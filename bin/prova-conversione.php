<?php
declare(strict_types=1);
/** Prova la conversione sul PDF di esempio e riassume il risultato. */

require __DIR__ . '/../vendor/autoload.php';

use Vblite\Convert\Conversioni\OctoScidoo\ConversioneOctoScidoo;
use Vblite\Convert\Conversioni\OctoScidoo\Raggruppatore;

$ingresso = $argv[1] ?? __DIR__ . '/../../handoff_octo_scidoo/esempi/prenotazioni 2024 2025.pdf';
$uscita   = $argv[2] ?? sys_get_temp_dir() . '/File Import Prenotazioni.xlsx';

$conversione = new ConversioneOctoScidoo();
$verifica    = $conversione->verifica($ingresso);
printf("verifica: %s%s · %d pagine\n", $verifica['ok'] ? 'ok' : 'NO', $verifica['motivo'] ? " ({$verifica['motivo']})" : '', $verifica['pagine']);
if (!$verifica['ok']) {
    exit(1);
}

$t0  = microtime(true);
$out = $conversione->converti($ingresso, $uscita, Raggruppatore::REGOLE_DEFAULT);
printf(
    "pagine=%d righe_lette=%d righe_scritte=%d anomalie=%d tempo=%.2fs\n",
    $out['pagine'], $out['righe_lette'], $out['righe_scritte'], count($out['anomalie']), microtime(true) - $t0
);

$perMotivo = [];
foreach ($out['anomalie'] as $a) {
    $chiave = $a['colonna'];
    $perMotivo[$chiave] = ($perMotivo[$chiave] ?? 0) + 1;
}
arsort($perMotivo);
echo "\nanomalie per colonna:\n";
foreach ($perMotivo as $colonna => $n) {
    printf("  %-20s %d\n", $colonna, $n);
}

echo "\nprime anomalie:\n";
foreach (array_slice($out['anomalie'], 0, 8) as $a) {
    printf("  %-7s %-24s %-18s %s\n", $a['chiave'], mb_substr($a['cliente'], 0, 22), $a['colonna'], mb_substr($a['motivo'], 0, 70));
}

echo "\nfile scritto: $uscita (" . number_format((int) filesize($uscita)) . " byte)\n";
