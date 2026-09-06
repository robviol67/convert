<?php
declare(strict_types=1);

namespace Vblite\Convert\Supporto;

/**
 * Scrive un foglio XLSX, una riga alla volta.
 *
 * Estratto dallo scrittore Scidoo quando è servito un secondo foglio da
 * produrre: la parte difficile — l'ordine degli elementi che Excel pretende,
 * gli stili, la scrittura in streaming — è la stessa, e tenerne due copie
 * avrebbe voluto dire correggere due volte gli stessi errori.
 *
 * L'ordine degli elementi NON è una questione di stile: lo schema OOXML li
 * dichiara come una sequenza e Excel la fa rispettare —
 * dimension → sheetViews → sheetFormatPr → cols → sheetData. Sbagliarlo
 * produce un file che le librerie tolleranti leggono senza fiatare e che Excel
 * rifiuta, offrendo di «recuperare il contenuto».
 */
final class FoglioXlsx
{
    public const STILE_NORMALE = 0;
    public const STILE_TESTATA = 1;
    public const STILE_DATA    = 2;
    public const STILE_VALUTA  = 3;
    public const STILE_INTERO  = 4;

    private const RIEMPIMENTO_TESTATA = 'FFFBE5D6';

    /** @var list<array{testata:string,tipo:string,larghezza:float}> */
    private array $colonne = [];

    private string $percorso = '';
    private int $primaColonna = 1;
    private string $nomeFoglio = 'Foglio1';

    /** @var resource|null il corpo, su file: è l'unica parte che cresce */
    private $f = null;

    private string $corpo = '';
    private int $riga = 1;

    /**
     * @param list<array{testata:string,tipo?:string,larghezza?:float}> $colonne
     * @param int $primaColonna 1 = si comincia da A, 2 = da B (la A resta libera)
     */
    public function apri(string $percorso, array $colonne, int $primaColonna = 1, string $nomeFoglio = 'Foglio1'): void
    {
        $this->percorso     = $percorso;
        $this->primaColonna = max(1, $primaColonna);
        $this->nomeFoglio   = $nomeFoglio;
        $this->riga         = 1;

        $this->colonne = array_map(
            static fn(array $c): array => [
                'testata'   => $c['testata'],
                'tipo'      => $c['tipo'] ?? 'testo',
                'larghezza' => (float) ($c['larghezza'] ?? 14),
            ],
            $colonne
        );

        $corpo = tempnam(sys_get_temp_dir(), 'foglio');
        if ($corpo === false) {
            throw new \RuntimeException('Non riesco a creare un file di appoggio.');
        }
        $f = fopen($corpo, 'w');
        if ($f === false) {
            throw new \RuntimeException('Non riesco a scrivere il file di appoggio.');
        }
        $this->corpo = $corpo;
        $this->f     = $f;

        fwrite($f, '<sheetData><row r="1">');
        foreach ($this->colonne as $i => $colonna) {
            fwrite($f, $this->cellaTesto($this->lettera($i) . '1', $colonna['testata'], self::STILE_TESTATA));
        }
        fwrite($f, '</row>');
        $this->riga = 2;
    }

    /** @param list<mixed> $valori nello stesso ordine delle colonne */
    public function riga(array $valori): void
    {
        if ($this->f === null) {
            return;
        }

        fwrite($this->f, '<row r="' . $this->riga . '">');
        foreach ($this->colonne as $i => $colonna) {
            $cella = $this->cella($this->lettera($i) . $this->riga, $valori[$i] ?? null, $colonna['tipo']);
            if ($cella !== '') {
                fwrite($this->f, $cella);
            }
        }
        fwrite($this->f, '</row>');
        $this->riga++;
    }

