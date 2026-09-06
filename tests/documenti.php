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

// ── EPUB ─────────────────────────────────────────────────────────────────────
// Il documento di prova ha due titoli: devono uscire due capitoli.
$epub = $tmp . '/libro.epub';
$documentoEpub = new Documento();
$scrittoreEpub = Formati::scrittore('epub', [
    'epub_titolo' => 'Titolo del libro',
    'epub_autore' => 'Chi lo ha scritto',
    'epub_lingua' => 'it',
    'epub_taglio' => '1',
]);
$scrittoreEpub->apri($epub, $documentoEpub);
$documentoEpub->consuma(static fn(Blocco $b) => $scrittoreEpub->blocco($b));
Formati::lettore($sorgente)->leggi($sorgente, $tmp . '/media-epub', $documentoEpub);
$scrittoreEpub->chiudi();

$ze = new ZipArchive();
verifica('l\'EPUB si apre come zip', true, $ze->open($epub) === true);

// Il mimetype deve essere il primo file e non compresso: è così che un lettore
// riconosce un EPUB senza aprirlo tutto.
$primo = $ze->statIndex(0);
verifica('mimetype è il primo file', 'mimetype', $primo['name']);
verifica('mimetype non è compresso', ZipArchive::CM_STORE, $primo['comp_method']);
verifica('mimetype dice cosa è', 'application/epub+zip', $ze->getFromName('mimetype'));

foreach (['META-INF/container.xml', 'OEBPS/content.opf', 'OEBPS/nav.xhtml', 'OEBPS/toc.ncx'] as $parte) {
    vero("l'EPUB contiene {$parte}", $ze->locateName($parte) !== false);
}

$opf = (string) $ze->getFromName('OEBPS/content.opf');
vero('l\'OPF porta il titolo scelto', str_contains($opf, '<dc:title>Titolo del libro</dc:title>'));
vero('l\'OPF porta l\'autore', str_contains($opf, '<dc:creator>Chi lo ha scritto</dc:creator>'));
vero('l\'OPF porta la lingua', str_contains($opf, '<dc:language>it</dc:language>'));
vero('l\'OPF dichiara la data di modifica, che EPUB 3 pretende', str_contains($opf, 'dcterms:modified'));

// Ogni file dichiarato nel manifest deve esistere davvero, e ogni voce dello
// spine deve puntare a un id del manifest: sono i due modi più comuni di
// produrre un EPUB che si apre a metà.
preg_match_all('~<item\b[^>]*id="([^"]+)"[^>]*href="([^"]+)"~', $opf, $voci, PREG_SET_ORDER);
$mancanti = [];
$idNoti   = [];
foreach ($voci as $voce) {
    $idNoti[$voce[1]] = true;
    if ($ze->locateName('OEBPS/' . $voce[2]) === false) {
        $mancanti[] = $voce[2];
    }
}
verifica('ogni file del manifest esiste nello zip', [], $mancanti);

preg_match_all('~<itemref\b[^>]*idref="([^"]+)"~', $opf, $riferimenti);
$orfani = array_values(array_filter($riferimenti[1], static fn(string $id): bool => !isset($idNoti[$id])));
verifica('ogni voce dello spine punta al manifest', [], $orfani);

// Tutte le parti XML devono essere ben formate: l'XHTML non perdona.
$malformate = [];
for ($i = 0; $i < $ze->numFiles; $i++) {
    $nome = (string) $ze->getNameIndex($i);
    if (preg_match('~\.(xhtml|opf|ncx|xml)$~', $nome) === 1
        && @simplexml_load_string((string) $ze->getFromName($nome)) === false) {
        $malformate[] = $nome;
    }
}
verifica('tutte le parti XML sono ben formate', [], $malformate);

$capitoli = 0;
for ($i = 0; $i < $ze->numFiles; $i++) {
    $capitoli += str_starts_with((string) $ze->getNameIndex($i), 'OEBPS/testo/capitolo-') ? 1 : 0;
}
// Il documento di prova ha UN titolo di primo livello e uno di secondo:
// tagliando al primo livello esce un capitolo solo.
verifica('un titolo di primo livello dà un capitolo', 1, $capitoli);

$nav = (string) $ze->getFromName('OEBPS/nav.xhtml');
$ze->close();

