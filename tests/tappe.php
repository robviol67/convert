<?php
declare(strict_types=1);
/**
 * Verifiche della conversione a tappe: `php tests/tappe.php`.
 *
 * Una stampa lunga non sta nella memoria dell'hosting, quindi si legge a
 * pezzi, una richiesta per pezzo, con lo stato su disco fra l'una e l'altra.
 * Le cose da provare sono quelle che possono andare storte in silenzio:
 *
 * - un pezzo di PDF deve dire esattamente quello che diceva il documento;
 * - il risultato a tappe deve essere identico a quello in un colpo;
 * - una tappa uccisa a metà non deve lasciare righe doppie;
 * - due richieste insieme non devono fare la stessa tappa;
 * - una tappa che muore sempre deve arrendersi e dire perché.
 */

$tmp = sys_get_temp_dir() . '/vb-tappe-' . getmypid();
@mkdir($tmp, 0770, true);
putenv('CONVERT_DB=' . $tmp . '/prova.db');
putenv('CONVERT_LAVORO=' . $tmp . '/lavoro');
putenv('CONVERT_SENZA_SFONDO=1');

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/LettoreXlsx.php';
require __DIR__ . '/pdf_prenotazioni.php';

use Smalot\PdfParser\Parser as PdfParser;
use Vblite\Convert\Auth;
use Vblite\Convert\Config;
use Vblite\Convert\Conversioni\OctoScidoo\ArchivioRighe;
use Vblite\Convert\Conversioni\OctoScidoo\ConversioneOctoScidoo;
use Vblite\Convert\Database;
use Vblite\Convert\Job;
use Vblite\Convert\Supporto\SpezzaPdf;
use Vblite\Convert\Test\LettoreXlsx;

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

/** Testo e posizione di ogni pezzo di ogni pagina: se coincide, il PDF dice la stessa cosa. */
function impronte(string $pdf): array
{
    $impronte = [];
    foreach ((new PdfParser())->parseFile($pdf)->getPages() as $pagina) {
        $pezzi = [];
        foreach ($pagina->getDataTm() as $e) {
            $pezzi[] = sprintf('%.2f,%.2f,%s', $e[0][4], $e[0][5], $e[1]);
        }
        $impronte[] = md5(implode("\n", $pezzi));
    }

    return $impronte;
}

/** Tutte le celle di un foglio, per confrontare due file. */
function celle(string $xlsx): string
{
    $zip = new ZipArchive();
    $zip->open($xlsx);
    $foglio = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    preg_match('~<sheetData>.*</sheetData>~s', $foglio, $m);

    return md5($m[0] ?? '');
}

// ── Il PDF lungo ─────────────────────────────────────────────────────────────
// 130 pagine da sei prenotazioni: con 60 pagine a tappa fanno tre letture e
// una scrittura, abbastanza per provare il passaggio da una tappa all'altra.
$lungo = $tmp . '/lunga.pdf';
stampaPrenotazioni($lungo, 130);
$interoImpronte = impronte($lungo);
verifica('la stampa di prova ha 130 pagine', 130, count($interoImpronte));

// ── Spezzare ─────────────────────────────────────────────────────────────────
$spezza = SpezzaPdf::apri($lungo);
vero('il PDF si apre a pezzi', $spezza !== null);
verifica('e conta le pagine senza leggerle', 130, $spezza?->pagine());

$pezzo = $tmp . '/pezzo.pdf';
verifica('un pezzo dal mezzo prende le pagine chieste', 60, $spezza?->estrai(61, 60, $pezzo));
verifica('e dice esattamente quello che diceva il documento', array_slice($interoImpronte, 60, 60), impronte($pezzo));
vero('un pezzo è molto più piccolo del documento', filesize($pezzo) < filesize($lungo) / 1.5);
verifica('l\'ultimo pezzo si ferma alla fine', 10, $spezza?->estrai(121, 60, $pezzo));
verifica('oltre la fine non c\'è niente', 0, $spezza?->estrai(131, 60, $pezzo));
$spezza?->chiudi();

