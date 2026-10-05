<?php
declare(strict_types=1);
/**
 * Verifiche del tracciato «Stampa prenotazioni»: `php tests/prenotazioni.php`.
 *
 * Octorate stampa due elenchi che portano gli stessi dati con colonne diverse,
 * e la tipologia li accetta tutti e due riconoscendoli dal titolo. Qui si prova
 * il secondo — quello con una riga per prenotazione — su un PDF costruito a
 * mano con la geometria di quello vero: colonne alle stesse coordinate, record
 * su cinque righe logiche, testata ripetuta a ogni pagina.
 *
 * Costruirlo invece di allegarlo serve a una cosa sola: che questa prova giri
 * anche dove i file del committente non ci sono.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/LettoreXlsx.php';

use Vblite\Convert\Conversioni\OctoPrenotazioni\ConversionePrenotazioni;
use Vblite\Convert\Conversioni\OctoScidoo\ConversioneOctoScidoo;
use Vblite\Convert\Conversioni\OctoScidoo\Parser;
use Vblite\Convert\Conversioni\OctoScidoo\Tracciato;
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

$tmp = sys_get_temp_dir() . '/vb-pren-' . getmypid();
@mkdir($tmp, 0770, true);

require __DIR__ . '/pdf_prenotazioni.php';

// ── Il PDF di prova ──────────────────────────────────────────────────────────
$flussi = [];
for ($p = 0; $p < 2; $p++) {
    $record = [];
    for ($i = 1; $i <= 6; $i++) {
        $record[] = record($p * 6 + $i);
    }
    $flussi[] = paginaPrenotazioni($record, $p + 1);
}
$pdf = $tmp . '/stampa-prenotazioni.pdf';
pdfConFlussi($pdf, $flussi);
vero('il PDF di prova è stato scritto', is_file($pdf) && filesize($pdf) > 2000);

// ── Riconoscimento ───────────────────────────────────────────────────────────
$conversione = new ConversionePrenotazioni();
$v = $conversione->verifica($pdf);
vero('la stampa prenotazioni viene accettata', $v['ok']);
verifica('e viene detto quale stampa è', 'Stampa prenotazioni', $v['intestazione']['stampa'] ?? '');
verifica('due pagine', 2, $v['pagine']);

// Una stampa che non conosciamo viene rifiutata, dicendo quali si accettano.
$altra = $tmp . '/altra.pdf';
pdfConFlussi($altra, [t(120, 553, 'Stampa movimenti di cassa', 9) . t(40, 441, '1.000')]);
$vAltra = $conversione->verifica($altra);
verifica('una stampa sconosciuta viene rifiutata', false, $vAltra['ok']);
vero('e il motivo nomina la stampa clienti', str_contains((string) $vAltra['motivo'], 'Stampa clienti presenti'));
vero('e anche la stampa prenotazioni', str_contains((string) $vAltra['motivo'], 'Stampa prenotazioni'));

// ── Estrazione ───────────────────────────────────────────────────────────────
$estratto = (new Parser())->estrai($pdf);
verifica('dodici prenotazioni, sei per pagina', 12, count($estratto['righe']));
verifica('tutte con un numero diverso', 12, count(array_unique(array_column($estratto['righe'], 'npren'))));

$primo = $estratto['righe'][0];
verifica('numero di prenotazione', '6.101', $primo['npren']);
verifica('cognome', 'ROSSI1', $primo['cognome']);
verifica('nome', 'mario', $primo['nome']);
verifica('telefono', '+39 333 1234567', $primo['telefono']);
verifica('e-mail', 'mario1@esempio.it', $primo['email']);
verifica('data di inserimento', '02/01/26', $primo['data']);
verifica('ora di inserimento', '09:15:00', $primo['ora']);
verifica('arrivo', '02/06/2026', $primo['arrivo']);
verifica('partenza', '04/06/2026', $primo['partenza']);
verifica('camera', '11', $primo['camera']);
verifica('adulti dalla colonna A', '2', $primo['adulti']);
verifica('trattamento', 'Mezza Pensione', $primo['trattamento']);
verifica('tipo camera', 'Matrim - Doppia', $primo['tipo_camera']);
verifica('importo col separatore delle migliaia ricucito', '1.250,50', $primo['importo']);
verifica('supplementi', 'Letto agg. Bambino', $primo['supplementi']);
verifica('note interne', 'arriva tardi', $primo['commenti']);
verifica('caparra', '300,00', $primo['caparre']);

$terzo = $estratto['righe'][2];
verifica('bambini dalla colonna B', '1', $terzo['bambini']);
verifica('neonati dalla colonna I', '', $terzo['neonati']);
$quinto = $estratto['righe'][4];
verifica('neonati dove ci sono', '1', $quinto['neonati']);
$secondo = $estratto['righe'][1];
verifica('agenzia pagante', 'BOOKING COM', $secondo['agenzia_pagante']);
$quarto = $estratto['righe'][3];
verifica('data opzione', '20/05/2026', $quarto['data_opzione']);

// ── Il foglio Scidoo ─────────────────────────────────────────────────────────
$uscita = $tmp . '/import.xlsx';
$esito  = $conversione->converti($pdf, $uscita, ['formato' => 'xlsx']);
verifica('dodici righe scritte', 12, $esito['righe_scritte']);

$foglio = new LettoreXlsx($uscita);
verifica('ID', '6101', $foglio->valore('B2'));
verifica('Nome Cliente', 'mario', $foglio->valore('C2'));
verifica('Cognome Cliente', 'ROSSI1', $foglio->valore('D2'));
verifica('Camera', '11', $foglio->valore('G2'));
// Il Tipo camera della stampa è testo libero, non una categoria di Scidoo:
// entra solo se lo si chiede.
verifica('Categoria Camera resta vuota', null, $foglio->valore('H2'));
verifica('Adulti', '2', $foglio->valore('O2'));
verifica('Bambini', '0', $foglio->valore('P2'));
verifica('Retta dal trattamento', 'Mezza Pensione', $foglio->valore('S2'));
verifica('Prezzo Retta con le migliaia', '1250.5', $foglio->valore('T2'));

$conCategoria = $tmp . '/con-categoria.xlsx';
$conversione->converti($pdf, $conCategoria, ['formato' => 'xlsx', 'categoria_da_tipo_camera' => true]);
verifica(
    'chiedendola, arriva dal Tipo camera',
    'Matrim - Doppia',
    (new LettoreXlsx($conCategoria))->valore('H2')
);

// Le fasce dichiarate si leggono, non si deducono: nessuna segnalazione.
$daRivedere = array_filter($esito['anomalie'], static fn(array $a): bool => $a['gravita'] === 'correggi');
$suFasce = array_filter($daRivedere, static fn(array $a): bool => str_contains($a['colonna'], 'Adulti'));
verifica('nessuna incertezza sulle fasce d\'età', [], array_values($suFasce));

// La terza prenotazione ha un bambino dichiarato: dev'essere nel foglio.
verifica('il bambino dichiarato arriva nel foglio', '1', $foglio->valore('P4'));

// ── La stessa stampa, con l'intestazione scritta tutta intera ────────────────
// Da una struttura all'altra Octorate scrive «N°pren.» in un pezzo solo invece
// che in tre: è la stessa stampa e va letta uguale.
$intero = $tmp . '/numero-intero.pdf';
stampaPrenotazioni($intero, 2, 6, false, true);
$vIntero = $conversione->verifica($intero);
vero('anche con «N°pren.» intero la stampa è accettata', $vIntero['ok']);
verifica('ed è sempre la stessa stampa', 'Stampa prenotazioni', $vIntero['intestazione']['stampa'] ?? '');
$estrattoIntero = (new Parser())->estrai($intero);
verifica('con le stesse righe', count($estratto['righe']), count($estrattoIntero['righe']));
verifica('e gli stessi numeri di prenotazione',
    array_column($estratto['righe'], 'npren'),
    array_column($estrattoIntero['righe'], 'npren'));
verifica('e lo stesso primo cliente', $primo['cognome'], $estrattoIntero['righe'][0]['cognome']);

// ── Due tipologie, due stampe: ognuna accetta la sua ─────────────────────────
// Le due stampe portano gli stessi dati con colonne diverse, e scambiarle
// darebbe un file sbagliato: ciascuna tipologia prende solo la sua, e dice
// dov'è l'altra.
$clienti = new ConversioneOctoScidoo();
verifica('le due tipologie dichiarano stampe diverse',
    ['clienti_presenti', 'prenotazioni'],
    [$clienti->tracciatoAccettato(), $conversione->tracciatoAccettato()]);

$scambiata = $clienti->verifica($pdf);
verifica('la stampa prenotazioni non entra nella tipologia dei clienti', false, $scambiata['ok']);
vero('e le viene detto quale stampa ha in mano', str_contains((string) $scambiata['motivo'], 'Stampa prenotazioni'));
vero('e dove portarla', str_contains((string) $scambiata['motivo'], $conversione->manifest()['titolo']));

$vecchia = __DIR__ . '/../../handoff_octo_scidoo/esempi/prenotazioni 2024 2025.pdf';
if (is_file($vecchia)) {
    $vVecchia = $clienti->verifica($vecchia);
    vero('la stampa clienti presenti è accettata dalla sua tipologia', $vVecchia['ok']);
    verifica('e riconosciuta come tale', 'Stampa clienti presenti', $vVecchia['intestazione']['stampa'] ?? '');

    $alContrario = $conversione->verifica($vecchia);
    verifica('e non entra in quella delle prenotazioni', false, $alContrario['ok']);
    vero('con l\'indicazione della tipologia giusta',
        str_contains((string) $alContrario['motivo'], $clienti->manifest()['titolo']));
} else {
    echo "Stampa clienti di esempio assente: saltato il confronto fra i due tracciati.\n";
}

// ── Il registro dei tracciati ────────────────────────────────────────────────
verifica('i tracciati sono due', 2, count(Tracciato::tutti()));
vero('e i nomi finiscono nei messaggi', str_contains(Tracciato::nomi(), 'Stampa prenotazioni'));

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
