<?php
declare(strict_types=1);

namespace Vblite\Convert\Conversioni\OctoScidoo;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Scrive il «File Import Prenotazioni» di Scidoo: intestazioni in riga 1 da B1,
 * date come date vere (seriali Excel) e importi come numeri.
 */
final class ScrittoreScidoo
{
    private const FORMATO_DATA    = 'm/d/yyyy';
    private const FORMATO_VALUTA  = '#,##0.00\ "€"';
    private const RIEMPIMENTO_HDR = 'FFFBE5D6';

    /** @var list<array{chiave:string,testata:string,tipo:string,larghezza:float}> */
    private array $colonne;

    /** @param list<array{chiave:string,testata:string,tipo:string,larghezza:float}>|null $colonne */
    public function __construct(?array $colonne = null)
    {
        $this->colonne = $colonne ?? require __DIR__ . '/colonne.php';
    }

    /** @return list<string> */
    public function testate(): array
    {
        return array_map(static fn(array $c): string => $c['testata'], $this->colonne);
    }

    /**
     * @param list<array<string,mixed>> $prenotazioni
     * @return int righe scritte
     */
    public function scrivi(array $prenotazioni, string $percorso, string $formato = 'xlsx'): int
    {
        $foglio = new Spreadsheet();
        $sh     = $foglio->getActiveSheet();
        $sh->setTitle('Foglio1');

        // Riga 1: intestazioni da B1 (la colonna A resta libera, come nell'originale)
        foreach ($this->colonne as $i => $colonna) {
            $lettera = $this->lettera($i);
            $sh->setCellValueExplicit($lettera . '1', $colonna['testata'], DataType::TYPE_STRING);
            $sh->getColumnDimension($lettera)->setWidth($colonna['larghezza']);
        }
        $ultima = $this->lettera(count($this->colonne) - 1);
        $stile  = $sh->getStyle('B1:' . $ultima . '1');
        $stile->getFont()->setBold(true)->setName('Calibri')->setSize(12);
        $stile->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::RIEMPIMENTO_HDR);
        $sh->getColumnDimension('A')->setWidth(8.66);

        $riga = 2;
        foreach ($prenotazioni as $prenotazione) {
            foreach ($this->colonne as $i => $colonna) {
                $this->scriviCella($sh, $this->lettera($i) . $riga, $prenotazione[$colonna['chiave']] ?? null, $colonna['tipo']);
            }
            $riga++;
        }

        $sh->freezePane('A2');

        if ($formato === 'csv') {
            (new Csv($foglio))->setDelimiter(';')->setUseBOM(true)->save($percorso);
        } else {
            (new Xlsx($foglio))->save($percorso);
        }
        $foglio->disconnectWorksheets();

        return $riga - 2;
    }

    private function scriviCella(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sh, string $coord, mixed $valore, string $tipo): void
    {
        if ($valore === null || $valore === '') {
            return; // cella vuota: mai 0, mai stringa vuota
        }

        switch ($tipo) {
            case 'data':
                $sh->setCellValue($coord, $valore);
                $sh->getStyle($coord)->getNumberFormat()->setFormatCode(self::FORMATO_DATA);
                break;
            case 'valuta':
                $sh->setCellValue($coord, (float) $valore);
                $sh->getStyle($coord)->getNumberFormat()->setFormatCode(self::FORMATO_VALUTA);
                break;
            case 'intero':
                $sh->setCellValue($coord, (int) $valore);
                $sh->getStyle($coord)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER);
                break;
            default:
                // I numeri di camera e i voucher restano testo: non vanno arrotondati
                // ne' interpretati come date da Excel.
                $sh->setCellValueExplicit($coord, (string) $valore, DataType::TYPE_STRING);
        }
    }

    /** Indice 0 → «B», perche' la colonna A resta libera. */
    private function lettera(int $indice): string
    {
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($indice + 2);
    }
}