// La misura della pagina ereditata dall'albero: nel pezzo non c'è più chi la dia.
$ereditata = $tmp . '/ereditata.pdf';
stampaPrenotazioni($ereditata, 3, 6, true);
$spezza = SpezzaPdf::apri($ereditata);
$spezza?->estrai(2, 1, $pezzo);
$spezza?->chiudi();
vero('la misura ereditata viene copiata sulla pagina', str_contains((string) file_get_contents($pezzo), '/MediaBox [0 0 842 595]'));
verifica('e il pezzo si legge come l\'originale', array_slice(impronte($ereditata), 1, 1), impronte($pezzo));

// Quello che non si sa spezzare si dice, non si rompe.
$falso = $tmp . '/falso.pdf';
file_put_contents($falso, "%PDF-1.5\n1 0 obj\n<< /Type /XRef >>\nstream\nxx\nendstream\nendobj\nstartxref\n9\n%%EOF\n");
verifica('una xref compressa non si apre', null, SpezzaPdf::apri($falso));
file_put_contents($falso, 'non sono un PDF');
verifica('un file che non è un PDF nemmeno', null, SpezzaPdf::apri($falso));

// ── L'archivio delle righe ───────────────────────────────────────────────────
$archivio = new ArchivioRighe($tmp . '/righe.txt');
$riga = static fn(string $n, string $c): array => ['npren' => $n, 'cognome' => $c, 'nota' => "a capo\ndentro"];
$archivio->aggiungi($riga('4.256', 'ROSSI'));
$archivio->aggiungi($riga('999', 'BIANCHI'));
$archivio->aggiungi($riga('4.256', 'VERDI'));   // la stessa prenotazione, più avanti
$archivio->chiudiScrittura();
$sicuro = $archivio->lunghezza();
$archivio->aggiungi($riga('7.000', 'MEZZA'));   // una tappa morta a metà
$archivio->chiudiScrittura();
$archivio->tronca($sicuro);

$gruppi = [];
$lette = $archivio->perPrenotazione(static function (string $n, array $righe) use (&$gruppi): void {
    $gruppi[] = [$n, array_column($righe, 'cognome')];
});
verifica('le righe sparse si ritrovano, nell\'ordine di prima comparsa',
    [['4.256', ['ROSSI', 'VERDI']], ['999', ['BIANCHI']]], $gruppi);
verifica('le righe della tappa morta sono sparite', 3, $lette);
$archivio->elimina();

// ── A tappe come in un colpo ─────────────────────────────────────────────────
$conversione = new ConversioneOctoScidoo();
$unColpo = $tmp . '/uncolpo.xlsx';
$esitoUnColpo = $conversione->converti($lungo, $unColpo, ['formato' => 'xlsx']);
verifica('780 prenotazioni', 780, $esitoUnColpo['righe_scritte']);

/** Come sul server: ogni tappa riparte da uno stato riletto dal disco. */
function aTappe(ConversioneOctoScidoo $c, string $pdf, string $cartella, string $uscita, ?callable $prima = null): array
{
    @mkdir($cartella, 0770, true);
    $stato = json_decode(json_encode($c->prepara($pdf, $cartella, [])), true);
    $tappe = 0;
    while (empty($stato['fatto']) && $tappe < 200) {
        if ($prima !== null) {
            $stato = $prima($stato, $tappe, $cartella);
        }
        $stato = json_decode(json_encode($c->avanza($pdf, $cartella, $stato, [], $uscita)), true);
        $tappe++;
    }

    return [$stato, $tappe];
}

$aTappe = $tmp . '/atappe.xlsx';
[$stato, $tappe] = aTappe($conversione, $lungo, $tmp . '/c1', $aTappe);
verifica('tre letture e una scrittura', 4, $tappe);
verifica('a tappe escono le stesse prenotazioni', 780, $stato['risultato']['righe_scritte']);
verifica('e lo stesso foglio, cella per cella', celle($unColpo), celle($aTappe));
verifica('e le stesse anomalie',
    count($esitoUnColpo['anomalie']),
    count(ConversioneOctoScidoo::leggiAnomalie($stato['risultato']['anomalie_file'])));

