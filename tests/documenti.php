<?php
declare(strict_types=1);
/**
 * Verifiche della tipologia Documenti ↔ Markdown: `php tests/documenti.php`.
 *
 * Ogni formato si controlla con gli strumenti di PHP, non con la libreria che
 * lo ha scritto: un validatore che condivide il codice dello scrittore
 * proverebbe poco. Il docx e lo zip si aprono con ZipArchive, il PDF si legge
 * con il parser che usiamo in ingresso, l'RTF si controlla nella sintassi.
 */

require __DIR__ . '/../vendor/autoload.php';

use Vblite\Convert\Conversioni\Documenti\Blocco;
use Vblite\Convert\Conversioni\Documenti\ConversioneDocumenti;
use Vblite\Convert\Conversioni\Documenti\Documento;
use Vblite\Convert\Conversioni\Documenti\Formati;

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

$tmp = sys_get_temp_dir() . '/vb-doc-' . getmypid();
@mkdir($tmp, 0770, true);

/** Converte un file in un formato e torna il percorso prodotto. */
function converti(string $ingresso, string $formato, string $tmp): string
{
    $uscita    = $tmp . '/uscita.' . $formato;
    $documento = new Documento();
    $scrittore = Formati::scrittore($formato);
    $scrittore->apri($uscita, $documento);
    $documento->consuma(static fn(Blocco $b) => $scrittore->blocco($b));
    Formati::lettore($ingresso)->leggi($ingresso, $tmp . '/media', $documento);
    $scrittore->chiudi();

    return $uscita;
}

