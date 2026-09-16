<?php
declare(strict_types=1);

namespace Vblite\Convert;

use Vblite\Convert\Conversioni\Conversione;
use Vblite\Convert\Conversioni\ConversioneAPassi;
use Vblite\Convert\Conversioni\Registro;

/**
 * Ciclo di vita di una conversione.
 *
 * Dove si possono lanciare processi, il lavoro gira in uno distaccato. Su
 * questo hosting no: exec() è disabilitata. Allora le conversioni che lo
 * sanno fare lavorano **a tappe** — ogni richiesta della pagina di avanzamento
 * fa un pezzo, e lo stato resta su disco fra una e l'altra — e le altre girano
 * dentro la richiesta che le lancia. L'avanzamento passa sempre dal database.
 */
final class Job
{
    public const IN_CORSO    = 'in_corso';
    public const COMPLETATA  = 'completata';
    public const DA_RIVEDERE = 'da_rivedere';
    public const ERRORE      = 'errore';

    /**
     * Quante volte di fila una tappa può cominciare senza finire.
     *
     * Una tappa uccisa dal server si rifà con un pezzo più piccolo; se muore
     * anche così, insistere non serve e si dice perché.
     */
    private const TENTATIVI_MASSIMI = 4;

    /** Una cartella di lavoro abbandonata da più di così si può togliere. */
    private const LAVORO_ABBANDONATO = 7 * 86400;

    /**
     * @param array<string,mixed> $regole
     */
    public static function crea(int $userId, string $tipologia, string $fileIn, string $nomeOriginale, array $regole): array
    {
        $pdo         = Database::pdo();
        $riferimento = substr(bin2hex(random_bytes(4)), 0, 6);

        $pdo->prepare(
            'INSERT INTO jobs (riferimento, user_id, tipologia, file_in, nome_originale, regole_json, esito, passo)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$riferimento, $userId, $tipologia, $fileIn, $nomeOriginale, json_encode($regole, JSON_UNESCAPED_UNICODE), self::IN_CORSO, 'in_coda']);

        return self::trova((int) $pdo->lastInsertId());
    }

    /** @return array<string,mixed>|null */
    public static function trova(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM jobs WHERE id = ?');
        $stmt->execute([$id]);
        $job = $stmt->fetch();

        return $job !== false ? $job : null;
    }

    /** @return array<string,mixed>|null */
    public static function perRiferimento(string $riferimento): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM jobs WHERE riferimento = ?');
        $stmt->execute([$riferimento]);
        $job = $stmt->fetch();

        return $job !== false ? $job : null;
    }

    /**
     * Avvia la conversione in un processo separato, cosi' la richiesta HTTP
     * ritorna subito e la pagina 2e puo' interrogare l'avanzamento.
     */
    public static function avviaInBackground(int $jobId): void
    {
        if (self::sfondoDisponibile()) {
            $comando = escapeshellcmd(PHP_BINARY) . ' '
                . escapeshellarg(Config::radice() . '/bin/esegui-job.php') . ' ' . (int) $jobId
                . ' > /dev/null 2>&1 &';
            @exec($comando);

            return;
        }

        // Senza processi in sfondo: a tappe, se la conversione lo sa fare. La
        // richiesta torna subito, e il lavoro lo porta avanti la pagina di
        // avanzamento. Altrimenti gira qui, nella richiesta.
        if (self::aTappe($jobId)) {
            self::preparaTappe($jobId);

            return;
        }

        self::esegui($jobId);
    }

