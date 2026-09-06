<?php
declare(strict_types=1);

namespace Vblite\Convert;

use Vblite\Convert\Conversioni\Registro;

/**
 * Ciclo di vita di una conversione.
 *
 * Il lavoro gira in un processo distaccato: l'utente puo' lasciare la pagina,
 * il job prosegue e ricompare nello storico. L'avanzamento passa dal database.
 */
final class Job
{
    public const IN_CORSO    = 'in_corso';
    public const COMPLETATA  = 'completata';
    public const DA_RIVEDERE = 'da_rivedere';
    public const ERRORE      = 'errore';

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
        $php     = PHP_BINARY;
        $script  = Config::radice() . '/bin/esegui-job.php';
        $comando = escapeshellcmd($php) . ' ' . escapeshellarg($script) . ' ' . (int) $jobId
            . ' > /dev/null 2>&1 &';

        // Su hosting condivisi exec() puo' essere disabilitata: in quel caso la
        // conversione gira in linea, nella stessa richiesta.
        if (function_exists('exec') && !in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
            @exec($comando);

            return;
        }

        self::esegui($jobId);
    }

    /** Esegue la conversione. Chiamata dal processo di sfondo o, in ripiego, in linea. */
    public static function esegui(int $jobId): void
    {
        $pdo = Database::pdo();
        $job = self::trova($jobId);
        if ($job === null) {
            return;
        }

        $conversione = Registro::trova($job['tipologia']);
        if ($conversione === null) {
            self::fallisci($jobId, 'Tipologia sconosciuta: ' . $job['tipologia']);

            return;
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

        $nomeUscita = $base . '.' . $estensione;
        $fileOut    = Config::cartellaUscita() . '/' . $job['riferimento'] . '-v' . $job['versione'] . '.' . $estensione;

        if (!is_dir(dirname($fileOut))) {
            mkdir(dirname($fileOut), 0770, true);
        }

        // Solo la fase di lettura conosce il numero di pagine: raggruppamento e
        // scrittura riportano un avanzamento su altra scala e non devono
        // sovrascrivere il totale gia' noto.
        $aggiornaLettura = $pdo->prepare('UPDATE jobs SET passo = ?, pagina_corrente = ?, pagine = ? WHERE id = ?');
        $aggiornaPasso   = $pdo->prepare('UPDATE jobs SET passo = ? WHERE id = ?');

        try {
            $risultato = $conversione->converti(
                $job['file_in'],
                $fileOut,
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

        // Una conversione può consegnare in un formato diverso da quello chiesto
        // — per esempio uno zip, quando il documento porta immagini a fianco.
        if (isset($risultato['estensione']) && $risultato['estensione'] !== $estensione) {
            $estensione = (string) $risultato['estensione'];
            $nuovo      = Config::cartellaUscita() . '/' . $job['riferimento'] . '-v' . $job['versione'] . '.' . $estensione;
            if (is_file($fileOut) && $fileOut !== $nuovo) {
                rename($fileOut, $nuovo);
            }
            $fileOut    = $nuovo;
            $nomeUscita = $base . '.' . $estensione;
        }

        // Le anomalie sono derivate: si rifanno a ogni giro. Le correzioni no.
        $pdo->prepare('DELETE FROM anomalie WHERE job_id = ?')->execute([$jobId]);
        $inserisci = $pdo->prepare(
            'INSERT INTO anomalie (job_id, chiave, cliente, motivo, colonna, gravita, valore_proposto)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $pdo->beginTransaction();
        foreach ($risultato['anomalie'] as $anomalia) {
            $inserisci->execute([
                $jobId,
                $anomalia['chiave'],
                $anomalia['cliente'],
                $anomalia['motivo'],
                $anomalia['colonna'],
                $anomalia['gravita'] ?? 'correggi',
                $anomalia['valore_proposto'],
            ]);
        }
        $pdo->commit();

        $daCorreggere = 0;
        foreach ($risultato['anomalie'] as $anomalia) {
            if (($anomalia['gravita'] ?? 'correggi') === 'correggi') {
                $daCorreggere++;
            }
        }

        $pdo->prepare(
            "UPDATE jobs SET file_out = ?, nome_uscita = ?, pagine = ?, righe_lette = ?, righe_scritte = ?,
                             byte_out = ?, anteprima_json = ?, passo = 'fatto', esito = ?,
                             concluso_il = datetime('now') WHERE id = ?"
        )->execute([
            $fileOut,
            $nomeUscita,
            $risultato['pagine'],
            $risultato['righe_lette'],
            $risultato['righe_scritte'],
            is_file($fileOut) ? filesize($fileOut) : null,
            json_encode($risultato['anteprima'], JSON_UNESCAPED_UNICODE),
            $daCorreggere > 0 ? self::DA_RIVEDERE : self::COMPLETATA,
            $jobId,
        ]);
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
    public static function applicaCorrezioni(int $jobId): void
    {
        $pdo     = Database::pdo();
        $vecchio = self::trova($jobId);
        if ($vecchio === null) {
            return;
        }

        $pdo->prepare('UPDATE jobs SET versione = versione + 1 WHERE id = ?')->execute([$jobId]);
        self::esegui($jobId);

        // Il giro precedente ha lasciato un file con un altro numero di versione.
        $nuovo = self::trova($jobId);
        if ($nuovo !== null && $vecchio['file_out'] !== null
            && $vecchio['file_out'] !== $nuovo['file_out']
            && is_file($vecchio['file_out'])) {
            @unlink($vecchio['file_out']);
        }

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
    public static function anomalie(int $jobId, ?string $gravita = null): array
    {
        $sql = 'SELECT * FROM anomalie WHERE job_id = ?';
        $par = [$jobId];
        if ($gravita !== null) {
            $sql .= ' AND gravita = ?';
            $par[] = $gravita;
        }
        $stmt = Database::pdo()->prepare($sql . ' ORDER BY gravita, id');
        $stmt->execute($par);

        return $stmt->fetchAll();
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
