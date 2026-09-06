<?php
declare(strict_types=1);
/**
 * Verifiche della cancellazione dello storico: `php tests/storico.php`.
 *
 * È l'unica funzione irreversibile dell'applicazione, e le due cose che
 * possono andare storte sono entrambe silenziose: portarsi via un file che
 * serviva ancora a un'altra conversione, o lasciare in giro file e righe di
 * una conversione cancellata. Si provano tutte e due.
 *
 * Il database è temporaneo. I file stanno nelle cartelle vere dell'archivio,
 * perché è là che elimina() è disposta a cancellare — con un nome che dice a
 * chi appartengono, e ripuliti in fondo comunque vada.
 */

require __DIR__ . '/../vendor/autoload.php';

$db = sys_get_temp_dir() . '/vb-storico-' . getmypid() . '.db';
putenv('CONVERT_DB=' . $db);

use Vblite\Convert\Auth;
use Vblite\Convert\Config;
use Vblite\Convert\Database;
use Vblite\Convert\Job;

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

$marchio = 'prova-storico-' . getmypid();
$nati    = [];

/** Un file vero nell'archivio, così elimina() ha qualcosa da cancellare. */
function file_prova(string $cartella, string $nome, string $contenuto): string
{
    global $nati;
    if (!is_dir($cartella)) {
        mkdir($cartella, 0770, true);
    }
    $percorso = $cartella . '/' . $nome;
    file_put_contents($percorso, $contenuto);
    $nati[] = $percorso;

    return $percorso;
}

$pdo = Database::pdo();
Auth::creaUtente($marchio . '@esempio.test', 'Prova', str_repeat('x', 12));
$utente = $pdo->query("SELECT id FROM users WHERE email = '{$marchio}@esempio.test'")->fetchColumn();
$utente = (int) $utente;

// ── Una conversione con i suoi due file ──────────────────────────────────────
$in  = file_prova(Config::cartellaIngresso(), $marchio . '-a.pdf', str_repeat('A', 1000));
$out = file_prova(Config::cartellaUscita(), $marchio . '-a.xlsx', str_repeat('B', 500));

$job = Job::crea($utente, 'octo_scidoo', $in, 'listino.pdf', []);
$pdo->prepare('UPDATE jobs SET file_out = ?, byte_out = 500 WHERE id = ?')->execute([$out, $job['id']]);
$pdo->prepare('INSERT INTO anomalie (job_id, motivo, colonna, gravita) VALUES (?, ?, ?, ?)')
    ->execute([$job['id'], 'una nota', 'Nome', 'informativa']);
$pdo->prepare('INSERT INTO correzioni (job_id, chiave, colonna, valore) VALUES (?, ?, ?, ?)')
    ->execute([$job['id'], '4.256', 'Nome', 'flavio']);

$tolti = Job::elimina((int) $job['id']);

verifica('due file cancellati', 2, $tolti['file']);
verifica('i byte liberati sono quelli veri', 1500, $tolti['byte']);
vero('il file d\'ingresso non c\'è più', !is_file($in));
vero('il file d\'uscita non c\'è più', !is_file($out));
verifica('la riga è sparita', null, Job::trova((int) $job['id']));

$resta = static function (string $tabella) use ($pdo, $job): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$tabella} WHERE job_id = ?");
    $stmt->execute([$job['id']]);

    return (int) $stmt->fetchColumn();
};
verifica('nessuna anomalia orfana', 0, $resta('anomalie'));
verifica('nessuna correzione orfana', 0, $resta('correzioni'));

// ── Il file d'ingresso condiviso da un «Rifai» ───────────────────────────────
// «Rifai» non ricarica niente: crea una conversione nuova sullo stesso PDF.
// Cancellarne una non deve togliere il PDF all'altra.
$condiviso = file_prova(Config::cartellaIngresso(), $marchio . '-b.pdf', str_repeat('C', 800));
$out1      = file_prova(Config::cartellaUscita(), $marchio . '-b1.xlsx', 'uno');
$out2      = file_prova(Config::cartellaUscita(), $marchio . '-b2.xlsx', 'due');

