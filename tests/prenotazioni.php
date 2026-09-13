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

/** Un PDF orizzontale con dentro i flussi già pronti, uno per pagina. */
function pdfConFlussi(string $percorso, array $flussi): void
{
    $pagine      = count($flussi);
    $primaPagina = 3;
    $primoFlusso = $primaPagina + $pagine;
    $primoFont   = $primoFlusso + $pagine;
    $totale      = $primoFont + 2;

    $out = "%PDF-1.4\n";
    $pos = strlen($out);
    $off = [];
    $scrivi = static function (string $s) use (&$out, &$pos): void {
        $out .= $s;
        $pos += strlen($s);
    };

    $off[1] = $pos;
    $scrivi("1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n");

    $kids = [];
    for ($i = 0; $i < $pagine; $i++) {
        $kids[] = ($primaPagina + $i) . ' 0 R';
    }
    $off[2] = $pos;
    $scrivi("2 0 obj\n<< /Type /Pages /Count {$pagine} /Kids [" . implode(' ', $kids) . "] >>\nendobj\n");

    $risorse = '<< /Font << /F1 ' . $primoFont . ' 0 R /F2 ' . ($primoFont + 1) . ' 0 R >> >>';
    for ($i = 0; $i < $pagine; $i++) {
        $n = $primaPagina + $i;
        $off[$n] = $pos;
        $scrivi("{$n} 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources {$risorse} /Contents "
            . ($primoFlusso + $i) . " 0 R >>\nendobj\n");
    }
    for ($i = 0; $i < $pagine; $i++) {
        $n = $primoFlusso + $i;
        $off[$n] = $pos;
        $scrivi("{$n} 0 obj\n<< /Length " . strlen($flussi[$i]) . " >>\nstream\n{$flussi[$i]}endstream\nendobj\n");
    }
    foreach (['Helvetica', 'Helvetica-Bold'] as $i => $nome) {
        $n = $primoFont + $i;
        $off[$n] = $pos;
        $scrivi("{$n} 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /{$nome} /Encoding /WinAnsiEncoding >>\nendobj\n");
    }

    $inizio = $pos;
    $scrivi("xref\n0 {$totale}\n0000000000 65535 f \n");
    for ($n = 1; $n < $totale; $n++) {
        $scrivi(sprintf("%010d 00000 n \n", $off[$n] ?? 0));
    }
    $scrivi("trailer\n<< /Size {$totale} /Root 1 0 R >>\nstartxref\n{$inizio}\n%%EOF\n");

    file_put_contents($percorso, $out);
}

/** Una stringa posizionata, come la mette Octorate. */
function t(float $x, float $y, string $testo, int $corpo = 7): string
{
    $testo = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $testo);

    return sprintf("BT /F1 %d Tf 1 0 0 1 %.1f %.1f Tm (%s) Tj ET\n", $corpo, $x, $y, $testo);
}

/**
 * Una pagina di «Stampa prenotazioni», con la geometria di quella vera:
 * testata su cinque righe fitte, record su cinque righe logiche a passo 10,2.
 */
