<?php
declare(strict_types=1);

namespace Vblite\Convert;

/** Accesso con utenti nominali. Niente registrazione pubblica: gli utenti si creano in 2i. */
final class Auth
{
    public static function avvia(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_set_cookie_params([
            'lifetime' => 0,
            // dirname() di «/index.php» torna «/»: il rtrim lo svuoterebbe, e un
            // cookie con percorso vuoto non viene mai rimandato indietro.
            'path'     => self::percorsoBase() . '/',
            'httponly' => true,
            'secure'   => (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off'),
            'samesite' => 'Lax',
        ]);
        session_name('vbliteconvert');
        session_start();
    }

    /** Cartella in cui vive l'applicazione, senza barra finale («» se e' la radice). */
    public static function percorsoBase(): string
    {
        return rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/');
    }

    /** @return array<string,mixed>|null */
    public static function utente(): ?array
    {
        self::avvia();
        $id = $_SESSION['user_id'] ?? null;
        if ($id === null) {
            return null;
        }

        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $utente = $stmt->fetch();

        return $utente !== false ? $utente : null;
    }

    /** @return array<string,mixed> */
    public static function richiedi(): array
    {
        $utente = self::utente();
        if ($utente === null) {
            header('Location: ?p=accesso');
            exit;
        }

        return $utente;
    }

    /** @return array{ok:bool,errore:?string} */
    public static function entra(string $email, string $password): array
    {
        $pdo    = Database::pdo();
        $chiave = strtolower(trim($email)) . '|' . ($_SERVER['REMOTE_ADDR'] ?? '?');

        if (self::troppiTentativi($chiave)) {
            return ['ok' => false, 'errore' => 'Troppi tentativi. Riprova fra un quarto d\'ora.'];
        }

        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([strtolower(trim($email))]);
        $utente = $stmt->fetch();

        // Si verifica comunque un hash fittizio quando l'utente non esiste, cosi'
        // il tempo di risposta non rivela quali email sono registrate.
        $hash = $utente !== false
            ? $utente['password_hash']
            : '$2y$12$usernonesistenteusernonesiste.aaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

        if (!password_verify($password, $hash) || $utente === false) {
            self::registraTentativo($chiave);

            return ['ok' => false, 'errore' => 'Email o password non corrette.'];
        }

        self::azzeraTentativi($chiave);
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $utente['id'];
        $pdo->prepare("UPDATE users SET ultimo_accesso = datetime('now') WHERE id = ?")->execute([$utente['id']]);

        return ['ok' => true, 'errore' => null];
    }

    public static function esci(): void
    {
        self::avvia();
        $_SESSION = [];
        session_destroy();
    }

    public static function creaUtente(string $email, string $nome, string $password, string $ruolo = 'admin'): void
    {
        Database::pdo()
            ->prepare('INSERT INTO users (email, nome, password_hash, ruolo) VALUES (?, ?, ?, ?)')
            ->execute([strtolower(trim($email)), trim($nome), self::hash($password), $ruolo]);
    }

    public static function cambiaPassword(int $userId, string $password): void
    {
        Database::pdo()
            ->prepare('UPDATE users SET password_hash = ?, deve_cambiare = 0 WHERE id = ?')
            ->execute([self::hash($password), $userId]);
    }

    public static function reimpostaPassword(int $userId, string $password): void
    {
        Database::pdo()
            ->prepare('UPDATE users SET password_hash = ?, deve_cambiare = 1 WHERE id = ?')
            ->execute([self::hash($password), $userId]);
    }

    /** Costo di bcrypt: ~0,25 s per hash, che a un login e' impercettibile. */
    private const COSTO_BCRYPT = 12;

    /**
     * Bcrypt, di proposito.
     *
     * Argon2id sarebbe migliore sulla carta, e su questo PHP c'e' pure. Ma con
     * i parametri di serie chiede 64 MB di memoria NATIVA — fuori dal
     * memory_limit — e sul pool FPM di questo hosting condiviso il processo
     * viene ucciso: nessuna eccezione, nessun log, solo un 500 di Apache.
     * Trovato in produzione, e non e' intercettabile a runtime: un processo
     * ucciso non esegue nessun blocco catch. Quindi non si tenta nemmeno.
     *
     * Bcrypt a costo 12 e' robusto, sta in memoria costante ed e' ovunque.
     * Se un domani si vuole tornare ad Argon2, va imposto un memory_cost basso
     * (19 MB circa) e provato sul server prima di darlo per buono.
     */
    public static function algoritmo(): string
    {
        return PASSWORD_BCRYPT;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => self::COSTO_BCRYPT]);
    }

    /** Gettone anti-CSRF, uno per sessione. */
    public static function gettone(): string
    {
        self::avvia();
        $_SESSION['csrf'] ??= bin2hex(random_bytes(16));

        return $_SESSION['csrf'];
    }

    public static function verificaGettone(?string $gettone): bool
    {
        self::avvia();

        return is_string($gettone) && isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $gettone);
    }

    private static function troppiTentativi(string $chiave): bool
    {
        $stmt = Database::pdo()->prepare(
            "SELECT COUNT(*) FROM tentativi_accesso WHERE chiave = ? AND quando > datetime('now', ?)"
        );
        $stmt->execute([$chiave, '-' . Config::TENTATIVI_FINESTRA . ' seconds']);

        return (int) $stmt->fetchColumn() >= Config::TENTATIVI_MAX;
    }

    private static function registraTentativo(string $chiave): void
    {
        Database::pdo()->prepare('INSERT INTO tentativi_accesso (chiave) VALUES (?)')->execute([$chiave]);
    }

    private static function azzeraTentativi(string $chiave): void
    {
        Database::pdo()->prepare('DELETE FROM tentativi_accesso WHERE chiave = ?')->execute([$chiave]);
    }
}