// ── Un documento di prova che tocca tutti i tipi di blocco ───────────────────
$sorgente = $tmp . '/sorgente.md';
file_put_contents($sorgente, <<<'MD'
    # Titolo primo

    Paragrafo con **grassetto**, *corsivo*, `codice` e un [collegamento](https://vblite.com).

    ## Sezione

    - primo
    - secondo con **enfasi**
      - annidato
    1. uno
    2. due

    > Citazione.

    ```php
    $a = 1;
    ```

    | A | B |
    | --- | --- |
    | uno | due |

    ---

    Chiusura con nome_file e 2 * 3 = 6.
    MD);

// ── Lettura del Markdown ─────────────────────────────────────────────────────
$documento = new Documento();
Formati::lettore($sorgente)->leggi($sorgente, $tmp . '/media', $documento);

$riepilogo = $documento->riepilogo();
verifica('titoli riconosciuti', 2, $riepilogo[Blocco::TITOLO] ?? 0);
verifica('voci di elenco riconosciute', 5, $riepilogo[Blocco::ELENCO] ?? 0);
verifica('citazione riconosciuta', 1, $riepilogo[Blocco::CITAZIONE] ?? 0);
verifica('blocco di codice riconosciuto', 1, $riepilogo[Blocco::CODICE] ?? 0);
verifica('tabella riconosciuta', 1, $riepilogo[Blocco::TABELLA] ?? 0);
verifica('riga orizzontale riconosciuta', 1, $riepilogo[Blocco::RIGA] ?? 0);

// Un asterisco isolato non è corsivo: «2 * 3 = 6» resta com'è.
$ultimo = $documento->blocchi()[count($documento->blocchi()) - 1];
verifica('asterisco isolato non è corsivo', 'Chiusura con nome_file e 2 * 3 = 6.', $ultimo->nudo());
$corsivi = 0;
foreach ($ultimo->testi as $tratto) {
    $corsivi += $tratto->corsivo ? 1 : 0;
}
verifica('nessun tratto in corsivo nell\'ultima riga', 0, $corsivi);

// ── Ogni formato in uscita produce un file valido ────────────────────────────
foreach (array_keys(Formati::formatiInUscita()) as $formato) {
    $prodotto = converti($sorgente, $formato, $tmp);
    vero("uscita .{$formato}: il file esiste e non è vuoto", is_file($prodotto) && filesize($prodotto) > 100);
}

// ── Il .docx è un pacchetto Word valido ──────────────────────────────────────
$docx = converti($sorgente, 'docx', $tmp);
$zip  = new ZipArchive();
verifica('il .docx si apre come zip', true, $zip->open($docx) === true);
foreach (['[Content_Types].xml', 'word/document.xml', 'word/styles.xml', 'word/numbering.xml'] as $parte) {
    vero("il .docx contiene {$parte}", $zip->locateName($parte) !== false);
}
$xmlDocx = (string) $zip->getFromName('word/document.xml');
$zip->close();

vero('il document.xml è XML ben formato', @simplexml_load_string($xmlDocx) !== false);
vero('i titoli usano gli stili, non solo il grassetto', str_contains($xmlDocx, '<w:pStyle w:val="Titolo1"/>'));
vero('gli elenchi usano la numerazione di Word', str_contains($xmlDocx, '<w:numPr>'));
vero('la tabella marca la riga di intestazione', str_contains($xmlDocx, '<w:tblHeader/>'));

// ── Il PDF è valido e il testo si estrae ─────────────────────────────────────
$pdf = converti($sorgente, 'pdf', $tmp);
$testa = (string) file_get_contents($pdf, false, null, 0, 8);
vero('il PDF comincia con %PDF', str_starts_with($testa, '%PDF-'));
$coda = (string) file_get_contents($pdf, false, null, max(0, filesize($pdf) - 200));
vero('il PDF ha la tavola degli scostamenti', str_contains($coda, 'startxref') && str_contains($coda, '%%EOF'));

$estratto = (new \Smalot\PdfParser\Parser())->parseFile($pdf)->getPages()[0]->getText();
$estratto = trim((string) preg_replace('~\s+~u', ' ', $estratto));
vero('il testo del PDF si estrae con le parole staccate', str_contains($estratto, 'Paragrafo con grassetto'));
vero('il PDF contiene il titolo', str_contains($estratto, 'Titolo primo'));

// ── L'RTF è sintatticamente sano ─────────────────────────────────────────────
$rtf = (string) file_get_contents(converti($sorgente, 'rtf', $tmp));
vero('l\'RTF comincia con {\\rtf1', str_starts_with($rtf, '{\rtf1'));
verifica(
    'le graffe dell\'RTF sono bilanciate',
    0,
    substr_count($rtf, '{') - substr_count($rtf, '}') - substr_count($rtf, '\{') + substr_count($rtf, '\}')
);
vero('l\'RTF dichiara la tavola dei font', str_contains($rtf, '\fonttbl'));

// ── Il giro completo: Markdown → docx → Markdown ─────────────────────────────
$ritorno = new Documento();
Formati::lettore($docx)->leggi($docx, $tmp . '/media2', $ritorno);

$prima = $documento->riepilogo();
$dopo  = $ritorno->riepilogo();
foreach ([Blocco::TITOLO, Blocco::ELENCO, Blocco::CITAZIONE, Blocco::CODICE, Blocco::TABELLA, Blocco::RIGA] as $tipo) {
    verifica("andata e ritorno, {$tipo}", $prima[$tipo] ?? 0, $dopo[$tipo] ?? 0);
}

// ── Il registro dei formati ──────────────────────────────────────────────────
verifica('cinque formati in ingresso', 5, count(Formati::formatiInUscita()));
vero('il Markdown è fra i formati in ingresso', in_array('md', Formati::estensioniInIngresso(), true));

// Un .doc si rifiuta con una spiegazione, non con un errore generico.
try {
    Formati::lettore($tmp . '/x.doc');
    vero('il .doc viene rifiutato', false);
} catch (RuntimeException $e) {
    vero('il .doc dice cosa fare', str_contains($e->getMessage(), '.docx'));
}
try {
    Formati::lettore($tmp . '/x.pptx');
    vero('un formato ignoto viene rifiutato', false);
} catch (RuntimeException $e) {
    vero('il formato ignoto viene nominato', str_contains($e->getMessage(), 'pptx'));
}

// ── La tipologia, dal registro ───────────────────────────────────────────────
$conversione = new ConversioneDocumenti();
$verificaFile = $conversione->verifica($sorgente);
vero('la tipologia accetta il Markdown', $verificaFile['ok']);

$risultato = $conversione->converti($sorgente, $tmp . '/finale.md', ['formato' => 'md']);
vero('la conversione rende dei blocchi', $risultato['righe_scritte'] > 0);
verifica('senza immagini non si impacchetta', 'md', $risultato['estensione']);

// ── Il vincolo di memoria vale anche qui ─────────────────────────────────────
if (function_exists('memory_reset_peak_usage')) {
    memory_reset_peak_usage();
}
$conversione->converti($sorgente, $tmp . '/mem.pdf', ['formato' => 'pdf']);
$picco = memory_get_peak_usage(true) / 1048576;
vero(sprintf('una conversione sta sotto i 20 MB (picco %.1f MB)', $picco), $picco < 20);

// ── Pulizia ──────────────────────────────────────────────────────────────────
foreach (glob($tmp . '/*') ?: [] as $file) {
    is_dir($file) ? array_map('unlink', glob($file . '/*') ?: []) && @rmdir($file) : @unlink($file);
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
