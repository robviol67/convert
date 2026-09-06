<?php
declare(strict_types=1);
/**
 * Verifiche della tipologia Trascrizioni → documento: `php tests/trascrizioni.php`.
 *
 * Le prove stanno tutte attorno a una cosa sola: da un elenco di battute
 * spezzate deve uscire un testo che si legge. I casi che contano sono i
 * sottotitoli automatici — nessuna punteggiatura e ogni riga ripetuta due
 * volte — perché sono il caso peggiore e insieme il più comune.
 */

require __DIR__ . '/../vendor/autoload.php';

use Vblite\Convert\Conversioni\Documenti\Blocco;
use Vblite\Convert\Conversioni\Documenti\Documento;
use Vblite\Convert\Conversioni\Trascrizioni\ConversioneTrascrizione;
use Vblite\Convert\Conversioni\Trascrizioni\LettoreTrascrizione;

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

$tmp = sys_get_temp_dir() . '/vb-trascr-' . getmypid();
@mkdir($tmp, 0770, true);

function scrivi(string $nome, string $contenuto): string
{
    global $tmp;
    $percorso = $tmp . '/' . $nome;
    file_put_contents($percorso, $contenuto);

    return $percorso;
}

/** @return list<Blocco> */
function leggi(string $percorso, array $regole = []): array
{
    $lettore = new LettoreTrascrizione();
    $lettore->configura($regole);
    $documento = new Documento();
    $lettore->leggi($percorso, '', $documento);

    return $documento->blocchi();
}

function testi(array $blocchi): array
{
    return array_map(static fn(Blocco $b): string => $b->nudo(), $blocchi);
}

// ── SRT: il formato più diffuso ──────────────────────────────────────────────
$srt = scrivi('base.srt', <<<SRT
1
00:00:01,000 --> 00:00:03,500
Buongiorno a tutti e benvenuti

2
00:00:03,500 --> 00:00:06,000
a questa presentazione.

3
00:00:06,200 --> 00:00:09,000
Oggi parliamo di conversione dati.

SRT);

$blocchi = leggi($srt);
verifica('un solo paragrafo: le battute si ricuciscono', 1, count($blocchi));
verifica(
    'le battute diventano un periodo continuo',
    'Buongiorno a tutti e benvenuti a questa presentazione. Oggi parliamo di conversione dati.',
    $blocchi[0]->nudo()
);
verifica('è un paragrafo, non altro', Blocco::PARAGRAFO, $blocchi[0]->tipo);

// I numeri di battuta non devono finire nel testo.
vero('il numero di battuta non entra nel testo', !str_contains($blocchi[0]->nudo(), '1 00:00'));

// ── VTT automatico: ripetizione a scorrimento e karaoke ──────────────────────
$vtt = scrivi('auto.vtt', <<<VTT
WEBVTT
Kind: captions
Language: it

00:00:00.000 --> 00:00:02.000
allora oggi vediamo

00:00:02.000 --> 00:00:04.500
allora oggi vediamo
come si converte un file

00:00:04.500 --> 00:00:07.000
come si converte un file
senza perdere niente

VTT);

$blocchi = leggi($vtt);
verifica(
    'la ripetizione a scorrimento viene tolta',
    'allora oggi vediamo come si converte un file senza perdere niente',
    $blocchi[0]->nudo()
);

$karaoke = scrivi('karaoke.vtt', <<<VTT
WEBVTT

00:00:00.000 --> 00:00:02.000
<00:00:00.240><c>questa</c> <00:00:00.680><c>è</c> <00:00:01.100><c>una</c> prova

VTT);
verifica('i marcatori del karaoke spariscono', 'questa è una prova', leggi($karaoke)[0]->nudo());

// ── SBV, il formato di YouTube Studio ────────────────────────────────────────
$sbv = scrivi('studio.sbv', <<<SBV
0:00:01.000,0:00:03.000
Prima riga della trascrizione

0:00:03.000,0:00:05.000
e seconda riga.

SBV);
verifica('SBV: i due tempi separati da virgola', 'Prima riga della trascrizione e seconda riga.', leggi($sbv)[0]->nudo());

// ── Il testo incollato dal pannello di YouTube ───────────────────────────────
// Là il tempo sta da solo su una riga sua, e non c'è nessun intervallo.
$incollato = scrivi('incollato.txt', <<<TXT
0:00
Benvenuti in questo video

0:04
dove vediamo una cosa utile.

1:23
E qui siamo più avanti.
TXT);

