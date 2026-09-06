<?php
declare(strict_types=1);
/**
 * Verifiche della tipologia PDF tabellare → Excel: `php tests/pdftabella.php`.
 *
 * Il PDF di prova si costruisce col nostro scrittore PDF, così la tabella è
 * nota e le attese non sono indovinate. Poi si prova anche sulla stampa
 * Octorate vera, che è il caso per cui questo metodo è nato.
 */

require __DIR__ . '/../vendor/autoload.php';

use Vblite\Convert\Conversioni\PdfTabella\ConversionePdfTabella;
use Vblite\Convert\Conversioni\PdfTabella\LettoreGriglia;
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

$tmp = sys_get_temp_dir() . '/vb-pdft-' . getmypid();
@mkdir($tmp, 0770, true);

/**
 * Un PDF con una tabella vera, scritto a mano: le colonne stanno a coordinate
 * note, così si sa cosa deve venire fuori.
 */
function pdfConTabella(string $percorso, int $pagine = 4): void
{
    $colonne = [60, 200, 340, 460];
    $flussi  = [];

    for ($p = 1; $p <= $pagine; $p++) {
        $y = 760;
        $c = '';

        // Testata ripetuta su ogni pagina: deve sparire dai dati.
        $c .= sprintf("BT /F1 9 Tf 1 0 0 1 %d %d Tm (Listino ACME - riservato) Tj ET\n", 60, 800);

        // Intestazioni della tabella, anch'esse su ogni pagina.
        foreach (['Codice', 'Descrizione', 'Prezzo', 'Pezzi'] as $i => $t) {
            $c .= sprintf("BT /F2 10 Tf 1 0 0 1 %d %d Tm (%s) Tj ET\n", $colonne[$i], $y, $t);
        }
        $y -= 22;

        for ($r = 1; $r <= 12; $r++) {
            $n = ($p - 1) * 12 + $r;
            $valori = ['ART-' . $n, 'Articolo numero ' . $n, number_format($n * 1.5, 2, '.', ''), (string) ($n * 10)];
            foreach ($valori as $i => $t) {
                $c .= sprintf("BT /F1 10 Tf 1 0 0 1 %d %d Tm (%s) Tj ET\n", $colonne[$i], $y, $t);
            }
            $y -= 18;
        }

        // Piè di pagina che cambia solo nel numero.
        $c .= sprintf("BT /F1 8 Tf 1 0 0 1 %d %d Tm (Pagina %d) Tj ET\n", 300, 40, $p);
        $flussi[] = $c;
    }

    $oggetti = [];
    $scostamenti = [];
    $out = "%PDF-1.4\n";
    $pos = strlen($out);

    $primaPagina = 3;
    $primoFlusso = $primaPagina + $pagine;
    $primoFont   = $primoFlusso + $pagine;
    $totale      = $primoFont + 2;

    $scrivi = static function (string $s) use (&$out, &$pos): void {
        $out .= $s;
        $pos += strlen($s);
    };

    $scostamenti[1] = $pos;
    $scrivi("1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n");

    $kids = [];
    for ($i = 0; $i < $pagine; $i++) {
        $kids[] = ($primaPagina + $i) . ' 0 R';
    }
    $scostamenti[2] = $pos;
    $scrivi("2 0 obj\n<< /Type /Pages /Count {$pagine} /Kids [" . implode(' ', $kids) . "] >>\nendobj\n");

    $risorse = '<< /Font << /F1 ' . $primoFont . ' 0 R /F2 ' . ($primoFont + 1) . ' 0 R >> >>';
    for ($i = 0; $i < $pagine; $i++) {
        $n = $primaPagina + $i;
        $scostamenti[$n] = $pos;
        $scrivi("{$n} 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources {$risorse} /Contents "
            . ($primoFlusso + $i) . " 0 R >>\nendobj\n");
    }
    for ($i = 0; $i < $pagine; $i++) {
        $n = $primoFlusso + $i;
        $scostamenti[$n] = $pos;
        $scrivi("{$n} 0 obj\n<< /Length " . strlen($flussi[$i]) . " >>\nstream\n{$flussi[$i]}endstream\nendobj\n");
    }
    foreach (['Helvetica', 'Helvetica-Bold'] as $i => $nome) {
        $n = $primoFont + $i;
        $scostamenti[$n] = $pos;
        $scrivi("{$n} 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /{$nome} /Encoding /WinAnsiEncoding >>\nendobj\n");
    }

    $inizio = $pos;
    $scrivi("xref\n0 {$totale}\n0000000000 65535 f \n");
    for ($n = 1; $n < $totale; $n++) {
        $scrivi(sprintf("%010d 00000 n \n", $scostamenti[$n] ?? 0));
    }
    $scrivi("trailer\n<< /Size {$totale} /Root 1 0 R >>\nstartxref\n{$inizio}\n%%EOF\n");

    file_put_contents($percorso, $out);
}

$pdf = $tmp . '/listino.pdf';
pdfConTabella($pdf, 4);
vero('il PDF di prova è stato scritto', is_file($pdf) && filesize($pdf) > 1000);

// ── Riconoscimento della griglia ─────────────────────────────────────────────
$griglia = new LettoreGriglia($pdf);
$griglia->analizza();

