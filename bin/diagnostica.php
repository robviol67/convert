<?php
declare(strict_types=1);
/**
 * Controlla che l'hosting regga quello che serve: si apre da browser
 * (/convert/bin/diagnostica.php e' bloccato dall'.htaccess, quindi va lanciato
 * da riga di comando) oppure con `php bin/diagnostica.php`.
 */

require __DIR__ . '/../vendor/autoload.php';

use Vblite\Convert\Config;

$controlli = [];

$controlli[] = ['PHP ≥ 8.1', PHP_VERSION, version_compare(PHP_VERSION, '8.1', '>=')];

foreach (['pdo_sqlite', 'zip', 'dom', 'xml', 'mbstring', 'gd', 'fileinfo'] as $estensione) {
    $controlli[] = ["estensione {$estensione}", extension_loaded($estensione) ? 'presente' : 'assente', extension_loaded($estensione)];
}

$dimensione = static fn(string $chiave): int => (int) preg_replace('~\D~', '', (string) ini_get($chiave))
    * (str_contains(strtolower((string) ini_get($chiave)), 'g') ? 1024 : 1);

$controlli[] = ['upload_max_filesize ≥ 50M', (string) ini_get('upload_max_filesize'), $dimensione('upload_max_filesize') >= 50];
$controlli[] = ['post_max_size ≥ 50M', (string) ini_get('post_max_size'), $dimensione('post_max_size') >= 50];
$controlli[] = ['max_execution_time ≥ 120', (string) ini_get('max_execution_time'), (int) ini_get('max_execution_time') === 0 || (int) ini_get('max_execution_time') >= 120];
$controlli[] = ['memory_limit ≥ 256M', (string) ini_get('memory_limit'), $dimensione('memory_limit') >= 256 || (int) ini_get('memory_limit') === -1];

$disabilitate = array_map('trim', explode(',', (string) ini_get('disable_functions')));
$controlli[] = [
    'exec() disponibile (job in sfondo)',
    in_array('exec', $disabilitate, true) ? 'disabilitata — si converte in linea' : 'disponibile',
    true, // non e' bloccante: c'e' il ripiego in linea
];

foreach ([Config::cartellaIngresso(), Config::cartellaUscita(), dirname(Config::percorsoDb())] as $cartella) {
    $ok = is_dir($cartella) && is_writable($cartella);
    $controlli[] = ["cartella scrivibile " . basename(dirname($cartella)) . '/' . basename($cartella), $ok ? 'ok' : 'NON scrivibile', $ok];
}

// I nomi contengono accenti e simboli: si allinea sui caratteri, non sui byte.
$larghezza = max(array_map(static fn(array $c): int => mb_strlen($c[0]), $controlli));
$problemi  = 0;
echo "vblite /convert — diagnostica dell'hosting\n\n";
foreach ($controlli as [$nome, $valore, $ok]) {
    $riempimento = str_repeat(' ', $larghezza - mb_strlen($nome));
    echo ($ok ? '·' : '!') . " {$nome}{$riempimento}  {$valore}\n";
    $problemi += $ok ? 0 : 1;
}
echo "\n" . ($problemi === 0 ? "Tutto a posto.\n" : "{$problemi} punti da sistemare prima di usare l'applicazione.\n");
exit($problemi === 0 ? 0 : 1);
