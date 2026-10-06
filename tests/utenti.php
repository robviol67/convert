<?php
declare(strict_types=1);
/**
 * Verifiche dell'utente intoccabile: `php tests/utenti.php`.
 *
 * C'è un amministratore che nessun altro può toccare: la sua password la cambia
 * solo lui, dal suo accesso, e nessuna schermata, nessun ruolo e nessuna rotta
 * può reimpostargliela. La regola sta nel codice, e queste prove servono a
 * tenerla lì — una protezione che si può togliere senza che niente protesti non
 * è una protezione.
 *
 * Si prova anche la via d'uscita: la procedura d'emergenza, che sta fuori
 * dall'applicazione, deve continuare a funzionare. Senza, l'account sarebbe
 * perso per sempre al primo guaio.
 */

$tmp = sys_get_temp_dir() . '/vb-utenti-' . getmypid();
@mkdir($tmp, 0770, true);
putenv('CONVERT_DB=' . $tmp . '/prova.db');

require __DIR__ . '/../vendor/autoload.php';

use Vblite\Convert\Auth;
use Vblite\Convert\Database;

$passati = 0;
$falliti = [];

function verifica(string $nome, mixed $atteso, mixed $ottenuto): void
{
    global $passati, $falliti;
    if ($atteso === $ottenuto) {
        $passati++;
        return;
    }
    $falliti[] = sprintf("  %s\n     atteso:   %s\n     ottenuto: %s", $nome, var_export($atteso, true), var_export($ottenuto, true));
}

function vero(string $nome, bool $condizione): void
{
    verifica($nome, true, $condizione);
}

/** Quello che rifiuta un'operazione: il messaggio, o null se è passata. */
function rifiuto(callable $operazione): ?string
{
    try {
        $operazione();

        return null;
    } catch (\RuntimeException $e) {
        return $e->getMessage();
    }
}

$pdo = Database::pdo();
$hash = static function (int $id) use ($pdo): string {
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$id]);

    return (string) $stmt->fetchColumn();
};
$id = static function (string $email) use ($pdo): int {
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);

    return (int) $stmt->fetchColumn();
};

Auth::creaUtente(Auth::INTOCCABILE, 'Roberto', 'quella-di-partenza');
Auth::creaUtente('altro@esempio.test', 'Un altro', 'password-normale');
$protetto = $id(Auth::INTOCCABILE);
$normale  = $id('altro@esempio.test');

// ── Chi è intoccabile ────────────────────────────────────────────────────────
vero('l\'indirizzo protetto si riconosce', Auth::intoccabile(Auth::INTOCCABILE));
vero('anche scritto con maiuscole e spazi', Auth::intoccabile('  ROBVIOL@INSERTSRL.COM '));
vero('e si riconosce dall\'id', Auth::intoccabile($protetto));
verifica('un altro indirizzo non lo è', false, Auth::intoccabile('altro@esempio.test'));
verifica('né un altro id', false, Auth::intoccabile($normale));
verifica('né un id che non esiste', false, Auth::intoccabile(999999));

// La sessione si avvia qui, esplicitamente: più avanti si finge di essere
// questo o quell'utente scrivendo in $_SESSION, e farlo prima che la sessione
// esista non avrebbe effetto. Legarlo all'ordine delle prove le renderebbe
// fragili senza che si veda.
Auth::utente();

// ── Nessun altro gliela reimposta ────────────────────────────────────────────
$prima = $hash($protetto);

$motivo = rifiuto(static fn() => Auth::reimpostaPassword($protetto, 'qualunque-altra'));
vero('reimpostare la password del protetto viene rifiutato', $motivo !== null);
vero('e il rifiuto dice perché', str_contains((string) $motivo, 'protetta da una regola scritta nel codice'));
vero('e dice chi può farlo', str_contains((string) $motivo, 'solo lui'));
verifica('la password non è stata toccata', $prima, $hash($protetto));