$blocchi = leggi($incollato);
verifica(
    'il testo incollato si legge come gli altri',
    'Benvenuti in questo video dove vediamo una cosa utile. E qui siamo più avanti.',
    $blocchi[0]->nudo()
);

// Coi tempi richiesti, il paragrafo comincia col suo marcatore.
$blocchi = leggi($incollato, ['tr_tempi' => 'paragrafo']);
vero('col marcatore, il paragrafo comincia col tempo', str_starts_with($blocchi[0]->nudo(), '[0:00] '));

// Un tempo sopra l'ora si scrive per esteso.
$lungo = scrivi('lungo.srt', "1\n01:02:03,000 --> 01:02:05,000\nQualcosa di detto tardi.\n");
verifica('sopra l\'ora il tempo ha le ore', '[1:02:03] Qualcosa di detto tardi.', leggi($lungo, ['tr_tempi' => 'paragrafo'])[0]->nudo());

// Il tempo sulla stessa riga del testo, come lo dà a volte il pannello.
$affiancato = scrivi('affiancato.txt', "0:07 prima cosa detta\n0:11 seconda cosa detta.\n");
verifica('tempo e testo sulla stessa riga', 'prima cosa detta seconda cosa detta.', leggi($affiancato)[0]->nudo());

// ── Chi parla ────────────────────────────────────────────────────────────────
$voci = scrivi('voci.vtt', <<<VTT
WEBVTT

00:00:01.000 --> 00:00:03.000
<v Anna>Buongiorno, comincio io.

00:00:03.000 --> 00:00:06.000
<v Bruno>Prego, ti ascolto volentieri.

VTT);
$blocchi = leggi($voci);
verifica('due voci, due paragrafi', 2, count($blocchi));
vero('il nome apre il paragrafo', str_starts_with($blocchi[0]->nudo(), 'Anna: '));
vero('e il nome è in grassetto', $blocchi[0]->testi[0]->grassetto);
vero('la seconda voce è l\'altra', str_starts_with($blocchi[1]->nudo(), 'Bruno: '));

$televisivo = scrivi('tv.srt', <<<SRT
1
00:00:01,000 --> 00:00:03,000
>> CONDUTTORE: Buonasera a tutti.

2
00:00:03,000 --> 00:00:06,000
>> OSPITE: Buonasera a lei.

SRT);
$blocchi = leggi($televisivo);
verifica('la notazione televisiva dà due interventi', 2, count($blocchi));
verifica('primo intervento', 'CONDUTTORE: Buonasera a tutti.', $blocchi[0]->nudo());

// Una frase qualunque coi due punti non è un parlante.
$falso = scrivi('falso.srt', "1\n00:00:01,000 --> 00:00:03,000\nQuesto è il punto: non è un nome proprio.\n");
verifica('una frase coi due punti non diventa un parlante', 'Questo è il punto: non è un nome proprio.', leggi($falso)[0]->nudo());

// ── Rumori di scena ──────────────────────────────────────────────────────────
$rumori = scrivi('rumori.srt', <<<SRT
1
00:00:01,000 --> 00:00:03,000
[Musica] Benvenuti [Applausi] al programma.

SRT);
verifica('musica e applausi spariscono', 'Benvenuti al programma.', leggi($rumori)[0]->nudo());
verifica('togliendo la spunta restano', '[Musica] Benvenuti [Applausi] al programma.', leggi($rumori, ['tr_pulisci' => false])[0]->nudo());

// ── Senza punteggiatura il paragrafo si chiude sulla lunghezza ───────────────
// È il caso dei sottotitoli automatici: un'ora di parlato senza un punto.
$parole = [];
for ($i = 1; $i <= 300; $i++) {
    $parole[] = 'parola' . $i;
}
$fiume = "WEBVTT\n\n";
foreach (array_chunk($parole, 6) as $n => $gruppo) {
    $fiume .= sprintf("00:%02d:%02d.000 --> 00:%02d:%02d.000\n%s\n\n", intdiv($n * 3, 60), ($n * 3) % 60, intdiv($n * 3 + 3, 60), ($n * 3 + 3) % 60, implode(' ', $gruppo));
}
$blocchi = leggi(scrivi('fiume.vtt', $fiume));
vero('senza punti fermi escono comunque più paragrafi', count($blocchi) >= 2);
$piuLungo = max(array_map(static fn(Blocco $b): int => count(explode(' ', $b->nudo())), $blocchi));
vero(sprintf('nessun paragrafo è un fiume (il più lungo: %d parole)', $piuLungo), $piuLungo <= 130);
verifica('nessuna parola persa per strada', 300, array_sum(array_map(
    static fn(Blocco $b): int => count(explode(' ', $b->nudo())),
    $blocchi
)));

