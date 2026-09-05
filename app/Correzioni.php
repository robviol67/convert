<?php
declare(strict_types=1);

namespace Vblite\Convert;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Riscrive nel file generato i valori corretti a mano nella schermata «Da rivedere».
 * Si lavora sul file gia' prodotto: la conversione resta deterministica e la
 * correzione umana e' un passaggio successivo, tracciato nel database.
 */
final class Correzioni
{
    /**
     * @param array<string,mixed>        $job
     * @param list<array<string,mixed>>  $correzioni
     */
    public static function applica(array $job, array $correzioni): void
    {
        $percorso = (string) $job['file_out'];
        if (!is_file($percorso) || !str_ends_with($percorso, '.xlsx')) {
            return; // il CSV si rigenera, non si ritocca
        }

        $foglio  = IOFactory::load($percorso);
        $sh      = $foglio->getSheet(0);
        $colonne = require Config::radice() . '/app/Conversioni/OctoScidoo/colonne.php';

        // Indice riga per ID prenotazione: la colonna B porta l'ID senza il punto.
        $righePerId = [];
        foreach ($sh->getRowIterator(2) as $riga) {
            $id = $sh->getCell('B' . $riga->getRowIndex())->getValue();
            if ($id !== null && $id !== '') {
                $righePerId[(string) (int) $id] = $riga->getRowIndex();
            }
        }

        foreach ($correzioni as $correzione) {
            $id = preg_replace('~\D~', '', (string) $correzione['chiave']);
            if ($id === '' || !isset($righePerId[$id])) {
                continue;
            }
            $riga = $righePerId[$id];

            foreach (self::celle((string) $correzione['colonna'], (string) $correzione['valore_corretto'], $colonne) as [$chiave, $valore]) {
                $indice = self::indiceColonna($chiave, $colonne);
                if ($indice === null) {
                    continue;
                }
                $coord = Coordinate::stringFromColumnIndex($indice + 2) . $riga;
                $tipo  = $colonne[$indice]['tipo'];

                if ($valore === '') {
                    $sh->setCellValue($coord, null);
                } elseif ($tipo === 'intero') {
                    $sh->setCellValue($coord, (int) $valore);
                } elseif ($tipo === 'valuta') {
                    $sh->setCellValue($coord, (float) str_replace(',', '.', str_replace('.', '', $valore)));
                } else {
                    $sh->setCellValueExplicit($coord, $valore, DataType::TYPE_STRING);
                }
            }
        }

        (new Xlsx($foglio))->save($percorso);
        $foglio->disconnectWorksheets();
    }

    /**
     * Una segnalazione puo' toccare piu' colonne: «Adulti · Bambini» porta due
     * numeri separati da un punto mediano.
     *
     * @param list<array{chiave:string,testata:string,tipo:string,larghezza:float}> $colonne
     * @return list<array{0:string,1:string}>
     */
    private static function celle(string $colonna, string $valore, array $colonne): array
    {
        if ($colonna === 'Adulti · Bambini') {
            $pezzi = preg_split('~[·,;/\s]+~u', trim($valore)) ?: [];

            return [
                ['adulti', $pezzi[0] ?? ''],
                ['bambini', $pezzi[1] ?? ''],
            ];
        }

        foreach ($colonne as $definizione) {
            if ($definizione['testata'] === $colonna || rtrim($definizione['testata']) === rtrim($colonna)) {
                return [[$definizione['chiave'], trim($valore)]];
            }
        }

        return [];
    }

    /** @param list<array{chiave:string,testata:string,tipo:string,larghezza:float}> $colonne */
    private static function indiceColonna(string $chiave, array $colonne): ?int
    {
        foreach ($colonne as $indice => $definizione) {
            if ($definizione['chiave'] === $chiave) {
                return $indice;
            }
        }

        return null;
    }
}