// Fuori da una sessione — uno script, un giro di manutenzione — non è «lui».
$motivo = rifiuto(static fn() => Auth::cambiaPassword($protetto, 'da-uno-script'));
vero('senza sessione il cambio è rifiutato', $motivo !== null);
verifica('e la password resta quella', $prima, $hash($protetto));

// Nemmeno un altro utente collegato può passare dalla porta del cambio.
$_SESSION['user_id'] = $normale;
$motivo = rifiuto(static fn() => Auth::cambiaPassword($protetto, 'da-un-altro-utente'));
vero('un altro utente collegato non gliela cambia', $motivo !== null);
verifica('e la password è sempre quella', $prima, $hash($protetto));

// La vecchia password continua a valere: i rifiuti non hanno lasciato niente a metà.
vero('la password di partenza vale ancora', password_verify('quella-di-partenza', $hash($protetto)));

// ── Lui, dal suo accesso, se la cambia ───────────────────────────────────────
$_SESSION['user_id'] = $protetto;
verifica('dal suo accesso se la cambia', null, rifiuto(static fn() => Auth::cambiaPassword($protetto, 'scelta-da-lui-stesso')));
vero('e vale davvero', password_verify('scelta-da-lui-stesso', $hash($protetto)));
verifica('e non resta segnata come provvisoria', 0, (int) $pdo->query(
    'SELECT deve_cambiare FROM users WHERE id = ' . $protetto
)->fetchColumn());
vero('ma reimpostargliela resta vietato anche a lui',
    rifiuto(static fn() => Auth::reimpostaPassword($protetto, 'per-via-traversa')) !== null);
$_SESSION['user_id'] = null;

// ── Gli altri utenti si gestiscono come sempre ───────────────────────────────
verifica('un altro utente si reimposta', null, rifiuto(static fn() => Auth::reimpostaPassword($normale, 'nuova-provvisoria')));
vero('e la password cambia davvero', password_verify('nuova-provvisoria', $hash($normale)));
verifica('e la può cambiare lui stesso', null, rifiuto(static fn() => Auth::cambiaPassword($normale, 'scelta-da-lui')));
vero('con effetto', password_verify('scelta-da-lui', $hash($normale)));

// ── La via d'uscita, che sta fuori dall'applicazione ─────────────────────────
Auth::impostaDaEmergenza($protetto, 'rimessa-dal-server');
vero('la procedura d\'emergenza rimette la password', password_verify('rimessa-dal-server', $hash($protetto)));
verifica('e non la segna come provvisoria', 0, (int) $pdo->query(
    'SELECT deve_cambiare FROM users WHERE id = ' . $protetto
)->fetchColumn());
vero('la regola vale ancora dopo l\'emergenza',
    rifiuto(static fn() => Auth::reimpostaPassword($protetto, 'un-altro-tentativo')) !== null);

// ── La regola non si aggira cancellando l'utente ─────────────────────────────
// Nell'applicazione non esiste nessuna cancellazione di utenti: se un domani
// venisse aggiunta, questa prova è il posto dove accorgersene.
$sorgenti = '';
foreach (['index.php', 'app', 'bin', 'views'] as $dove) {
    $percorso = __DIR__ . '/../' . $dove;
    foreach (is_dir($percorso)
        ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($percorso))
        : [new SplFileInfo($percorso)] as $file) {
        if ($file->isFile() && str_ends_with((string) $file, '.php')) {
            $sorgenti .= (string) file_get_contents((string) $file);
        }
    }
}
verifica('nessuna cancellazione di utenti nel codice', 0, preg_match('~DELETE\s+FROM\s+users~i', $sorgenti));

foreach (glob($tmp . '/*') ?: [] as $file) {
    @unlink($file);
}
@rmdir($tmp);

echo "\n";
if ($falliti === []) {
    echo "OK — {$passati} verifiche passate.\n";
    exit(0);
}
echo 'FALLITE ' . count($falliti) . ' su ' . ($passati + count($falliti)) . ":\n\n";
echo implode("\n\n", $falliti) . "\n";
exit(1);
