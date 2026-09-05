<?php
declare(strict_types=1);

namespace Vblite\Convert\Conversioni\OctoScidoo;

/**
 * Scrive il «File Import Prenotazioni» di Scidoo: intestazioni in riga 1 da B1,
 * date come date vere (seriali Excel) e importi come numeri.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * PERCHE' NON PHPSPREADSHEET
 *
 * PhpSpreadsheet tiene tutto il foglio in memoria: su queste 584 righe arrivava
 * a 56 MB. L'hosting di produzione uccide il processo poco sopra i 20 MB di dati
 * — misurato, non stimato: allocando a blocchi il processo muore fra i 18 e i
 * 20 MB, senza errore PHP e senza una riga di log, perche' e' il sistema a
 * fermarlo, non PHP.
 *
 * Un XLSX pero' e' solo uno zip con dentro qualche XML. Scriverlo di getto,
 * una riga alla volta su file, costa memoria costante: qui il picco non dipende
 * dal numero di righe. In cambio si rinuncia a formule, grafici e a tutto il
 * resto — che questo tracciato non usa.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class ScrittoreScidoo
{
    /** Stili definiti in styles.xml, nell'ordine in cui li scriviamo. */
    private const STILE_NORMALE   = 0;
    private const STILE_TESTATA   = 1;
    private const STILE_DATA      = 2;
    private const STILE_VALUTA    = 3;
    private const STILE_INTERO    = 4;

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
     * @param iterable<array<string,mixed>> $prenotazioni
     * @return int righe scritte
     */
    public function scrivi(iterable $prenotazioni, string $percorso, string $formato = 'xlsx'): int
    {
        return $formato === 'csv'
            ? $this->scriviCsv($prenotazioni, $percorso)
            : $this->scriviXlsx($prenotazioni, $percorso);
    }

    /** @param iterable<array<string,mixed>> $prenotazioni */
    private function scriviCsv(iterable $prenotazioni, string $percorso): int
    {
        $f = fopen($percorso, 'w');
        if ($f === false) {
            throw new \RuntimeException("Non riesco a scrivere {$percorso}");
        }

        fwrite($f, "\u{FEFF}");                       // BOM: Excel apra in UTF-8
        fputcsv($f, array_merge([''], $this->testate()), ';', '"', '\\');

        $scritte = 0;
        foreach ($prenotazioni as $prenotazione) {
            $riga = [''];
            foreach ($this->colonne as $colonna) {
                $riga[] = $this->testoCsv($prenotazione[$colonna['chiave']] ?? null, $colonna['tipo']);
            }
            fputcsv($f, $riga, ';', '"', '\\');
            $scritte++;
        }
        fclose($f);

        return $scritte;
    }

    /**
     * @param iterable<array<string,mixed>> $prenotazioni
     * @return int righe scritte
     */
    private function scriviXlsx(iterable $prenotazioni, string $percorso): int
    {
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException("Serve l'estensione zip di PHP per scrivere un XLSX.");
        }

        // Il corpo del foglio si scrive su un file d'appoggio, non in memoria:
        // e' l'unica parte che cresce col numero di righe.
        $corpo = tempnam(sys_get_temp_dir(), 'scidoo');
        if ($corpo === false) {
            throw new \RuntimeException('Non riesco a creare un file di appoggio.');
        }

        $f = fopen($corpo, 'w');
        if ($f === false) {
            throw new \RuntimeException('Non riesco a scrivere il file di appoggio.');
        }

        fwrite($f, '<sheetData>');

        // Riga 1: le intestazioni, alla lettera come nel file del cliente.
        fwrite($f, '<row r="1">');
        foreach ($this->colonne as $i => $colonna) {
            fwrite($f, $this->cellaTesto($this->lettera($i) . '1', $colonna['testata'], self::STILE_TESTATA));
        }
        fwrite($f, '</row>');

        $riga    = 2;
        $scritte = 0;
        foreach ($prenotazioni as $prenotazione) {
            fwrite($f, '<row r="' . $riga . '">');
            foreach ($this->colonne as $i => $colonna) {
                $cella = $this->cella(
                    $this->lettera($i) . $riga,
                    $prenotazione[$colonna['chiave']] ?? null,
                    $colonna['tipo']
                );
                if ($cella !== '') {
                    fwrite($f, $cella);
                }
            }
            fwrite($f, '</row>');
            $riga++;
            $scritte++;
        }

        fwrite($f, '</sheetData>');
        fclose($f);

        // Il foglio completo: l'intestazione va scritta ora, perche' <dimension>
        // vuole sapere l'ultima riga, che si conosce solo a corpo finito.
        $foglio = tempnam(sys_get_temp_dir(), 'scidoo');
        if ($foglio === false) {
            @unlink($corpo);
            throw new \RuntimeException('Non riesco a creare un file di appoggio.');
        }

        $g = fopen($foglio, 'w');
        if ($g === false) {
            @unlink($corpo);
            throw new \RuntimeException('Non riesco a scrivere il file di appoggio.');
        }

        fwrite($g, $this->prologoFoglio($riga - 1));

        // Copia in streaming: il corpo non passa mai per la memoria di PHP.
        $lettura = fopen($corpo, 'r');
        if ($lettura !== false) {
            stream_copy_to_stream($lettura, $g);
            fclose($lettura);
        }
        fwrite($g, '</worksheet>');
        fclose($g);
        @unlink($corpo);

        $zip = new \ZipArchive();
        @unlink($percorso);
        if ($zip->open($percorso, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($foglio);
            throw new \RuntimeException("Non riesco a creare {$percorso}");
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->relsRadice());
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->relsWorkbook());
        $zip->addFromString('xl/styles.xml', $this->styles());
        // addFile legge dal disco mentre comprime: il foglio non passa in memoria.
        $zip->addFile($foglio, 'xl/worksheets/sheet1.xml');
        $zip->close();

        @unlink($foglio);

        return $scritte;
    }

    /**
     * L'apertura del foglio.
     *
     * L'ordine degli elementi non e' una questione di stile: lo schema di
     * OOXML li dichiara come una sequenza, e Excel la fa rispettare. Deve
     * essere dimension → sheetViews → sheetFormatPr → cols → sheetData; anche
     * i <col> vanno in ordine crescente di colonna. Sbagliare l'ordine produce
     * un file che le librerie piu' tolleranti leggono senza fiatare e che Excel
     * invece rifiuta, offrendo di «recuperare il contenuto».
     */
    private function prologoFoglio(int $ultimaRiga): string
    {
        $ultima = $this->lettera(count($this->colonne) - 1);

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';

        $xml .= '<dimension ref="A1:' . $ultima . max(1, $ultimaRiga) . '"/>';

        $xml .= '<sheetViews><sheetView workbookViewId="0">'
            . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
            . '<selection pane="bottomLeft" activeCell="A2" sqref="A2"/>'
            . '</sheetView></sheetViews>';

        $xml .= '<sheetFormatPr defaultRowHeight="15"/>';

        // La colonna A resta libera ma tiene la sua larghezza, e va per prima.
        $xml .= '<cols><col min="1" max="1" width="8.66" customWidth="1"/>';
        foreach ($this->colonne as $i => $colonna) {
            $n = $i + 2;
            $xml .= sprintf('<col min="%d" max="%d" width="%.2f" customWidth="1"/>', $n, $n, $colonna['larghezza']);
        }
        $xml .= '</cols>';

        return $xml;
    }

    /** Una cella del foglio; stringa vuota se non va scritta (cella vuota, mai 0). */
    private function cella(string $coord, mixed $valore, string $tipo): string
    {
        if ($valore === null || $valore === '') {
            return '';
        }

        return match ($tipo) {
            'data'   => sprintf('<c r="%s" s="%d"><v>%s</v></c>', $coord, self::STILE_DATA, $this->numero((float) $valore)),
            'valuta' => sprintf('<c r="%s" s="%d"><v>%s</v></c>', $coord, self::STILE_VALUTA, $this->numero((float) $valore)),
            'intero' => sprintf('<c r="%s" s="%d"><v>%d</v></c>', $coord, self::STILE_INTERO, (int) $valore),
            // Numeri di camera e voucher restano testo: non vanno arrotondati
            // ne' interpretati come date da Excel.
            default  => $this->cellaTesto($coord, (string) $valore, self::STILE_NORMALE),
        };
    }

    private function cellaTesto(string $coord, string $testo, int $stile): string
    {
        return sprintf(
            '<c r="%s" s="%d" t="inlineStr"><is><t xml:space="preserve">%s</t></is></c>',
            $coord,
            $stile,
            $this->xml($testo)
        );
    }

    /** Punto decimale e niente notazione esponenziale: XLSX vuole il formato inglese. */
    private function numero(float $v): string
    {
        return rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.') ?: '0';
    }

    private function xml(string $testo): string
    {
        // I caratteri di controllo non sono ammessi in XML e nei commenti OTA
        // capitano: si tolgono, altrimenti Excel rifiuta l'intero file.
        $pulito = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $testo) ?? $testo;

        return htmlspecialchars($pulito, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function testoCsv(mixed $valore, string $tipo): string
    {
        if ($valore === null || $valore === '') {
            return '';
        }

        return match ($tipo) {
            // Nel CSV le date tornano leggibili: un seriale non direbbe niente.
            'data'   => \Vblite\Convert\Vista::data((float) $valore),
            'valuta' => number_format((float) $valore, 2, ',', ''),
            'intero' => (string) (int) $valore,
            default  => (string) $valore,
        };
    }

    /** Indice 0 → «B», perche' la colonna A resta libera. */
    private function lettera(int $indice): string
    {
        $n       = $indice + 2;
        $lettera = '';
        while ($n > 0) {
            $resto   = ($n - 1) % 26;
            $lettera = chr(65 + $resto) . $lettera;
            $n       = (int) (($n - $resto) / 26);
        }

        return $lettera;
    }

    // ── le parti fisse del pacchetto XLSX ────────────────────────────────────

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private function relsRadice(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function workbook(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Foglio1" sheetId="1" r:id="rId1"/></sheets></workbook>';
    }

    private function relsWorkbook(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    /** Gli stessi formati del file di esempio del cliente. */
    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="2">'
            . '<numFmt numFmtId="164" formatCode="m/d/yyyy"/>'
            . '<numFmt numFmtId="165" formatCode="#,##0.00\ &quot;€&quot;"/>'
            . '</numFmts>'
            . '<fonts count="2">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="12"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="3">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFFBE5D6"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="5">'
            . '<xf numFmtId="0"   fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0"   fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="1"   fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }
}
