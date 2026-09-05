<?php
declare(strict_types=1);

namespace Vblite\Convert;

/**
 * Registro degli errori.
 *
 * Sull'hosting non c'e' SSH e i log di Plesk non sono raggiungibili via FTP:
 * un errore fatale diventa una pagina bianca 500 e basta. Qui si scrive cosa
 * e' successo in un file protetto, si mostra all'utente un codice da riferire,
 * e le ultime voci si rileggono da ?p=diagnostica.
 */
final class Errori
{
    private const QUANTI_TENERE = 40;

    public static function registra(): void
    {
        set_exception_handler([self::class, 'eccezione']);
        set_error_handler([self::class, 'errore']);
        register_shutdown_function([self::class, 'spegnimento']);
    }

    public static function eccezione(\Throwable $e): void
    {
        $codice = self::scrivi(
            get_class($e) . ': ' . $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        );
        self::mostra($codice);
    }

    /** Gli avvisi non fermano la pagina: si annotano e si tira dritto. */
    public static function errore(int $tipo, string $messaggio, string $file = '', int $riga = 0): bool
    {
        if ((error_reporting() & $tipo) === 0) {
            return false;
        }
        self::scrivi(self::nomeTipo($tipo) . ': ' . $messaggio, $file, $riga, null);

        return false; // lascia lavorare anche il gestore di serie
    }

    /** L'unico modo di intercettare gli errori fatali, che saltano i gestori. */
    public static function spegnimento(): void
    {
        $ultimo = error_get_last();
        if ($ultimo === null || !in_array($ultimo['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }
        $codice = self::scrivi(
            self::nomeTipo($ultimo['type']) . ': ' . $ultimo['message'],
            $ultimo['file'],
            $ultimo['line'],
            null
        );
        if (!headers_sent()) {
            self::mostra($codice);
        }
    }

    /**
     * Briciola di percorso.
     *
     * Quando il processo viene ucciso — memoria nativa esaurita, stack finito,
     * un'estensione che segfaulta — non scatta nessun gestore e non resta niente
     * nei log: il 500 e' muto. L'unica cosa che sopravvive e' quello che era
     * gia' su disco. Questa scrive e forza il flush a ogni passo, cosi' l'ultima
     * riga presente dice dove si e' fermato.
     *
     * @param array<string,scalar> $dati
     */
    public static function passo(string $etichetta, array $dati = []): void
    {
        static $inizio = null;
        $inizio ??= microtime(true);

        $riga = sprintf(
            '%s  %+7.2fs  %5.1fMB  %s',
            gmdate('H:i:s'),
            microtime(true) - $inizio,
            memory_get_peak_usage(true) / 1048576,
            $etichetta
        );
        foreach ($dati as $chiave => $valore) {
            $riga .= " {$chiave}={$valore}";
        }

        $percorso = dirname(Config::percorsoDb()) . '/passi.log';
        if (!is_dir(dirname($percorso))) {
            @mkdir(dirname($percorso), 0770, true);
        }
        // Ogni riga va su disco subito: se il processo muore, deve esserci gia'.
        $f = @fopen($percorso, 'a');
        if ($f !== false) {
            @fwrite($f, $riga . "\n");
            @fflush($f);
            @fclose($f);
        }
    }

    /** Riparte da zero: le briciole del giro precedente confonderebbero. */
    public static function azzeraPassi(): void
    {
        @unlink(dirname(Config::percorsoDb()) . '/passi.log');
    }

    public static function passi(): string
    {
        $percorso = dirname(Config::percorsoDb()) . '/passi.log';

        return is_file($percorso) ? (string) file_get_contents($percorso) : '';
    }

    /** @return list<array<string,string>> le voci piu' recenti, per la diagnostica */
    public static function ultimi(int $quanti = 10): array
    {
        $percorso = self::percorso();
        if (!is_file($percorso)) {
            return [];
        }

        $voci = [];
        foreach (array_filter(explode("\n", (string) file_get_contents($percorso))) as $riga) {
            $voce = json_decode($riga, true);
            if (is_array($voce)) {
                $voci[] = $voce;
            }
        }

        return array_slice(array_reverse($voci), 0, $quanti);
    }

    public static function svuota(): void
    {
        @unlink(self::percorso());
    }

    private static function percorso(): string
    {
        return dirname(Config::percorsoDb()) . '/errori.log';
    }

    /** @return string il codice da riferire */
    private static function scrivi(string $messaggio, string $file, int $riga, ?string $traccia): string
    {
        $codice = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));

        $voce = [
            'codice'   => $codice,
            'quando'   => gmdate('Y-m-d H:i:s'),
            'messaggio' => $messaggio,
            'dove'     => basename($file) . ':' . $riga,
            'pagina'   => (string) ($_GET['p'] ?? '-'),
            'php'      => PHP_VERSION,
        ];
        if ($traccia !== null) {
            // Le prime righe bastano a capire da dove viene; il resto e' rumore.
            $voce['traccia'] = implode("\n", array_slice(explode("\n", $traccia), 0, 6));
        }

        $percorso = self::percorso();
        if (!is_dir(dirname($percorso))) {
            @mkdir(dirname($percorso), 0770, true);
        }
        @file_put_contents($percorso, json_encode($voce, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);

        self::sfoltisci($percorso);

        return $codice;
    }

    /** Il registro non deve crescere all'infinito su un hosting a spazio contato. */
    private static function sfoltisci(string $percorso): void
    {
        $righe = array_filter(explode("\n", (string) @file_get_contents($percorso)));
        if (count($righe) <= self::QUANTI_TENERE) {
            return;
        }
        @file_put_contents($percorso, implode("\n", array_slice($righe, -self::QUANTI_TENERE)) . "\n", LOCK_EX);
    }

    private static function mostra(string $codice): void
    {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
        }
        $base = htmlspecialchars(Auth::percorsoBase(), ENT_QUOTES, 'UTF-8');
        echo <<<HTML
            <!doctype html><html lang="it"><head><meta charset="utf-8">
            <title>Errore · vblite /convert</title>
            <link rel="stylesheet" href="{$base}/public/css/broadsheet.css">
            <link rel="stylesheet" href="{$base}/public/css/convert.css">
            </head><body><div class="sh"><div class="body" style="padding-top:40px">
            <p class="kick" style="color:var(--color-accent-2-700);margin:0 0 8px">Errore {$codice}</p>
            <h1 class="h1" style="font-size:44px">Qualcosa si è rotto.</h1>
            <p class="lede">L'errore è stato annotato. Riferisci il codice
            <span class="mono">{$codice}</span>: il dettaglio si legge nella diagnostica,
            in fondo alla pagina.</p>
            <p style="margin-top:30px"><a href="{$base}/?p=diagnostica">Vai alla diagnostica</a>
            &nbsp;·&nbsp; <a href="{$base}/">Torna all'inizio</a></p>
            </div></div></body></html>
            HTML;
    }

    private static function nomeTipo(int $tipo): string
    {
        return match ($tipo) {
            E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR => 'Errore fatale',
            E_PARSE   => 'Errore di sintassi',
            E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING => 'Avviso',
            E_NOTICE, E_USER_NOTICE => 'Nota',
            E_USER_ERROR => 'Errore segnalato dal codice',
            E_DEPRECATED, E_USER_DEPRECATED => 'Deprecato',
            default   => 'Errore',
        };
    }
}
