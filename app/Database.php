<?php
declare(strict_types=1);

namespace Vblite\Convert;

use PDO;

/** Connessione SQLite e schema. Il file del database sta fuori dalla web root. */
final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $percorso = Config::percorsoDb();
        $nuovo    = !is_file($percorso);

        if (!is_dir(dirname($percorso))) {
            mkdir(dirname($percorso), 0770, true);
        }

        $pdo = new PDO('sqlite:' . $percorso, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        self::$pdo = $pdo;
        self::schema($pdo);

        if ($nuovo) {
            @chmod($percorso, 0660);
        }

        return $pdo;
    }

    private static function schema(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS users (
              id            INTEGER PRIMARY KEY,
              email         TEXT UNIQUE NOT NULL,
              password_hash TEXT NOT NULL,
              nome          TEXT NOT NULL,
              ruolo         TEXT NOT NULL DEFAULT 'admin',
              deve_cambiare INTEGER NOT NULL DEFAULT 1,
              ultimo_accesso TEXT,
              creato_il     TEXT NOT NULL DEFAULT (datetime('now'))
            );

            CREATE TABLE IF NOT EXISTS jobs (
              id            INTEGER PRIMARY KEY,
              riferimento   TEXT UNIQUE NOT NULL,
              user_id       INTEGER NOT NULL REFERENCES users(id),
              tipologia     TEXT NOT NULL,
              file_in       TEXT NOT NULL,
              nome_originale TEXT NOT NULL,
              file_out      TEXT,
              nome_uscita   TEXT,
              regole_json   TEXT NOT NULL,
              pagine        INTEGER,
              righe_lette   INTEGER,
              righe_scritte INTEGER,
              pagina_corrente INTEGER NOT NULL DEFAULT 0,
              passo         TEXT NOT NULL DEFAULT 'in_coda',
              esito         TEXT NOT NULL,
              errore        TEXT,
              versione      INTEGER NOT NULL DEFAULT 1,
              byte_out      INTEGER,
              creato_il     TEXT NOT NULL DEFAULT (datetime('now')),
              concluso_il   TEXT
            );

            CREATE TABLE IF NOT EXISTS anomalie (
              id              INTEGER PRIMARY KEY,
              job_id          INTEGER NOT NULL REFERENCES jobs(id) ON DELETE CASCADE,
              chiave          TEXT,
              cliente         TEXT,
              motivo          TEXT NOT NULL,
              colonna         TEXT NOT NULL,
              gravita         TEXT NOT NULL DEFAULT 'correggi',
              valore_proposto TEXT,
              valore_corretto TEXT,
              risolta         INTEGER NOT NULL DEFAULT 0
            );

            CREATE TABLE IF NOT EXISTS preset (
              id         INTEGER PRIMARY KEY,
              user_id    INTEGER NOT NULL REFERENCES users(id),
              tipologia  TEXT NOT NULL,
              nome       TEXT NOT NULL,
              regole_json TEXT NOT NULL,
              creato_il  TEXT NOT NULL DEFAULT (datetime('now'))
            );

            CREATE TABLE IF NOT EXISTS tentativi_accesso (
              id       INTEGER PRIMARY KEY,
              chiave   TEXT NOT NULL,
              quando   TEXT NOT NULL DEFAULT (datetime('now'))
            );

            CREATE INDEX IF NOT EXISTS idx_jobs_utente   ON jobs(user_id, creato_il DESC);
            CREATE INDEX IF NOT EXISTS idx_anomalie_job  ON anomalie(job_id);
            CREATE INDEX IF NOT EXISTS idx_tentativi     ON tentativi_accesso(chiave, quando);
            SQL);
    }
}
