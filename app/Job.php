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

        $regole     = json_decode((string) $job['regole_json'], true) ?: [];
        $estensione = ($regole['formato'] ?? 'xlsx') === 'csv' ? 'csv' : 'xlsx';
        $nomeUscita = 'File Import Prenotazioni.' . $estensione;
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
                             byte_out = ?, passo = 'fatto', esito = ?, concluso_il = datetime('now') WHERE id = ?"
        )->execute([
            $fileOut,
            $nomeUscita,
            $risultato['pagine'],
            $risultato['righe_lette'],
            $risultato['righe_scritte'],
            is_file($fileOut) ? filesize($fileOut) : null,
            $daCorreggere > 0 ? self::DA_RIVEDERE : self::COMPLETATA,
            $jobId,
        ]);
    }

    /**
     * Le correzioni dell'utente si riscrivono sopra il file appena generato:
     * il motore resta deterministico, l'intervento umano e' un secondo passaggio
     * tracciato nella tabella anomalie.
     */
    public static function applicaCorrezioni(int $jobId): void
    {
        $pdo  = Database::pdo();
        $job  = self::trova($jobId);
        if ($job === null || $job['file_out'] === null || !is_file($job['file_out'])) {
            return;
        }

        $stmt = $pdo->prepare("SELECT * FROM anomalie WHERE job_id = ? AND valore_corretto IS NOT NULL AND valore_corretto <> ''");
        $stmt->execute([$jobId]);
        $correzioni = $stmt->fetchAll();
        if ($correzioni === []) {
            return;
        }

        Correzioni::applica($job, $correzioni);
        $pdo->prepare('UPDATE anomalie SET risolta = 1 WHERE job_id = ? AND valore_corretto IS NOT NULL')->execute([$jobId]);
        $pdo->prepare('UPDATE jobs SET byte_out = ? WHERE id = ?')->execute([filesize($job['file_out']), $jobId]);
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

    private static function fallisci(int $jobId, string $errore): void
    {
        Database::pdo()
            ->prepare("UPDATE jobs SET esito = ?, errore = ?, passo = 'errore', concluso_il = datetime('now') WHERE id = ?")
            ->execute([self::ERRORE, $errore, $jobId]);
    }
}