$primo   = Job::crea($utente, 'octo_scidoo', $condiviso, 'condiviso.pdf', []);
$secondo = Job::crea($utente, 'octo_scidoo', $condiviso, 'condiviso.pdf', []);
$pdo->prepare('UPDATE jobs SET file_out = ? WHERE id = ?')->execute([$out1, $primo['id']]);
$pdo->prepare('UPDATE jobs SET file_out = ? WHERE id = ?')->execute([$out2, $secondo['id']]);

$tolti = Job::elimina((int) $secondo['id']);
verifica('della riconversione si cancella solo l\'uscita', 1, $tolti['file']);
vero('il PDF resta a chi lo usa ancora', is_file($condiviso));
vero('l\'uscita della riconversione è sparita', !is_file($out2));
vero('l\'altra conversione è intatta', Job::trova((int) $primo['id']) !== null);

// Tolta anche l'ultima, il PDF può andarsene.
$tolti = Job::elimina((int) $primo['id']);
verifica('l\'ultima si porta via anche il PDF', 2, $tolti['file']);
vero('il PDF non c\'è più', !is_file($condiviso));

// ── Un percorso fuori dall'archivio non si tocca ─────────────────────────────
// Se il database dicesse /etc/qualcosa, cancellarlo non è affar nostro.
$fuori = sys_get_temp_dir() . '/' . $marchio . '-fuori.txt';
file_put_contents($fuori, 'non toccare');
$nati[] = $fuori;

$estraneo = Job::crea($utente, 'octo_scidoo', $fuori, 'fuori.pdf', []);
$pdo->prepare('UPDATE jobs SET file_out = ? WHERE id = ?')->execute([$fuori, $estraneo['id']]);
$tolti = Job::elimina((int) $estraneo['id']);
verifica('un file fuori dall\'archivio non viene toccato', 0, $tolti['file']);
vero('ed è ancora al suo posto', is_file($fuori));
verifica('ma la riga se ne va lo stesso', null, Job::trova((int) $estraneo['id']));

// ── Svuotare ─────────────────────────────────────────────────────────────────
for ($i = 1; $i <= 3; $i++) {
    $f = file_prova(Config::cartellaIngresso(), $marchio . "-s{$i}.pdf", str_repeat('D', 100));
    $u = file_prova(Config::cartellaUscita(), $marchio . "-s{$i}.xlsx", str_repeat('E', 50));
    $j = Job::crea($utente, 'octo_scidoo', $f, "s{$i}.pdf", []);
    $pdo->prepare('UPDATE jobs SET file_out = ? WHERE id = ?')->execute([$u, $j['id']]);
}
verifica('tre conversioni in archivio', 3, (int) $pdo->query('SELECT COUNT(*) FROM jobs')->fetchColumn());

$tolti = Job::svuota();
verifica('svuota le conta tutte', 3, $tolti['conversioni']);
verifica('e porta via sei file', 6, $tolti['file']);
verifica('per 450 byte', 450, $tolti['byte']);
verifica('lo storico è vuoto', 0, (int) $pdo->query('SELECT COUNT(*) FROM jobs')->fetchColumn());
verifica('svuotare due volte non è un errore', 0, Job::svuota()['conversioni']);

// Gli utenti restano: si svuota lo storico, non l'anagrafica.
verifica('l\'utente c\'è ancora', 1, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());

// ── Pulizia ──────────────────────────────────────────────────────────────────
foreach ($nati as $percorso) {
    @unlink($percorso);
}
foreach ([$db, $db . '-wal', $db . '-shm'] as $f) {
    @unlink($f);
}

echo "\n";
if ($falliti === []) {
    echo "OK — {$passati} verifiche passate.\n";
    exit(0);
}
echo 'FALLITE ' . count($falliti) . ' su ' . ($passati + count($falliti)) . ":\n\n";
echo implode("\n\n", $falliti) . "\n";
exit(1);