// ── I modi di raggruppare ────────────────────────────────────────────────────
$blocchi = leggi($srt, ['tr_raggruppa' => 'battuta']);
verifica('una riga per battuta: tre battute, tre blocchi', 3, count($blocchi));
verifica('la prima battuta resta com\'era', 'Buongiorno a tutti e benvenuti', $blocchi[0]->nudo());

$blocchi = leggi($voci, ['tr_raggruppa' => 'parlante']);
verifica('un paragrafo per intervento', 2, count($blocchi));

// ── La conversione intera ────────────────────────────────────────────────────
$conversione = new ConversioneTrascrizione();

$v = $conversione->verifica($srt);
vero('un .srt viene accettato', $v['ok']);
verifica('e dice che formato è', 'SRT', $v['intestazione']['formato']);

$v = $conversione->verifica(scrivi('vuoto.srt', "\n\n"));
verifica('un file vuoto viene rifiutato', false, $v['ok']);

$v = $conversione->verifica(scrivi('altro.docx', 'x'));
verifica('un formato che non è una trascrizione viene rifiutato', false, $v['ok']);
vero('e viene detto quali si accettano', str_contains((string) $v['motivo'], '.srt'));

$analisi = $conversione->analizza($srt);
vero('il sommario dice quante parole', str_contains($analisi['sommario'], 'parole'));
vero('e quanto ci vuole a leggerlo', str_contains($analisi['sommario'], 'lettura'));

$md = $tmp . '/uscita.md';
$esito = $conversione->converti($srt, $md, ['formato' => 'md']);
vero('il Markdown è stato scritto', is_file($md) && filesize($md) > 0);
verifica('un paragrafo scritto', 1, $esito['righe_scritte']);
vero('il testo c\'è tutto', str_contains((string) file_get_contents($md), 'conversione dati.'));

$docx = $tmp . '/uscita.docx';
$conversione->converti($voci, $docx, ['formato' => 'docx']);
vero('anche il Word è stato scritto', is_file($docx) && filesize($docx) > 1000);
$zip = new ZipArchive();
vero('ed è uno zip valido, come vuole il formato', $zip->open($docx) === true);
$documentoXml = (string) $zip->getFromName('word/document.xml');
$zip->close();
vero('col nome di chi parla in grassetto', str_contains($documentoXml, '<w:b/>'));
vero('e col testo dentro', str_contains($documentoXml, 'ascolto volentieri'));

$txt = $tmp . '/uscita.txt';
$conversione->converti($incollato, $txt, ['formato' => 'txt', 'tr_tempi' => 'paragrafo']);
vero('il testo semplice tiene i marcatori', str_contains((string) file_get_contents($txt), '[0:00]'));

// L'avviso sul metodo dev'esserci sempre: i paragrafi sono ricostruiti.
$motivi = array_column($esito['anomalie'], 'motivo');
vero('viene detto che i paragrafi sono ricostruiti', array_reduce(
    $motivi,
    static fn(bool $t, string $m): bool => $t || str_contains($m, 'ricostruiti'),
    false
));

// ── Memoria ──────────────────────────────────────────────────────────────────
$tre_ore = "WEBVTT\n\n";
for ($n = 0; $n < 3600; $n++) {
    $tre_ore .= sprintf(
        "%02d:%02d:%02d.000 --> %02d:%02d:%02d.000\nquesta e' la battuta numero %d di una trascrizione lunga.\n\n",
        intdiv($n * 3, 3600), intdiv(($n * 3) % 3600, 60), ($n * 3) % 60,
        intdiv($n * 3 + 3, 3600), intdiv(($n * 3 + 3) % 3600, 60), ($n * 3 + 3) % 60,
        $n
    );
}
$lungo = scrivi('treore.vtt', $tre_ore);
if (function_exists('memory_reset_peak_usage')) {
    memory_reset_peak_usage();
}
$esito = $conversione->converti($lungo, $tmp . '/treore.md', ['formato' => 'md']);
$picco = memory_get_peak_usage(true) / 1048576;
vero('tre ore di parlato danno un documento vero', $esito['righe_scritte'] > 100);
vero(sprintf('e stanno sotto i 20 MB (picco %.1f MB)', $picco), $picco < 20);

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
