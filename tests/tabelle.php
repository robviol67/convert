<?php
declare(strict_types=1);
/**
 * Verifiche della tipologia Tabella → Tabella: `php tests/tabelle.php`.
 * Il file prodotto si rilegge col nostro lettore, che è indipendente dallo
 * scrittore: se sbagliassero allo stesso modo non se ne accorgerebbe nessuno,
 * per questo l'XLSX si controlla anche nell'XML.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/LettoreXlsx.php';

use Vblite\Convert\Conversioni\Tabelle\ConversioneTabelle;
use Vblite\Convert\Conversioni\Tabelle\LettoreTabella;

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

$tmp = sys_get_temp_dir() . '/vb-tab-' . getmypid();
@mkdir($tmp, 0770, true);

// ── Un CSV all'italiana: punto e virgola, virgola decimale ───────────────────
$csv = $tmp . '/fornitore.csv';
file_put_contents($csv, "codice;descrizione;prezzo;quantita\n"
    . "A-100;Vite M6 zincata;0,45;1200\n"
    . "A-101;Dado \"speciale\" M6;0,12;3000\n"
    . "B-220;\"Rondella; piana\";0,05;5000\n");

$lettore = new LettoreTabella($csv);
verifica('il separatore giusto viene indovinato', ['codice', 'descrizione', 'prezzo', 'quantita'], $lettore->intestazioni());

$righe = [];
foreach ($lettore->dati() as $riga) {
    $righe[] = $riga;
}
verifica('tre righe di dati', 3, count($righe));
verifica('le virgolette non spezzano il campo', 'Dado "speciale" M6', $righe[1][1]);
verifica('il punto e virgola dentro le virgolette non separa', 'Rondella; piana', $righe[2][1]);

// ── La mappatura ─────────────────────────────────────────────────────────────
$conversione = new ConversioneTabelle();

$mappa = [
    ['nome' => 'Fornitore',       'da' => ConversioneTabelle::FISSO, 'valore' => 'ACME SRL', 'tipo' => 'testo'],
    ['nome' => 'Codice articolo', 'da' => 'codice',                  'valore' => '',         'tipo' => 'testo'],
    ['nome' => 'Pezzi',           'da' => 'quantita',                'valore' => '',         'tipo' => 'intero'],
    ['nome' => 'Nota',            'da' => ConversioneTabelle::VUOTO, 'valore' => '',         'tipo' => 'testo'],
    ['nome' => 'Campagna',        'da' => ConversioneTabelle::FISSO, 'valore' => 'MARZO',    'tipo' => 'testo'],
];

$uscita = $tmp . '/mappato.xlsx';
$esito  = $conversione->converti($csv, $uscita, ['formato' => 'xlsx', 'colonne' => $mappa]);

verifica('tre righe scritte', 3, $esito['righe_scritte']);
verifica('cinque colonne in uscita', 5, $esito['pagine']);
verifica('nessuna anomalia con una mappatura valida', 0, count($esito['anomalie']));

$prodotto = new LettoreTabella($uscita);
verifica(
    'le colonne escono con i nomi scelti e nell\'ordine scelto',
    ['Fornitore', 'Codice articolo', 'Pezzi', 'Nota', 'Campagna'],
    $prodotto->intestazioni()
);

$dati = [];
foreach ($prodotto->dati() as $riga) {
    $dati[] = $riga;
}
verifica('il valore fisso è su tutte le righe', ['ACME SRL', 'ACME SRL', 'ACME SRL'], array_column($dati, 0));
verifica('la seconda colonna fissa pure', ['MARZO', 'MARZO', 'MARZO'], array_column($dati, 4));
verifica('i valori mappati sono quelli giusti', ['A-100', 'A-101', 'B-220'], array_column($dati, 1));
verifica('la colonna «lascia vuota» è vuota', ['', '', ''], array_column($dati, 3));
verifica('la colonna non mappata resta fuori', false, in_array('descrizione', $prodotto->intestazioni(), true));

// L'XLSX deve essere quello che Excel si aspetta, non solo qualcosa che
// rileggiamo noi: l'ordine degli elementi è la parte che Excel fa rispettare.
$ordine = \Vblite\Convert\Test\LettoreXlsx::ordineElementi($uscita);
verifica('gli elementi del foglio sono nell\'ordine dello schema',
    ['dimension', 'sheetViews', 'sheetFormatPr', 'cols', 'sheetData'], $ordine);

$foglio = new \Vblite\Convert\Test\LettoreXlsx($uscita);
vero('la colonna dichiarata intera esce come numero', $foglio->eNumero('C2'));
vero('la colonna dichiarata testo resta testo', !$foglio->eNumero('A2'));

// ── Il caso che rompe: un preset su un tracciato diverso ─────────────────────
$altro = $tmp . '/altro.csv';
file_put_contents($altro, "sku;titolo;pz\nX-1;Altro articolo;10\n");

$esitoAltro = $conversione->converti($altro, $tmp . '/altro.xlsx', ['formato' => 'xlsx', 'colonne' => $mappa]);
verifica('le colonne mancanti vengono segnalate', 2, count($esitoAltro['anomalie']));
vero('la segnalazione dice quale colonna manca',
    str_contains($esitoAltro['anomalie'][0]['motivo'], 'codice')
    || str_contains($esitoAltro['anomalie'][1]['motivo'], 'quantita'));
verifica('la riga esce comunque', 1, $esitoAltro['righe_scritte']);

$conBuchi = new LettoreTabella($tmp . '/altro.xlsx');
$primaRiga = [];
foreach ($conBuchi->dati() as $riga) {
    $primaRiga = $riga;
    break;
}
verifica('il valore fisso c\'è anche col tracciato sbagliato', 'ACME SRL', $primaRiga[0] ?? '');
verifica('la colonna che non esiste esce vuota, non con un errore', '', $primaRiga[1] ?? 'x');

// ── Senza mappatura: identità ────────────────────────────────────────────────
$identita = $conversione->converti($csv, $tmp . '/identita.xlsx', ['formato' => 'xlsx']);
$comEra   = new LettoreTabella($tmp . '/identita.xlsx');
verifica('senza mappatura le colonne restano quelle',
    ['codice', 'descrizione', 'prezzo', 'quantita'], $comEra->intestazioni());
verifica('senza mappatura le righe restano quelle', 3, $identita['righe_scritte']);

// ── CSV in uscita ────────────────────────────────────────────────────────────
$conversione->converti($csv, $tmp . '/uscita.csv', ['formato' => 'csv', 'colonne' => $mappa]);
$testoCsv = (string) file_get_contents($tmp . '/uscita.csv');
vero('il CSV comincia col segno d\'ordine dei byte, per Excel', str_starts_with($testoCsv, "\u{FEFF}"));

// Il CSV si rilegge invece di cercarci dentro una stringa: PHP mette le
// virgolette ai campi che contengono uno spazio, e un confronto letterale
// fallirebbe su un file perfettamente valido.
$csvRiletto = new LettoreTabella($tmp . '/uscita.csv');
verifica(
    'il CSV porta le intestazioni scelte',
    ['Fornitore', 'Codice articolo', 'Pezzi', 'Nota', 'Campagna'],
    $csvRiletto->intestazioni()
);
$righeCsv = [];
foreach ($csvRiletto->dati() as $riga) {
    $righeCsv[] = $riga;
}
verifica('il CSV porta i valori fissi su ogni riga', ['ACME SRL', 'ACME SRL', 'ACME SRL'], array_column($righeCsv, 0));

// ── XLSX in ingresso, con date e celle vuote ─────────────────────────────────
// Si costruisce con il nostro scrittore e si rilegge: il giro completo.
$fonte = new \Vblite\Convert\Supporto\FoglioXlsx();
$fonte->apri($tmp . '/date.xlsx', [
    ['testata' => 'nome', 'tipo' => 'testo'],
    ['testata' => 'quando', 'tipo' => 'data'],
    ['testata' => 'quanto', 'tipo' => 'valuta'],
]);
$fonte->riga(['primo', 45658, 12.5]);      // 45658 = 01/01/2025
$fonte->riga(['secondo', null, 3.0]);      // cella data vuota
$fonte->chiudi();

$conDate = new LettoreTabella($tmp . '/date.xlsx');
$righeDate = [];
foreach ($conDate->dati() as $riga) {
    $righeDate[] = $riga;
}
verifica('la data non esce come numero seriale', '01/01/2025', $righeDate[0][1] ?? '');
verifica('una cella vuota non sposta le colonne', 'secondo', $righeDate[1][0] ?? '');
verifica('la cella vuota resta vuota', '', $righeDate[1][1] ?? 'x');
verifica('il valore dopo la cella vuota è al suo posto', '3', $righeDate[1][2] ?? '');

// ── Memoria ──────────────────────────────────────────────────────────────────
$grande = $tmp . '/grande.csv';
$f = fopen($grande, 'w');
fwrite($f, "a;b;c;d;e\n");
for ($i = 0; $i < 20000; $i++) {
    fwrite($f, "riga{$i};valore che occupa un po' di spazio;{$i};altro testo;ancora\n");
}
fclose($f);

if (function_exists('memory_reset_peak_usage')) {
    memory_reset_peak_usage();
}
$tante = $conversione->converti($grande, $tmp . '/grande.xlsx', ['formato' => 'xlsx']);
$picco = memory_get_peak_usage(true) / 1048576;
verifica('ventimila righe convertite', 20000, $tante['righe_scritte']);
vero(sprintf('ventimila righe stanno sotto i 20 MB (picco %.1f MB)', $picco), $picco < 20);

// ── Pulizia ──────────────────────────────────────────────────────────────────
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