function paginaPrenotazioni(array $record, int $numero): string
{
    // Le colonne, alle coordinate della stampa vera.
    $X = ['npren' => 37.0, 'cognome' => 68.3, 'arrivo' => 179.9, 'camera' => 225.4,
          'ospiti' => 260.0, 'gruppo' => 275.7, 'tratt' => 428.6, 'importo' => 519.5,
          'suppl' => 585.2, 'note' => 681.7, 'caparre' => 766.3];

    $c  = t(123.5, 553.6, 'Stampa prenotazioni', 9);
    $c .= t(24.8, 532.2, 'SOC. AGRICOLA DI PROVA S.R.L.');
    $c .= t(698.5, 552.2, 'Pagina') . t(798.7, 552.2, (string) $numero);
    $c .= t(493.4, 528.0, 'Importi espressi in: EUR');

    // Testata: la prima riga porta le ancore di colonna, sotto si impilano
    // le altre voci a passo piu' stretto, come nell'originale.
    $c .= t(27.6, 488.1, 'N') . t(33.1, 488.1, "\u{00b0}") . t($X['npren'], 488.1, 'pren.');
    $c .= t(33.4, 480.2, 'Data') . t(35.3, 472.3, 'Ora');
    $c .= t($X['cognome'], 488.1, 'Cognome') . t($X['cognome'], 480.2, 'Nome')
        . t($X['cognome'], 472.3, 'Telefono') . t($X['cognome'], 464.4, 'eMail');
    $c .= t($X['arrivo'], 488.1, 'Arrivo') . t(174.9, 480.2, 'Partenza');
    $c .= t($X['camera'], 488.1, 'Cam.');
    $c .= t($X['ospiti'], 488.1, 'A') . t($X['ospiti'], 480.2, 'B') . t(260.7, 472.3, 'I');
    $c .= t($X['gruppo'], 488.1, 'Gruppo') . t($X['gruppo'], 480.2, 'Agenzia pagante')
        . t($X['gruppo'], 472.3, 'Agenzia prenotante') . t($X['gruppo'], 464.4, 'Voucher agenzia');
    $c .= t($X['tratt'], 488.1, 'Trattamento') . t(428.1, 480.2, 'Convenzione')
        . t(427.4, 472.3, 'Data opzione') . t(428.9, 464.4, 'Tipo camera')
        . t(422.6, 456.5, 'Predisposizione');
    $c .= t($X['importo'], 487.7, 'Importo') . t(516.9, 479.7, 'Sconto %') . t(509.6, 471.8, 'Sconto valore');
    $c .= t($X['suppl'], 488.3, 'Supplementi');
    $c .= t($X['note'], 488.3, 'Note interne');
    $c .= t($X['caparre'], 488.1, 'Caparre') . t(767.2, 480.2, 'Acconti');

    $y = 441.1;
    foreach ($record as $r) {
        // riga 0
        $c .= t(39.4, $y, $r['npren']) . t($X['cognome'], $y, $r['cognome'])
            . t(170.3, $y, $r['arrivo']) . t(231.6, $y, $r['camera'])
            . t(263.0, $y, $r['adulti']) . t(402.0, $y, $r['trattamento'])
            . t(537.0, $y, $r['importo']);
        if ($r['supplementi'] !== '') {
            $c .= t(565.0, $y, $r['supplementi']);
        }
        if ($r['caparre'] !== '') {
            $c .= t(770.0, $y, $r['caparre']);
        }
        // riga 1
        $c .= t(26.0, $y - 10.2, $r['data']) . t($X['cognome'], $y - 10.2, $r['nome'])
            . t(170.1, $y - 10.2, $r['partenza']);
        if ($r['bambini'] !== '') {
            $c .= t(263.0, $y - 10.2, $r['bambini']);
        }
        if ($r['agenzia'] !== '') {
            $c .= t($X['gruppo'], $y - 10.2, $r['agenzia']);
        }
        // riga 2 (la Ora sta un filo piu' in basso, come nell'originale)
        $c .= t(25.0, $y - 23.5, $r['ora']) . t($X['cognome'], $y - 20.5, $r['telefono']);
        if ($r['neonati'] !== '') {
            $c .= t(263.0, $y - 20.5, $r['neonati']);
        }
        if ($r['data_opzione'] !== '') {
            $c .= t(430.0, $y - 20.5, $r['data_opzione']);
        }
        // riga 3
        $c .= t($X['cognome'], $y - 30.6, $r['email']) . t(402.0, $y - 30.6, $r['tipo_camera']);
        if ($r['note'] !== '') {
            $c .= t($X['note'], $y - 30.6, $r['note']);
        }

        $y -= 44.2;
    }

    return $c;
}

// ── Il PDF di prova ──────────────────────────────────────────────────────────
function record(int $n): array
{
    return [
        'npren'        => '6.' . (100 + $n),
        'data'         => sprintf('%02d/01/26', ($n % 28) + 1),
        'ora'          => sprintf('%02d:15:00', ($n % 12) + 8),
        'cognome'      => 'ROSSI' . $n,
        'nome'         => 'mario',
        'telefono'     => '+39 333 1234567',
        'email'        => "mario{$n}@esempio.it",
        'arrivo'       => sprintf('%02d/06/2026', ($n % 28) + 1),
        'partenza'     => sprintf('%02d/06/2026', ($n % 28) + 3),
        'camera'       => (string) (10 + $n),
        'adulti'       => '2',
        'bambini'      => $n % 3 === 0 ? '1' : '',
        'neonati'      => $n % 5 === 0 ? '1' : '',
        'agenzia'      => $n % 2 === 0 ? 'BOOKING COM' : '',
        'trattamento'  => 'Mezza Pensione',
        'data_opzione' => $n % 4 === 0 ? '20/05/2026' : '',
        'tipo_camera'  => 'Matrim - Doppia',
        'importo'      => '1.250,50',
        'supplementi'  => 'Letto agg. Bambino',
        'note'         => $n === 1 ? 'arriva tardi' : '',
        'caparre'      => '300,00',
    ];
}

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
$conversione = new ConversioneOctoScidoo();
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
verifica('Categoria Camera dalla colonna Tipo camera', 'Matrim - Doppia', $foglio->valore('H2'));
verifica('Adulti', '2', $foglio->valore('O2'));
verifica('Bambini', '0', $foglio->valore('P2'));
verifica('Retta dal trattamento', 'Mezza Pensione', $foglio->valore('S2'));
verifica('Prezzo Retta con le migliaia', '1250.5', $foglio->valore('T2'));

// Le fasce dichiarate si leggono, non si deducono: nessuna segnalazione.
$daRivedere = array_filter($esito['anomalie'], static fn(array $a): bool => $a['gravita'] === 'correggi');
$suFasce = array_filter($daRivedere, static fn(array $a): bool => str_contains($a['colonna'], 'Adulti'));
verifica('nessuna incertezza sulle fasce d\'età', [], array_values($suFasce));

// La terza prenotazione ha un bambino dichiarato: dev'essere nel foglio.
verifica('il bambino dichiarato arriva nel foglio', '1', $foglio->valore('P4'));

// ── La stampa storica continua a funzionare ──────────────────────────────────
$vecchia = __DIR__ . '/../../handoff_octo_scidoo/esempi/prenotazioni 2024 2025.pdf';
if (is_file($vecchia)) {
    $vVecchia = $conversione->verifica($vecchia);
    vero('la stampa clienti presenti è ancora accettata', $vVecchia['ok']);
    verifica('e riconosciuta come tale', 'Stampa clienti presenti', $vVecchia['intestazione']['stampa'] ?? '');
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