// Una tappa uccisa a metà: righe scritte ma stato non aggiornato, e il
// tentativo contato. La tappa si rifà a pezzi più piccoli, senza doppioni.
$uccisa = $tmp . '/uccisa.xlsx';
[$stato, $tappe] = aTappe($conversione, $lungo, $tmp . '/c2', $uccisa, static function (array $stato, int $tappa, string $cartella): array {
    if ($tappa === 1) {
        // La tappa morta aveva già riscritto righe vere — qui le prime dieci
        // del file — e ne ha lasciata una a metà.
        $righe = file($cartella . '/righe.txt');
        file_put_contents($cartella . '/righe.txt', implode('', array_slice($righe, 0, 10)) . "6.10", FILE_APPEND);
        $stato['tentativi'] = 2;   // come lo lascia il Job dopo una morte
    }

    return $stato;
});
verifica('dopo una tappa uccisa il foglio è identico', celle($unColpo), celle($uccisa));
vero('e la tappa rifatta ha letto meno pagine', $tappe > 4);

// Una tappa da 60 pagine sta ben sotto il tetto dell'hosting.
@mkdir($tmp . '/c3', 0770, true);
$stato = $conversione->prepara($lungo, $tmp . '/c3', []);
gc_collect_cycles();
if (function_exists('memory_reset_peak_usage')) {
    memory_reset_peak_usage();
}
$prima = memory_get_usage();
$conversione->avanza($lungo, $tmp . '/c3', $stato, [], $tmp . '/c3.xlsx');
$tappaMb = (memory_get_peak_usage() - $prima) / 1048576;
vero(sprintf('una tappa di lettura costa poco (%.1f MB)', $tappaMb), $tappaMb < 8);

// ── Il job, come lo porta avanti la pagina di avanzamento ────────────────────
$pdo = Database::pdo();
Auth::creaUtente('tappe-' . getmypid() . '@esempio.test', 'Prova', str_repeat('x', 12));
$utente = (int) $pdo->query('SELECT MAX(id) FROM users')->fetchColumn();

$nati = [];
function caricata(string $origine): string
{
    global $nati;
    @mkdir(Config::cartellaIngresso(), 0770, true);
    $dest = Config::cartellaIngresso() . '/prova-tappe-' . getmypid() . '-' . count($nati) . '.pdf';
    copy($origine, $dest);
    $nati[] = $dest;

    return $dest;
}

$job = Job::crea($utente, 'octo_scidoo', caricata($lungo), 'lunga.pdf', ['formato' => 'xlsx']);
$id  = (int) $job['id'];
Job::avviaInBackground($id);
vero('senza processi in sfondo si prepara a tappe', is_file(Job::cartellaLavoro($job) . '/stato.json'));
vero('e la pagina deve restare aperta', Job::avanzaConLaPagina($id));
verifica('il job è in corso', Job::IN_CORSO, Job::trova($id)['esito']);

Job::avanza($id);
$dopoUna = Job::trova($id);
verifica('dopo una tappa si è in lettura', 'lettura', $dopoUna['passo']);
verifica('a pagina 60', 60, (int) $dopoUna['pagina_corrente']);
verifica('su 130', 130, (int) $dopoUna['pagine']);

// Un'altra richiesta tiene il lucchetto: questa non deve fare niente.
$lucchetto = fopen(Job::cartellaLavoro($job) . '/lucchetto', 'c');
flock($lucchetto, LOCK_EX);
Job::avanza($id);
verifica('col lucchetto preso non si avanza', 60, (int) Job::trova($id)['pagina_corrente']);
flock($lucchetto, LOCK_UN);
fclose($lucchetto);

$giri = 0;
while (Job::trova($id)['esito'] === Job::IN_CORSO && $giri++ < 50) {
    Job::avanza($id);
}
$finito = Job::trova($id);
vero('il job arriva in fondo', in_array($finito['esito'], [Job::COMPLETATA, Job::DA_RIVEDERE], true));
verifica('con tutte le prenotazioni', 780, (int) $finito['righe_scritte']);
verifica('il foglio è quello giusto', celle($unColpo), celle((string) $finito['file_out']));
vero('la cartella di lavoro è stata tolta', !is_dir(Job::cartellaLavoro($job)));