// Tagliando anche al secondo livello i capitoli diventano due: è la scelta
// offerta nello step 2, e deve cambiare davvero il risultato.
$epub2 = $tmp . '/libro2.epub';
$doc2  = new Documento();
$scr2  = Formati::scrittore('epub', ['epub_taglio' => '2']);
$scr2->apri($epub2, $doc2);
$doc2->consuma(static fn(Blocco $b) => $scr2->blocco($b));
Formati::lettore($sorgente)->leggi($sorgente, $tmp . '/media-epub3', $doc2);
$scr2->chiudi();

$ze2 = new ZipArchive();
$ze2->open($epub2);
$capitoli2 = 0;
for ($i = 0; $i < $ze2->numFiles; $i++) {
    $capitoli2 += str_starts_with((string) $ze2->getNameIndex($i), 'OEBPS/testo/capitolo-') ? 1 : 0;
}
$ze2->close();
verifica('tagliando anche al secondo livello i capitoli diventano due', 2, $capitoli2);

$ze->open($epub);
vero('l\'indice è marcato come tale', str_contains($nav, 'epub:type="toc"'));
verifica('l\'indice ha una voce per titolo', 2, substr_count($nav, '<li><a href='));
vero('le voci dell\'indice puntano a un\'ancora', str_contains($nav, '.xhtml#t'));

vero('senza immagini la copertina è tipografica',
    str_contains((string) $ze->getFromName('OEBPS/testo/copertina.xhtml'), 'Titolo del libro'));
$ze->close();

// L'EPUB si rilegge, e ritrova quello che c'era.
$ritornoEpub = new Documento();
Formati::lettore($epub)->leggi($epub, $tmp . '/media-epub2', $ritornoEpub);
vero('l\'EPUB riletto ritrova i titoli', ($ritornoEpub->riepilogo()[Blocco::TITOLO] ?? 0) >= 2);
vero('l\'EPUB riletto ritrova gli elenchi', ($ritornoEpub->riepilogo()[Blocco::ELENCO] ?? 0) >= 5);
vero('l\'EPUB riletto ritrova la tabella', ($ritornoEpub->riepilogo()[Blocco::TABELLA] ?? 0) >= 1);

// ── HTML ─────────────────────────────────────────────────────────────────────
$html = $tmp . '/pagina.html';
file_put_contents($html, '<!DOCTYPE html><html><head><meta charset="utf-8">'
    . '<style>p{color:red}</style><title>Testata</title></head><body>'
    . '<h1>Titolo</h1>'
    . '<p>Testo con <strong>grassetto</strong>, <em>corsivo</em>, <code>codice</code> '
    . 'e <a href="https://vblite.com">un link</a>.</p>'
    . '<p>Entit&agrave;: &lt;tag&gt; &amp; &quot;virgolette&quot;.</p>'
    . '<ul><li>uno</li><li>due</li></ul>'
    . '<table><tr><th>A</th><th>B</th></tr><tr><td>x</td><td>y</td></tr></table>'
    . '<script>var non = "deve comparire";</script>'
    . '</body></html>');

$daHtml = new Documento();
Formati::lettore($html)->leggi($html, $tmp . '/media-html', $daHtml);
$blocchiHtml = $daHtml->blocchi();

verifica('l\'HTML dà due titoli? no: uno', 1, $daHtml->riepilogo()[Blocco::TITOLO] ?? 0);
verifica('l\'HTML dà due voci di elenco', 2, $daHtml->riepilogo()[Blocco::ELENCO] ?? 0);
verifica('l\'HTML dà una tabella', 1, $daHtml->riepilogo()[Blocco::TABELLA] ?? 0);

$tuttoIlTesto = '';
foreach ($blocchiHtml as $b) {
    $tuttoIlTesto .= ' ' . $b->nudo();
}
vero('il contenuto di <script> non entra nel documento', !str_contains($tuttoIlTesto, 'deve comparire'));
vero('il contenuto di <style> non entra nel documento', !str_contains($tuttoIlTesto, 'color:red'));
vero('il titolo della testata non diventa testo', !str_contains($tuttoIlTesto, 'Testata'));
vero('le entità si sciolgono', str_contains($tuttoIlTesto, 'Entità: <tag> & "virgolette".'));