verifica('quattro pagine', 4, $griglia->pagine());
verifica('quattro colonne riconosciute', 4, $griglia->colonne());
vero('le righe ripetute sono state riconosciute', $griglia->quanteRipetute() >= 2);

$righe = [];
$griglia->righe(static function (int $pagina, array $celle) use (&$righe): void {
    $righe[] = $celle;
});

// 4 pagine × 12 righe = 48, senza testata, intestazioni e piè di pagina.
verifica('restano solo le righe di dati', 48, count($righe));
verifica('la prima cella della prima riga', 'ART-1', $righe[0][0] ?? '');
verifica('la descrizione va nella seconda colonna', 'Articolo numero 1', $righe[0][1] ?? '');
verifica('il prezzo va nella terza', '1.50', $righe[0][2] ?? '');
verifica('i pezzi vanno nella quarta', '10', $righe[0][3] ?? '');
verifica('l\'ultima riga è dell\'ultima pagina', 'ART-48', $righe[47][0] ?? '');

$conTestate = 0;
$griglia->righe(static function () use (&$conTestate): void {
    $conTestate++;
}, false);
vero('senza togliere le ripetute le righe sono di più', $conTestate > count($righe));

// ── La conversione ───────────────────────────────────────────────────────────
$conversione = new ConversionePdfTabella();

$v = $conversione->verifica($pdf);
vero('il PDF viene accettato', $v['ok']);
verifica('la verifica conta le colonne', 4, $v['intestazione']['colonne'] ?? 0);

// Con le intestazioni prese dalla prima riga.
$analisi = $conversione->analizza($pdf, ['pdf_intestazioni' => true]);
verifica(
    'le intestazioni si leggono dalla prima riga quando lo si chiede',
    ['Codice', 'Descrizione', 'Prezzo', 'Pezzi'],
    $analisi['colonne']
);
vero('l\'anteprima salta la riga delle intestazioni',
    ($analisi['assaggio'][0][0] ?? '') === 'ART-1');

// Senza: nomi di comodo.
$anonimo = $conversione->analizza($pdf);
verifica('senza spunta le colonne hanno nomi di comodo', 'Colonna 1', $anonimo['colonne'][0] ?? '');

$uscita = $tmp . '/listino.xlsx';
$esito  = $conversione->converti($pdf, $uscita, [
    'formato' => 'xlsx',
    'pdf_intestazioni' => true,
    'colonne' => [
        ['nome' => 'Fornitore', 'da' => ConversioneTabelle::FISSO, 'valore' => 'ACME', 'tipo' => 'testo'],
        ['nome' => 'Codice',    'da' => 'Codice',      'valore' => '', 'tipo' => 'testo'],
        ['nome' => 'Pezzi',     'da' => 'Pezzi',       'valore' => '', 'tipo' => 'intero'],
    ],
]);

verifica('quarantotto righe scritte', 48, $esito['righe_scritte']);

$prodotto = new LettoreTabella($uscita);
verifica('le colonne sono quelle mappate', ['Fornitore', 'Codice', 'Pezzi'], $prodotto->intestazioni());

$dati = [];
foreach ($prodotto->dati() as $riga) {
    $dati[] = $riga;
}
verifica('quarantotto righe nel foglio', 48, count($dati));
verifica('il valore fisso c\'è', 'ACME', $dati[0][0] ?? '');
verifica('il codice viene dalla colonna giusta', 'ART-1', $dati[0][1] ?? '');
verifica('i pezzi vengono dalla colonna giusta', '10', $dati[0][2] ?? '');

// La segnalazione sul metodo dev'esserci sempre: è una conversione che deduce.
$motivi = array_column($esito['anomalie'], 'motivo');
vero('viene detto che le colonne sono dedotte', array_reduce(
    $motivi,
    static fn(bool $t, string $m): bool => $t || str_contains($m, 'dedotte'),
    false
));

// ── Il corridoio cambia il risultato ─────────────────────────────────────────
// Con un corridoio molto largo le colonne vicine si fondono.
$larga = new LettoreGriglia($pdf, 200.0);
$larga->analizza();
vero('con un corridoio larghissimo si trovano meno colonne', $larga->colonne() < 4);

// ── Un PDF senza testo estraibile ────────────────────────────────────────────
$vuoto = $tmp . '/vuoto.pdf';
file_put_contents($vuoto, "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
    . "2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
    . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\n"
    . "trailer<</Root 1 0 R/Size 4>>\n");
$esitoVuoto = $conversione->verifica($vuoto);
verifica('un PDF senza testo viene rifiutato', false, $esitoVuoto['ok']);
vero('e viene detto perché', str_contains((string) $esitoVuoto['motivo'], 'immagini')
    || str_contains((string) $esitoVuoto['motivo'], 'illeggibile'));

// ── Memoria ──────────────────────────────────────────────────────────────────
$grande = $tmp . '/grande.pdf';
pdfConTabella($grande, 60);      // 720 righe di dati

if (function_exists('memory_reset_peak_usage')) {
    memory_reset_peak_usage();
}
$tante = $conversione->converti($grande, $tmp . '/grande.xlsx', ['formato' => 'xlsx']);
$picco = memory_get_peak_usage(true) / 1048576;
verifica('sessanta pagine danno 720 righe', 720, $tante['righe_scritte']);
vero(sprintf('sessanta pagine stanno sotto i 20 MB (picco %.1f MB)', $picco), $picco < 20);

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