$informative = 0;
$correggi    = 0;
foreach ($esitoUnColpo['anomalie'] as $a) {
    $a['gravita'] === 'correggi' ? $correggi++ : $informative++;
}
verifica('le anomalie da correggere sono tutte nel database', $correggi, Job::contaAnomalie($id, 'correggi'));
verifica('e anche le informative', $informative, array_sum(Job::anomaliePerColonna($id, 'informativa')));
verifica('si sfogliano a pagine', min(5, $correggi), count(Job::anomalie($id, 'correggi', 5, 0)));

// Le correzioni ripartono a tappe, e il file vecchio sparisce solo alla fine.
$vecchio = (string) $finito['file_out'];
vero('le correzioni ripartono a tappe', Job::applicaCorrezioni($id));
vero('il file vecchio c\'è ancora mentre si lavora', is_file($vecchio));
$giri = 0;
while (Job::trova($id)['esito'] === Job::IN_CORSO && $giri++ < 50) {
    Job::avanza($id);
}
$rifatto = Job::trova($id);
verifica('la nuova versione è la 2', 2, (int) $rifatto['versione']);
vero('il file nuovo c\'è', is_file((string) $rifatto['file_out']));
vero('e il vecchio è stato tolto', !is_file($vecchio));

// Una tappa che muore sempre: ci si arrende, e si dice perché.
$ostinato = Job::crea($utente, 'octo_scidoo', caricata($lungo), 'lunga.pdf', []);
Job::avviaInBackground((int) $ostinato['id']);
$fileStato = Job::cartellaLavoro($ostinato) . '/stato.json';
$statoOstinato = json_decode((string) file_get_contents($fileStato), true);
$statoOstinato['tentativi'] = 4;
file_put_contents($fileStato, json_encode($statoOstinato));
Job::avanza((int) $ostinato['id']);
$arreso = Job::trova((int) $ostinato['id']);
verifica('dopo troppe morti il job si ferma', Job::ERRORE, $arreso['esito']);
vero('e dice che è il server a fermarlo', str_contains((string) $arreso['errore'], 'il server la ferma'));
vero('e il lavoro a metà è stato tolto', !is_dir(Job::cartellaLavoro($ostinato)));

// Annullare butta il lavoro fatto.
$annullato = Job::crea($utente, 'octo_scidoo', caricata($lungo), 'lunga.pdf', []);
Job::avviaInBackground((int) $annullato['id']);
Job::avanza((int) $annullato['id']);
Job::annulla((int) $annullato['id']);
verifica('annullato è un errore', Job::ERRORE, Job::trova((int) $annullato['id'])['esito']);
vero('e la cartella non c\'è più', !is_dir(Job::cartellaLavoro($annullato)));
Job::avanza((int) $annullato['id']);
vero('e un giro in più non la ricrea', !is_dir(Job::cartellaLavoro($annullato)));

// Eliminare un job a metà toglie anche il suo lavoro.
$aMeta = Job::crea($utente, 'octo_scidoo', caricata($lungo), 'lunga.pdf', []);
Job::avviaInBackground((int) $aMeta['id']);
Job::avanza((int) $aMeta['id']);
Job::elimina((int) $aMeta['id']);
vero('eliminare toglie il lavoro a metà', !is_dir(Job::cartellaLavoro($aMeta)));

// ── Pulizia ──────────────────────────────────────────────────────────────────
Job::svuota($utente);
foreach ($nati as $file) {
    @unlink($file);
}
$via = static function (string $cartella) use (&$via): void {
    foreach (glob($cartella . '/{,.}[!.]*', GLOB_BRACE) ?: [] as $voce) {
        is_dir($voce) ? $via($voce) : @unlink($voce);
    }
    @rmdir($cartella);
};
$via($tmp);

echo "\n";
if ($falliti === []) {
    echo "OK — {$passati} verifiche passate.\n";
    exit(0);
}
echo 'FALLITE ' . count($falliti) . ' su ' . ($passati + count($falliti)) . ":\n\n";
echo implode("\n\n", $falliti) . "\n";
exit(1);