// I tratti in linea devono sopravvivere, non solo il testo.
$conTratti = null;
foreach ($blocchiHtml as $b) {
    if (str_contains($b->nudo(), 'grassetto')) {
        $conTratti = $b;
    }
}
vero('il paragrafo con i tratti esiste', $conTratti !== null);
if ($conTratti !== null) {
    $grassetti = $corsivi = $codici = $collegamenti = 0;
    foreach ($conTratti->testi as $t) {
        $grassetti    += $t->grassetto ? 1 : 0;
        $corsivi      += $t->corsivo ? 1 : 0;
        $codici       += $t->codice ? 1 : 0;
        $collegamenti += $t->collegamento !== null ? 1 : 0;
    }
    verifica('il grassetto sopravvive', 1, $grassetti);
    verifica('il corsivo sopravvive', 1, $corsivi);
    verifica('il codice in linea sopravvive', 1, $codici);
    verifica('il collegamento sopravvive', 1, $collegamenti);
}

// ── Il registro dei formati ──────────────────────────────────────────────────
verifica('sei formati in uscita', 6, count(Formati::formatiInUscita()));
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

// ── Anteprima del risultato ──────────────────────────────────────────────────
$conAnteprima = $conversione->converti($sorgente, $tmp . '/ap.docx', ['formato' => 'docx']);
$html = $conAnteprima['anteprima']['html'] ?? '';

vero('l\'anteprima rende i titoli', str_contains($html, '<h1 class="ap-t1">Titolo primo</h1>'));
vero('l\'anteprima rende il grassetto', str_contains($html, '<strong>grassetto</strong>'));
vero('l\'anteprima rende gli elenchi come liste', str_contains($html, '<ul><li>primo</li>'));
vero('l\'anteprima rende le tabelle', str_contains($html, '<table class="table"><thead>'));
vero('l\'anteprima rende le citazioni', str_contains($html, '<blockquote>'));
verifica('un documento corto non è parziale', false, $conAnteprima['anteprima']['parziale']);

// Il testo arriva da un file caricato da qualcuno: nell'anteprima non deve
// poter diventare marcatura. È la verifica che conta più di tutte le altre.
$cattivo = $tmp . '/cattivo.md';
file_put_contents(
    $cattivo,
    '# <script>alert(1)</script>' . PHP_EOL . PHP_EOL
    . 'Un paragrafo con <img src=x onerror=alert(2)> e "virgolette".' . PHP_EOL
);
$conCattivo = $conversione->converti($cattivo, $tmp . '/cattivo.md.docx', ['formato' => 'docx']);
$htmlCattivo = $conCattivo['anteprima']['html'] ?? '';

// La prova giusta non è cercare le parole pericolose — «onerror» compare
// eccome, ma dentro «&lt;img … &gt;», dove è testo inerte. La prova è che gli
// UNICI tag presenti siano quelli che emettiamo noi: se ne spunta uno che non
// è nella lista, vuol dire che il contenuto del file è diventato marcatura.
$nostri = ['h1','h2','h3','h4','h5','h6','p','ul','ol','li','blockquote','pre','code',
           'strong','em','hr','table','thead','tbody','tr','th','td','span'];
preg_match_all('~</?([a-zA-Z][a-zA-Z0-9]*)~', $htmlCattivo, $trovati);
$intrusi = array_values(array_unique(array_diff(array_map('strtolower', $trovati[1]), $nostri)));
verifica('nessun tag estraneo nell\'anteprima', [], $intrusi);

vero('il testo pericoloso resta visibile, ma protetto', str_contains($htmlCattivo, '&lt;script&gt;'));
vero('le virgolette sono protette', str_contains($htmlCattivo, '&quot;'));

// Un documento lungo si taglia, e lo dice.
$lungo = $tmp . '/lungo.md';
file_put_contents($lungo, str_repeat("Paragrafo di riempimento.

", 80));
$conLungo = $conversione->converti($lungo, $tmp . '/lungo.md.md', ['formato' => 'md']);
verifica('un documento lungo è dichiarato parziale', true, $conLungo['anteprima']['parziale']);
vero('l\'anteprima non contiene tutti gli 80 paragrafi', substr_count((string) $conLungo['anteprima']['html'], '<p>') < 80);

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