    /** @return int righe di dati scritte */
    public function chiudi(): int
    {
        if ($this->f === null) {
            return 0;
        }
        fwrite($this->f, '</sheetData>');
        fclose($this->f);
        $this->f = null;

        if (!class_exists('ZipArchive')) {
            @unlink($this->corpo);
            throw new \RuntimeException("Serve l'estensione zip di PHP per scrivere un XLSX.");
        }

        // Il foglio completo: intestazione (che vuole l'ultima riga) più il
        // corpo copiato in streaming, così non passa mai per la memoria.
        $foglio = (string) tempnam(sys_get_temp_dir(), 'fogl');
        $g = fopen($foglio, 'w');
        if ($g !== false) {
            fwrite($g, $this->prologo($this->riga - 1));
            $lettura = fopen($this->corpo, 'r');
            if ($lettura !== false) {
                stream_copy_to_stream($lettura, $g);
                fclose($lettura);
            }
            fwrite($g, '</worksheet>');
            fclose($g);
        }

        $zip = new \ZipArchive();
        @unlink($this->percorso);
        if ($zip->open($this->percorso, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($this->corpo);
            @unlink($foglio);
            throw new \RuntimeException("Non riesco a creare {$this->percorso}");
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->relsRadice());
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->relsWorkbook());
        $zip->addFromString('xl/styles.xml', $this->styles());
        $zip->addFile($foglio, 'xl/worksheets/sheet1.xml');
        $zip->close();

        @unlink($this->corpo);
        @unlink($foglio);

        return $this->riga - 2;
    }

    // ── celle ────────────────────────────────────────────────────────────────

    private function cella(string $coord, mixed $valore, string $tipo): string
    {
        // Cella vuota: mai uno zero, mai una stringa vuota.
        if ($valore === null || $valore === '') {
            return '';
        }

        return match ($tipo) {
            'data'   => sprintf('<c r="%s" s="%d"><v>%s</v></c>', $coord, self::STILE_DATA, $this->numero((float) $valore)),
            'valuta' => sprintf('<c r="%s" s="%d"><v>%s</v></c>', $coord, self::STILE_VALUTA, $this->numero((float) $valore)),
            'intero' => sprintf('<c r="%s" s="%d"><v>%d</v></c>', $coord, self::STILE_INTERO, (int) $valore),
            'numero' => is_numeric((string) $valore)
                ? sprintf('<c r="%s"><v>%s</v></c>', $coord, $this->numero((float) $valore))
                : $this->cellaTesto($coord, (string) $valore, self::STILE_NORMALE),
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

    /** Punto decimale e niente esponenti: XLSX vuole il formato inglese. */
    private function numero(float $v): string
    {
        return rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.') ?: '0';
    }

    private function xml(string $testo): string
    {
        // I caratteri di controllo non sono ammessi in XML e capitano: si
        // tolgono, altrimenti Excel rifiuta l'intero file.
        $pulito = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $testo) ?? $testo;

        return htmlspecialchars($pulito, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function lettera(int $indice): string
    {
        $n = $indice + $this->primaColonna;
        $lettera = '';
        while ($n > 0) {
            $resto   = ($n - 1) % 26;
            $lettera = chr(65 + $resto) . $lettera;
            $n       = (int) (($n - $resto) / 26);
        }

        return $lettera;
    }

    // ── parti fisse ──────────────────────────────────────────────────────────

    private function prologo(int $ultimaRiga): string
    {
        $ultima = $this->lettera(count($this->colonne) - 1);

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<dimension ref="A1:' . $ultima . max(1, $ultimaRiga) . '"/>'
            . '<sheetViews><sheetView workbookViewId="0">'
            . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
            . '<selection pane="bottomLeft" activeCell="A2" sqref="A2"/>'
            . '</sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="15"/><cols>';

        // I <col> vanno in ordine crescente: se la A resta libera, va comunque
        // dichiarata per prima.
        if ($this->primaColonna > 1) {
            $xml .= '<col min="1" max="' . ($this->primaColonna - 1) . '" width="8.66" customWidth="1"/>';
        }
        foreach ($this->colonne as $i => $colonna) {
            $n = $i + $this->primaColonna;
            $xml .= sprintf('<col min="%d" max="%d" width="%.2f" customWidth="1"/>', $n, $n, $colonna['larghezza']);
        }

        return $xml . '</cols>';
    }

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
            . '<sheets><sheet name="' . $this->xml($this->nomeFoglio) . '" sheetId="1" r:id="rId1"/></sheets></workbook>';
    }

    private function relsWorkbook(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

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
            . '<fill><patternFill patternType="solid"><fgColor rgb="' . self::RIEMPIMENTO_TESTATA . '"/><bgColor indexed="64"/></patternFill></fill>'
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
