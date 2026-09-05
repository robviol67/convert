<?php
declare(strict_types=1);

namespace Vblite\Convert;

/**
 * Primo avvio dal browser.
 *
 * Sull'hosting non c'e' SSH: `php bin/installa.php` non e' lanciabile, quindi
 * i due utenti iniziali si creano da una pagina. La pagina esiste solo finche'
 * la tabella users e' vuota: appena c'e' un utente, sparisce da sola.
 */
final class Installazione
{
    /** Gli account interni previsti, gia' pronti nel modulo. */
    public const UTENTI_PREVISTI = [
        ['email' => 'robviol@insertsrl.com', 'nome' => 'Roberto Violi'],
        ['email' => 'claudio@insertsrl.com', 'nome' => 'Claudio'],
    ];

    public const LUNGHEZZA_MINIMA = 10;

    public static function serve(): bool
    {
        try {
            return (int) Database::pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0;
        } catch (\Throwable) {
            return true; // database non ancora creato: a maggior ragione serve
        }
    }

    /**
     * @param array<string,mixed> $post
     * @return list<string> gli errori; vuoto se e' andata
     */
    public static function esegui(array $post): array
    {
        if (!self::serve()) {
            return ['L\'installazione è già stata fatta.'];
        }

        $errori = [];
        $daCreare = [];

        foreach (self::UTENTI_PREVISTI as $i => $previsto) {
            $email    = trim((string) ($post['email'][$i] ?? $previsto['email']));
            $nome     = trim((string) ($post['nome'][$i] ?? $previsto['nome']));
            $password = (string) ($post['password'][$i] ?? '');

            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errori[] = "Email non valida per il secondo campo: «{$email}».";
                continue;
            }
            if ($nome === '') {
                $errori[] = "Manca il nome per {$email}.";
                continue;
            }
            if (strlen($password) < self::LUNGHEZZA_MINIMA) {
                $errori[] = "La password di {$email} deve avere almeno " . self::LUNGHEZZA_MINIMA . ' caratteri.';
                continue;
            }
            $daCreare[] = ['email' => $email, 'nome' => $nome, 'password' => $password];
        }

        if ($errori !== []) {
            return $errori;
        }

        foreach ($daCreare as $utente) {
            Auth::creaUtente($utente['email'], $utente['nome'], $utente['password']);
        }

        return [];
    }

    /**
     * Controlli sull'hosting, gli stessi di bin/diagnostica.php ma leggibili
     * da una pagina: senza SSH e' l'unico modo per sapere se il server regge.
     *
     * @return list<array{nome:string,valore:string,ok:bool,bloccante:bool}>
     */
    public static function controlli(): array
    {
        $controlli = [];
        $aggiungi = static function (string $nome, string $valore, bool $ok, bool $bloccante = true) use (&$controlli): void {
            $controlli[] = ['nome' => $nome, 'valore' => $valore, 'ok' => $ok, 'bloccante' => $bloccante];
        };

        $aggiungi('PHP ≥ 8.1', PHP_VERSION, version_compare(PHP_VERSION, '8.1', '>='));

        foreach (['pdo_sqlite', 'zip', 'dom', 'xml', 'mbstring', 'gd', 'fileinfo'] as $estensione) {
            $aggiungi("estensione {$estensione}", extension_loaded($estensione) ? 'presente' : 'assente', extension_loaded($estensione));
        }

        $mega = static function (string $chiave): float {
            $grezzo = (string) ini_get($chiave);
            $numero = (float) preg_replace('~[^\d.]~', '', $grezzo);
            $unita  = strtolower(substr(trim($grezzo), -1));

            return match ($unita) {
                'g' => $numero * 1024,
                'k' => $numero / 1024,
                default => $numero,
            };
        };

        $aggiungi('upload_max_filesize ≥ 50M', (string) ini_get('upload_max_filesize'), $mega('upload_max_filesize') >= 50);
        $aggiungi('post_max_size ≥ 50M', (string) ini_get('post_max_size'), $mega('post_max_size') >= 50);

        $tempo = (int) ini_get('max_execution_time');
        $aggiungi('max_execution_time ≥ 120', $tempo === 0 ? 'illimitato' : "{$tempo} s", $tempo === 0 || $tempo >= 120);

        $memoria = (int) ini_get('memory_limit');
        $aggiungi('memory_limit ≥ 256M', (string) ini_get('memory_limit'), $memoria === -1 || $mega('memory_limit') >= 256);

        // Non basta sapere quale algoritmo c'e': va provato davvero. Su questo
        // hosting Argon2 esiste ma uccide il processo — vedi Auth::algoritmo().
        $algoritmo = Auth::algoritmo();
        $nome = $algoritmo === PASSWORD_BCRYPT ? 'bcrypt · costo 12' : (string) $algoritmo;
        try {
            $prova = Auth::hash('prova-di-cifratura');
            $ok    = is_string($prova) && $prova !== '' && password_verify('prova-di-cifratura', $prova);
            $aggiungi('cifratura delle password', $ok ? $nome . ' · funziona' : $nome . ' · NON verifica', $ok);
        } catch (\Throwable $e) {
            $aggiungi('cifratura delle password', $nome . ' · ' . $e->getMessage(), false);
        }

        // E nemmeno basta che la cartella sia scrivibile: SQLite in WAL ha bisogno
        // di creare i file laterali, e su alcuni filesystem condivisi non ci riesce.
        // Si prova una scrittura vera, e poi si pulisce.
        try {
            $pdo = Database::pdo();
            $pdo->exec('CREATE TABLE IF NOT EXISTS prova_scrittura (id INTEGER PRIMARY KEY, quando TEXT)');
            $pdo->prepare('INSERT INTO prova_scrittura (quando) VALUES (?)')->execute([gmdate('c')]);
            $pdo->exec('DROP TABLE prova_scrittura');
            $aggiungi('scrittura sul database', 'ok', true);
        } catch (\Throwable $e) {
            $aggiungi('scrittura sul database', $e->getMessage(), false);
        }

        $disabilitate = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        $exec = !in_array('exec', $disabilitate, true) && function_exists('exec');
        $aggiungi(
            'exec() per i job in sfondo',
            $exec ? 'disponibile' : 'disabilitata — si converte in linea',
            $exec,
            false // non bloccante: c'e' il ripiego in linea
        );

        foreach ([
            'storage/in'  => Config::cartellaIngresso(),
            'storage/out' => Config::cartellaUscita(),
            'data'        => dirname(Config::percorsoDb()),
        ] as $etichetta => $cartella) {
            if (!is_dir($cartella)) {
                @mkdir($cartella, 0770, true);
            }
            $ok = is_dir($cartella) && is_writable($cartella);
            $aggiungi("cartella scrivibile {$etichetta}", $ok ? 'ok' : 'NON scrivibile', $ok);
        }

        return $controlli;
    }
}