    /**
     * Su hosting condivisi exec() è spesso disabilitata.
     *
     * CONVERT_SENZA_SFONDO=1 fa come se non ci fosse anche dove c'è: serve alle
     * prove, che devono far girare le tappe come sul server.
     */
    private static function sfondoDisponibile(): bool
    {
        if (getenv('CONVERT_SENZA_SFONDO') === '1') {
            return false;
        }

        return function_exists('exec')
            && !in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true);
    }

    /**
     * Se il lavoro va avanti solo finché la pagina di avanzamento è aperta.
     *
     * È il prezzo delle tappe senza processi in sfondo, e va detto a chi
     * guarda: chiudere la pagina ferma la conversione, riaprirla la riprende.
     */
    public static function avanzaConLaPagina(int $jobId): bool
    {
        return !self::sfondoDisponibile() && self::aTappe($jobId);
    }

    private static function aTappe(int $jobId): bool
    {
        $job = self::trova($jobId);

        return $job !== null && Registro::trova((string) $job['tipologia']) instanceof ConversioneAPassi;
    }

    /** Esegue la conversione in un colpo. Dal processo di sfondo o, in ripiego, in linea. */
    public static function esegui(int $jobId): void
    {
        $contesto = self::contesto($jobId);
        if ($contesto === null) {
            return;
        }
        ['job' => $job, 'conversione' => $conversione, 'regole' => $regole] = $contesto;

        // Solo la fase di lettura conosce il numero di pagine: raggruppamento e
        // scrittura riportano un avanzamento su altra scala e non devono
        // sovrascrivere il totale gia' noto.
        $pdo = Database::pdo();
        $aggiornaLettura = $pdo->prepare('UPDATE jobs SET passo = ?, pagina_corrente = ?, pagine = ? WHERE id = ?');
        $aggiornaPasso   = $pdo->prepare('UPDATE jobs SET passo = ? WHERE id = ?');

        try {
            $risultato = $conversione->converti(
                $job['file_in'],
                $contesto['file_out'],
                $regole,
                static function (string $passo, int $corrente, int $totale) use ($aggiornaLettura, $aggiornaPasso, $jobId): void {
                    if ($passo === 'lettura' && $totale > 1) {
                        $aggiornaLettura->execute([$passo, $corrente, $totale, $jobId]);

                        return;
                    }
                    $aggiornaPasso->execute([$passo, $jobId]);
                }
            );
        } catch (\Throwable $e) {
            self::fallisci($jobId, $e->getMessage());

            return;
        }

        self::concludi($jobId, $contesto, $risultato);
    }

    /**
     * Tutto quello che serve a far girare un job, ricavato una volta sola.
     *
     * @return array{job:array<string,mixed>,conversione:Conversione,regole:array<string,mixed>,
     *               file_out:string,nome_uscita:string,base:string}|null
     */
    private static function contesto(int $jobId): ?array
    {
        $job = self::trova($jobId);
        if ($job === null) {
            return null;
        }

        $conversione = Registro::trova($job['tipologia']);
        if ($conversione === null) {
            self::fallisci($jobId, 'Tipologia sconosciuta: ' . $job['tipologia']);

            return null;
        }

        $regole = json_decode((string) $job['regole_json'], true) ?: [];

        // Le correzioni fatte a mano rientrano nella conversione invece di
        // essere applicate al file gia' scritto: il risultato resta il prodotto
        // di un unico passaggio, non di ritocchi sovrapposti.
        $regole['correzioni'] = self::correzioni($jobId);

        // Il nome del file caricato serve alle tipologie che lo riusano per
        // battezzare quello che producono.
        $regole['nome_originale'] = (string) $job['nome_originale'];
        // Formato e nome del file prodotto li dichiara la tipologia: qui non
        // deve esserci niente che sappia di prenotazioni o di documenti.
        $manifest   = $conversione->manifest();
        $formati    = $manifest['formati_uscita'] ?? ['xlsx' => 'XLSX'];
        $formato    = (string) ($regole['formato'] ?? array_key_first($formati));
        $estensione = isset($formati[$formato]) ? $formato : (string) array_key_first($formati);

        $base = $manifest['nome_uscita']
            ?? pathinfo((string) $job['nome_originale'], PATHINFO_FILENAME);

        $fileOut = Config::cartellaUscita() . '/' . $job['riferimento'] . '-v' . $job['versione'] . '.' . $estensione;
        if (!is_dir(dirname($fileOut))) {
            mkdir(dirname($fileOut), 0770, true);
        }

        return [
            'job'         => $job,
            'conversione' => $conversione,
            'regole'      => $regole,
            'file_out'    => $fileOut,
            'nome_uscita' => $base . '.' . $estensione,
            'base'        => (string) $base,
        ];
    }

    /**
     * Registra il risultato: anomalie, conteggi, file prodotto.
     *
     * Le anomalie possono arrivare in memoria o in un file, una per riga: una
     * stampa di duemila pagine ne produce tredicimila, e tenerle tutte in un
     * vettore costerebbe più del tetto dell'hosting.
     *
     * @param array<string,mixed> $contesto
     * @param array<string,mixed> $risultato
     */
    private static function concludi(int $jobId, array $contesto, array $risultato): void
    {
        $pdo        = Database::pdo();
        $job        = $contesto['job'];
        $fileOut    = $contesto['file_out'];
        $nomeUscita = $contesto['nome_uscita'];

        // Una conversione può consegnare in un formato diverso da quello chiesto
        // — per esempio uno zip, quando il documento porta immagini a fianco.
        if (isset($risultato['estensione']) && !str_ends_with($fileOut, '.' . $risultato['estensione'])) {
            $estensione = (string) $risultato['estensione'];
            $nuovo      = Config::cartellaUscita() . '/' . $job['riferimento'] . '-v' . $job['versione'] . '.' . $estensione;
            if (is_file($fileOut) && $fileOut !== $nuovo) {
                rename($fileOut, $nuovo);
            }
            $fileOut    = $nuovo;
            $nomeUscita = $contesto['base'] . '.' . $estensione;
        }

        // Le anomalie sono derivate: si rifanno a ogni giro. Le correzioni no.
        $pdo->prepare('DELETE FROM anomalie WHERE job_id = ?')->execute([$jobId]);
        $inserisci = $pdo->prepare(
            'INSERT INTO anomalie (job_id, chiave, cliente, motivo, colonna, gravita, valore_proposto)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $daCorreggere = 0;
        $registra = static function (array $anomalia) use ($inserisci, $jobId, &$daCorreggere): void {
            $gravita = $anomalia['gravita'] ?? 'correggi';
            $inserisci->execute([
                $jobId,
                $anomalia['chiave'],
                $anomalia['cliente'],
                $anomalia['motivo'],
                $anomalia['colonna'],
                $gravita,
                $anomalia['valore_proposto'],
            ]);
            $daCorreggere += $gravita === 'correggi' ? 1 : 0;
        };

        $pdo->beginTransaction();
        foreach ($risultato['anomalie'] ?? [] as $anomalia) {
            $registra($anomalia);
        }
        if (isset($risultato['anomalie_file'])) {
            $f = @fopen((string) $risultato['anomalie_file'], 'rb');
            while ($f !== false && ($linea = fgets($f)) !== false) {
                $anomalia = json_decode($linea, true);
                if (is_array($anomalia)) {
                    $registra($anomalia);
                }
            }
            if ($f !== false) {
                fclose($f);
            }
        }
        $pdo->commit();

        $pdo->prepare(
            "UPDATE jobs SET file_out = ?, nome_uscita = ?, pagine = ?, righe_lette = ?, righe_scritte = ?,
                             byte_out = ?, anteprima_json = ?, passo = 'fatto', esito = ?, errore = NULL,
                             pagina_corrente = ?, concluso_il = datetime('now') WHERE id = ?"
        )->execute([
            $fileOut,
            $nomeUscita,
            $risultato['pagine'],
            $risultato['righe_lette'],
            $risultato['righe_scritte'],
            is_file($fileOut) ? filesize($fileOut) : null,
            json_encode($risultato['anteprima'], JSON_UNESCAPED_UNICODE),
            $daCorreggere > 0 ? self::DA_RIVEDERE : self::COMPLETATA,
            $risultato['pagine'],
            $jobId,
        ]);
    }

    // ── A tappe ─────────────────────────────────────────────────────────────

    /** Dove un job a tappe tiene il suo lavoro fra una richiesta e l'altra. */
    public static function cartellaLavoro(array $job): string
    {
        return Config::cartellaLavoro() . '/' . $job['riferimento'] . '-v' . $job['versione'];
    }

    /**
     * Prepara il lavoro a tappe e torna subito.
     *
     * @param string|null $filePrecedente il file della versione prima, da
     *        togliere quando la nuova è pronta (lo passa applicaCorrezioni)
     */
    public static function preparaTappe(int $jobId, ?string $filePrecedente = null): void
    {
        $contesto = self::contesto($jobId);
        if ($contesto === null || !$contesto['conversione'] instanceof ConversioneAPassi) {
            return;
        }
        $job = $contesto['job'];
        self::pulisciAbbandonati();

        $cartella = self::cartellaLavoro($job);
        self::togliCartella($cartella);
        if (!mkdir($cartella, 0770, true) && !is_dir($cartella)) {
            self::fallisci($jobId, 'Non riesco a creare la cartella di lavoro.');

            return;
        }

        try {
            $stato = $contesto['conversione']->prepara($job['file_in'], $cartella, $contesto['regole']);
        } catch (\Throwable $e) {
            self::fallisci($jobId, $e->getMessage());
            self::togliCartella($cartella);

            return;
        }
        $stato['_file_precedente'] = $filePrecedente;
        self::salvaStato($cartella, $stato);

        $punto = $contesto['conversione']->avanzamento($stato);
        Database::pdo()
            ->prepare("UPDATE jobs SET esito = ?, errore = NULL, passo = ?, pagina_corrente = 0, pagine = ? WHERE id = ?")
            ->execute([self::IN_CORSO, $punto['passo'], $punto['totale'], $jobId]);
    }

    /**
     * Fa una tappa del lavoro, se il job ne ha bisogno.
     *
     * La chiama la pagina di avanzamento a ogni giro. Un lucchetto sulla
     * cartella di lavoro fa sì che due richieste — due schede aperte, o una
     * richiesta ripetuta dal server — non facciano la stessa tappa insieme.
     * Il lucchetto lo toglie il sistema anche se il processo viene ucciso.
     */
    public static function avanza(int $jobId): void
    {
        $job = self::trova($jobId);
        if ($job === null || $job['esito'] !== self::IN_CORSO || !self::aTappe($jobId)) {
            return;
        }

        $cartella = self::cartellaLavoro($job);
        if (!is_file($cartella . '/stato.json')) {
            // Nessun lavoro in corso per questo job: la cartella è andata
            // persa, o il job viene da prima delle tappe. Si riparte da capo.
            self::preparaTappe($jobId);

            return;
        }

        $lucchetto = @fopen($cartella . '/lucchetto', 'c');
        if ($lucchetto === false) {
            return;
        }
        if (!flock($lucchetto, LOCK_EX | LOCK_NB)) {
            fclose($lucchetto);

            return;   // un'altra richiesta sta già facendo questa tappa
        }

        try {
            $contesto = self::contesto($jobId);
            if ($contesto === null) {
                return;
            }
            $conversione = $contesto['conversione'];
            assert($conversione instanceof ConversioneAPassi);
            $stato = self::leggiStato($cartella);

            if ((int) ($stato['tentativi'] ?? 0) >= self::TENTATIVI_MASSIMI) {
                self::fallisci(
                    $jobId,
                    'La conversione si è interrotta ' . self::TENTATIVI_MASSIMI . ' volte allo stesso punto, '
                    . 'anche leggendo poche pagine per volta: il server la ferma. Prova a esportare la stampa '
                    . 'per un periodo più corto.'
                );
                self::togliCartella($cartella);

                return;
            }

            // Il tentativo si conta prima di cominciare: se la richiesta muore a
            // metà, la prossima lo sa e fa un pezzo più piccolo.
            $stato['tentativi'] = (int) ($stato['tentativi'] ?? 0) + 1;
            self::salvaStato($cartella, $stato);

            $nuovo = $conversione->avanza($job['file_in'], $cartella, $stato, $contesto['regole'], $contesto['file_out']);
            $nuovo['tentativi'] = 0;

            if (!empty($nuovo['fatto'])) {
                self::concludi($jobId, $contesto, $nuovo['risultato']);
                self::togliFileVecchio($nuovo['_file_precedente'] ?? null, $contesto['file_out']);
                self::togliCartella($cartella);

                return;
            }

            self::salvaStato($cartella, $nuovo);
            $punto = $conversione->avanzamento($nuovo);
            $pagine = $punto['totale'] > 0 ? $punto['totale'] : (int) ($job['pagine'] ?? 0);
            // Finita la lettura, la barra resta piena mentre si raggruppa.
            $corrente = $punto['passo'] === 'lettura' ? $punto['corrente'] : $pagine;
            Database::pdo()
                ->prepare('UPDATE jobs SET passo = ?, pagina_corrente = ?, pagine = ? WHERE id = ?')
                ->execute([$punto['passo'], $corrente, $pagine, $jobId]);
        } catch (\Throwable $e) {
            self::fallisci($jobId, $e->getMessage());
            self::togliCartella($cartella);
        } finally {
            flock($lucchetto, LOCK_UN);
            fclose($lucchetto);
        }
    }

    /** Ferma un job in corso e butta il lavoro fatto. */
    public static function annulla(int $jobId): void
    {
        $job = self::trova($jobId);
        if ($job === null) {
            return;
        }
        Database::pdo()
            ->prepare("UPDATE jobs SET esito = ?, errore = 'Annullata dall''utente', passo = 'errore' WHERE id = ?")
            ->execute([self::ERRORE, $jobId]);
        self::togliCartella(self::cartellaLavoro($job));
    }

    /** @return array<string,mixed> */
    private static function leggiStato(string $cartella): array
    {
        $stato = json_decode((string) @file_get_contents($cartella . '/stato.json'), true);
        if (!is_array($stato)) {
            throw new \RuntimeException('Lo stato del lavoro non si legge più: la conversione va rifatta.');
        }

        return $stato;
    }

    /**
     * Scrive lo stato in modo che non resti mai a metà: prima un file a parte,
     * poi lo scambio, che sul disco è un'operazione sola.
     *
     * @param array<string,mixed> $stato
     */
    private static function salvaStato(string $cartella, array $stato): void
    {
        $provvisorio = $cartella . '/stato.json.' . getmypid();
        if (file_put_contents($provvisorio, json_encode($stato, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) === false) {
            throw new \RuntimeException('Non riesco a salvare lo stato del lavoro.');
        }
        rename($provvisorio, $cartella . '/stato.json');
    }

    /** Una cartella di lavoro contiene solo file: niente ricorsione. */
    private static function togliCartella(string $cartella): void
    {
        if (!is_dir($cartella) || !str_starts_with($cartella, Config::cartellaLavoro() . '/')) {
            return;
        }
        // scandir e non glob: GLOB_BRACE, che servirebbe per i file nascosti,
        // non c'è su tutti i sistemi.
        foreach (scandir($cartella) ?: [] as $nome) {
            if ($nome !== '.' && $nome !== '..') {
                @unlink($cartella . '/' . $nome);
            }
        }
        @rmdir($cartella);
    }

    /** Le cartelle di lavoro dei job che nessuno ha più riaperto. */
    private static function pulisciAbbandonati(): void
    {
        foreach (glob(Config::cartellaLavoro() . '/*', GLOB_ONLYDIR) ?: [] as $cartella) {
            if ((int) @filemtime($cartella . '/stato.json') < time() - self::LAVORO_ABBANDONATO) {
                self::togliCartella($cartella);
            }
        }
    }

    private static function togliFileVecchio(?string $vecchio, string $nuovo): void
    {
        if ($vecchio !== null && $vecchio !== '' && $vecchio !== $nuovo && is_file($vecchio)
            && str_starts_with((string) realpath($vecchio), (string) realpath(Config::cartellaUscita()) . '/')) {
            @unlink($vecchio);
        }
    }

    /**
     * Le correzioni gia' inserite, pronte per il motore.
     *
     * @return array<string,array<string,string>> per N°pren. e colonna Scidoo
     */
    private static function correzioni(int $jobId): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT chiave, colonna, valore FROM correzioni
             WHERE job_id = ? AND saltata = 0 AND valore IS NOT NULL AND valore <> ''"
        );
        $stmt->execute([$jobId]);

        $mappa = [];
        foreach ($stmt->fetchAll() as $riga) {
            $mappa[(string) $riga['chiave']][(string) $riga['colonna']] = (string) $riga['valore'];
        }

        return $mappa;
    }

    /**
     * Rigenera il file tenendo conto delle correzioni inserite in «Da rivedere».
     *
     * Non si ritoccano celle nel foglio gia' prodotto: la conversione viene
     * rifatta con le correzioni fra le regole. Costa un secondo e lascia un
     * risultato che e' sempre il prodotto di un unico passaggio deterministico,
     * invece di una serie di ritocchi sovrapposti di cui nessuno tiene il conto.
     */
    public static function applicaCorrezioni(int $jobId): bool
    {
        $pdo     = Database::pdo();
        $vecchio = self::trova($jobId);
        if ($vecchio === null) {
            return false;
        }

        $pdo->prepare('UPDATE jobs SET versione = versione + 1 WHERE id = ?')->execute([$jobId]);

        // Su questo hosting una stampa lunga non si riconverte dentro una
        // richiesta: si riparte a tappe, e il file vecchio si toglie quando il
        // nuovo è pronto. Chi chiama deve mandare alla pagina di avanzamento.
        if (!self::sfondoDisponibile() && self::aTappe($jobId)) {
            self::preparaTappe($jobId, $vecchio['file_out']);

            return true;
        }

        self::esegui($jobId);

        // Il giro precedente ha lasciato un file con un altro numero di versione.
        $nuovo = self::trova($jobId);
        if ($nuovo !== null && $vecchio['file_out'] !== null
            && $vecchio['file_out'] !== $nuovo['file_out']
            && is_file($vecchio['file_out'])) {
            @unlink($vecchio['file_out']);
        }

        return false;
    }

    /** Registra quello che una persona ha deciso su una segnalazione. */
    public static function salvaCorrezione(int $jobId, string $chiave, string $colonna, ?string $valore, bool $saltata = false): void
    {
        $pdo = Database::pdo();

        if (($valore === null || trim($valore) === '') && !$saltata) {
            $pdo->prepare('DELETE FROM correzioni WHERE job_id = ? AND chiave = ? AND colonna = ?')
                ->execute([$jobId, $chiave, $colonna]);

            return;
        }

        $pdo->prepare(
            'INSERT INTO correzioni (job_id, chiave, colonna, valore, saltata) VALUES (?, ?, ?, ?, ?)
             ON CONFLICT (job_id, chiave, colonna)
             DO UPDATE SET valore = excluded.valore, saltata = excluded.saltata'
        )->execute([$jobId, $chiave, $colonna, $valore !== null ? trim($valore) : null, $saltata ? 1 : 0]);
    }

    /**
     * Le decisioni gia' prese, per riempire i campi in «Da rivedere».
     *
     * @return array<string,array{valore:?string,saltata:bool}> per «chiave|colonna»
     */
    public static function decisioni(int $jobId): array
    {
        $stmt = Database::pdo()->prepare('SELECT chiave, colonna, valore, saltata FROM correzioni WHERE job_id = ?');
        $stmt->execute([$jobId]);

        $mappa = [];
        foreach ($stmt->fetchAll() as $riga) {
            $mappa[$riga['chiave'] . '|' . $riga['colonna']] = [
                'valore'  => $riga['valore'],
                'saltata' => (int) $riga['saltata'] === 1,
            ];
        }

        return $mappa;
    }

    /** @return list<array<string,mixed>> */
    public static function anomalie(int $jobId, ?string $gravita = null, ?int $quante = null, int $daQui = 0): array
    {
        $sql = 'SELECT * FROM anomalie WHERE job_id = ?';
        $par = [$jobId];
        if ($gravita !== null) {
            $sql .= ' AND gravita = ?';
            $par[] = $gravita;
        }
        $sql .= ' ORDER BY gravita, id';
        // Una stampa lunga ne produce tredicimila: le pagine le chiedono a pezzi.
        if ($quante !== null) {
            $sql .= ' LIMIT ' . max(0, $quante) . ' OFFSET ' . max(0, $daQui);
        }
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($par);

        return $stmt->fetchAll();
    }

    /** Quante anomalie ha un job, senza portarle in memoria. */
    public static function contaAnomalie(int $jobId, string $gravita): int
    {
        $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM anomalie WHERE job_id = ? AND gravita = ?');
        $stmt->execute([$jobId, $gravita]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Le anomalie di una gravità contate per colonna.
     *
     * @return array<string,int>
     */
    public static function anomaliePerColonna(int $jobId, string $gravita): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT colonna, COUNT(*) AS n FROM anomalie WHERE job_id = ? AND gravita = ?
             GROUP BY colonna ORDER BY MIN(id)'
        );
        $stmt->execute([$jobId, $gravita]);

        $mappa = [];
        foreach ($stmt->fetchAll() as $riga) {
            $mappa[(string) $riga['colonna']] = (int) $riga['n'];
        }

        return $mappa;
    }

    /** @return list<array<string,mixed>> */
    public static function storico(?int $userId = null, string $filtro = 'tutte', int $limite = 100): array
    {
        $sql = 'SELECT j.*, u.nome AS utente_nome, u.email AS utente_email,
                       (SELECT COUNT(*) FROM anomalie a WHERE a.job_id = j.id AND a.gravita = \'correggi\') AS da_rivedere
                FROM jobs j JOIN users u ON u.id = j.user_id';
        $dove = [];
        $par  = [];

        if ($filtro === 'mie' && $userId !== null) {
            $dove[] = 'j.user_id = ?';
            $par[]  = $userId;
        }
        if ($filtro === 'da_rivedere') {
            $dove[] = "j.esito = 'da_rivedere'";
        }
        if ($dove !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $dove);
        }
        $sql .= ' ORDER BY j.creato_il DESC, j.id DESC LIMIT ' . (int) $limite;

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($par);

        return $stmt->fetchAll();
    }

    /**
     * Cancella una conversione: la riga e i suoi file.
     *
     * Il file d'ingresso può essere condiviso. «Rifai» non ricarica niente:
     * crea una conversione nuova che punta allo stesso PDF. Cancellare la
     * riconversione portandosi via il file toglierebbe il «Rifai» anche a
     * quella originale, quindi il file d'ingresso si tocca solo quando non lo
     * usa più nessuno. Il file d'uscita invece è di questa conversione sola.
     *
     * Si cancella soltanto dentro le cartelle dell'archivio: un percorso
     * manomesso nel database non deve poter portare via nient'altro.
     *
     * @return array{file:int,byte:int}
     */
    public static function elimina(int $id): array
    {
        $job = self::trova($id);
        if ($job === null) {
            return ['file' => 0, 'byte' => 0];
        }

        $pdo = Database::pdo();
        // Le figlie le porterebbe via la cascata delle chiavi esterne, ma la
        // cascata dipende da un pragma acceso a ogni connessione: due righe
        // esplicite costano niente e non lasciano orfani se il pragma manca.
        $pdo->beginTransaction();
        foreach (['anomalie', 'correzioni'] as $tabella) {
            $pdo->prepare("DELETE FROM {$tabella} WHERE job_id = ?")->execute([$id]);
        }
        $pdo->prepare('DELETE FROM jobs WHERE id = ?')->execute([$id]);
        $pdo->commit();

        $tolti = ['file' => 0, 'byte' => 0];
        $togli = static function (?string $percorso, string $cartella) use (&$tolti): void {
            if ($percorso === null || $percorso === '' || !is_file($percorso)) {
                return;
            }
            $vero   = realpath($percorso);
            $dentro = realpath($cartella);
            if ($vero === false || $dentro === false
                || !str_starts_with($vero, rtrim($dentro, '/') . '/')) {
                return;
            }
            $byte = (int) @filesize($vero);
            if (@unlink($vero)) {
                $tolti['file']++;
                $tolti['byte'] += $byte;
            }
        };

        $togli($job['file_out'], Config::cartellaUscita());

        // Il lavoro a tappe lasciato a metà: tutte le versioni del job.
        foreach (glob(Config::cartellaLavoro() . '/' . $job['riferimento'] . '-v*', GLOB_ONLYDIR) ?: [] as $cartella) {
            self::togliCartella($cartella);
        }

        $altre = $pdo->prepare('SELECT COUNT(*) FROM jobs WHERE file_in = ?');
        $altre->execute([$job['file_in']]);
        if ((int) $altre->fetchColumn() === 0) {
            $togli($job['file_in'], Config::cartellaIngresso());
        }

        return $tolti;
    }

    /**
     * Svuota lo storico. Con $userId, solo le conversioni di quella persona.
     *
     * Passa da elimina() una per una invece di un DELETE solo: è il modo di
     * non sbagliare sui file condivisi, e qui la lentezza non conta — sono
     * centinaia di righe, non milioni.
     *
     * @return array{conversioni:int,file:int,byte:int}
     */
    public static function svuota(?int $userId = null): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id FROM jobs' . ($userId !== null ? ' WHERE user_id = ?' : '')
        );
        $stmt->execute($userId !== null ? [$userId] : []);
        $ids = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $totale = ['conversioni' => 0, 'file' => 0, 'byte' => 0];
        foreach ($ids as $id) {
            $tolti = self::elimina((int) $id);
            $totale['conversioni']++;
            $totale['file'] += $tolti['file'];
            $totale['byte'] += $tolti['byte'];
        }

        return $totale;
    }

    private static function fallisci(int $jobId, string $errore): void
    {
        Database::pdo()
            ->prepare("UPDATE jobs SET esito = ?, errore = ?, passo = 'errore', concluso_il = datetime('now') WHERE id = ?")
            ->execute([self::ERRORE, $errore, $jobId]);
    }
}
