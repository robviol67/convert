<?php
declare(strict_types=1);

namespace Vblite\Convert\Conversioni\Documenti\Scrittori;

use Vblite\Convert\Conversioni\Documenti\Blocco;
use Vblite\Convert\Conversioni\Documenti\Documento;
use Vblite\Convert\Conversioni\Documenti\Testo;

/**
 * Modello → Word (.docx).
 *
 * Scritto a mano, come l'XLSX e per lo stesso motivo: le librerie tengono il
 * documento in memoria e qui il tetto è ~20 MB. Un .docx è uno zip con dentro
 * XML; il corpo si scrive di getto su file e la memoria resta costante.
 *
 * Anche qui vale la lezione dell'XLSX: **l'ordine degli elementi conta**. Word
 * applica la sequenza dichiarata dallo schema e rifiuta i file che non la
 * rispettano — dentro w:pPr, w:pStyle viene prima di w:numPr, che viene prima
 * di w:jc; dentro w:rPr, w:rFonts prima di w:b, prima di w:i, prima di w:sz.
 */
final class ScrittoreDocx implements Scrittore
{
    /** Id delle due numerazioni definite in numbering.xml. */
    private const NUM_PUNTATO  = 1;
    private const NUM_NUMERATO = 2;

    /** @var list<array{id:string,nome:string,percorso:string}> */
    private array $immagini = [];

    /** @var list<array{id:string,url:string}> */
    private array $collegamenti = [];

    public static function estensione(): string
    {
        return 'docx';
    }

    public static function nome(): string
    {
        return 'Word (.docx)';
    }

    /** @var resource|null */
    private $f = null;

    private string $corpo = '';
    private string $percorso = '';
    private int $resi = 0;
    private ?Documento $documento = null;

    /** Questo formato non ha impostazioni: le regole non lo riguardano. */
    public function configura(array $regole): void
    {
    }

    public function apri(string $percorso, Documento $documento): void
    {
        $this->immagini     = [];
        $this->collegamenti = [];
        $this->resi         = 0;
        $this->percorso     = $percorso;
        $this->documento    = $documento;

        // Il corpo si accumula su file: le relazioni e i tipi di contenuto si
        // conoscono solo alla fine, ma il testo non deve stare in memoria.
        $corpo = tempnam(sys_get_temp_dir(), 'docx');
        if ($corpo === false) {
            throw new \RuntimeException('Non riesco a creare un file di appoggio.');
        }
        $f = fopen($corpo, 'w');
        if ($f === false) {
            throw new \RuntimeException('Non riesco a scrivere il file di appoggio.');
        }
        $this->corpo = $corpo;
        $this->f     = $f;
    }

    public function blocco(Blocco $blocco): void
    {
        if ($this->f === null || $this->documento === null) {
            return;
        }
        fwrite($this->f, $this->rendi($blocco, $this->documento));
        $this->resi++;
    }

    public function chiudi(): int
    {
        if ($this->f === null) {
            return $this->resi;
        }
        fclose($this->f);
        $this->f = null;

        $zip = new \ZipArchive();
        @unlink($this->percorso);
        if ($zip->open($this->percorso, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($this->corpo);
            throw new \RuntimeException("Non riesco a creare {$this->percorso}");
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->relsRadice());
        $zip->addFromString('word/styles.xml', $this->stili());
        $zip->addFromString('word/numbering.xml', $this->numerazioni());
        $zip->addFromString('word/_rels/document.xml.rels', $this->relsDocumento());

        // L'intestazione e la chiusura sono corte; il corpo si concatena in
        // streaming, cosi' un documento lungo non passa mai per la memoria.
        $completo = tempnam(sys_get_temp_dir(), 'docd');
        $g = fopen((string) $completo, 'w');
        if ($g !== false) {
            fwrite($g, $this->apertura());
            $lettura = fopen($this->corpo, 'r');
            if ($lettura !== false) {
                stream_copy_to_stream($lettura, $g);
                fclose($lettura);
            }
            fwrite($g, $this->chiusura());
            fclose($g);
            $zip->addFile((string) $completo, 'word/document.xml');
        }

        foreach ($this->immagini as $immagine) {
            if (is_file($immagine['percorso'])) {
                $zip->addFile($immagine['percorso'], 'word/media/' . $immagine['nome']);
            }
        }
        $zip->close();

        @unlink($this->corpo);
        @unlink((string) $completo);

        return $this->resi;
    }

    private function rendi(Blocco $blocco, Documento $documento): string
    {
        return match ($blocco->tipo) {
            Blocco::TITOLO    => $this->paragrafo($blocco->testi, 'Titolo' . $blocco->livello),
            Blocco::CITAZIONE => $this->paragrafo($blocco->testi, 'Citazione'),
            Blocco::CODICE    => $this->codice($blocco->nudo()),
            Blocco::ELENCO    => $this->paragrafo(
                $blocco->testi,
                'Elenco',
                $blocco->ordinato ? self::NUM_NUMERATO : self::NUM_PUNTATO,
                $blocco->livello
            ),
            Blocco::TABELLA   => $this->tabella($blocco),
            Blocco::IMMAGINE  => $this->immagine($blocco, $documento),
            Blocco::RIGA      => '<w:p><w:pPr><w:pBdr><w:bottom w:val="single" w:sz="6" w:space="1" w:color="AAAAAA"/></w:pBdr></w:pPr></w:p>',
            default           => $this->paragrafo($blocco->testi),
        };
    }

    /** @param list<Testo> $testi */
    private function paragrafo(array $testi, string $stile = '', int $numerazione = 0, int $livello = 0): string
    {
        // Ordine obbligato dentro w:pPr: pStyle, poi numPr.
        $prop = '';
        if ($stile !== '') {
            $prop .= '<w:pStyle w:val="' . $stile . '"/>';
        }
        if ($numerazione > 0) {
            $prop .= '<w:numPr><w:ilvl w:val="' . min(8, $livello) . '"/>'
                   . '<w:numId w:val="' . $numerazione . '"/></w:numPr>';
        }

        $corse = '';
        foreach ($testi as $testo) {
            $corse .= $this->corsa($testo);
        }

        return '<w:p>' . ($prop !== '' ? '<w:pPr>' . $prop . '</w:pPr>' : '') . $corse . '</w:p>';
    }

    private function corsa(Testo $testo): string
    {
        // Ordine obbligato dentro w:rPr: rFonts, b, i, sz.
        $prop = '';
        if ($testo->codice) {
            $prop .= '<w:rFonts w:ascii="Consolas" w:hAnsi="Consolas"/>';
        }
        if ($testo->grassetto) {
            $prop .= '<w:b/>';
        }
        if ($testo->corsivo) {
            $prop .= '<w:i/>';
        }

        $corsa = '<w:r>' . ($prop !== '' ? '<w:rPr>' . $prop . '</w:rPr>' : '')
               . '<w:t xml:space="preserve">' . $this->xml($testo->testo) . '</w:t></w:r>';

        if ($testo->collegamento === null || $testo->collegamento === '') {
            return $corsa;
        }

        $id = 'rIdLink' . (count($this->collegamenti) + 1);
        $this->collegamenti[] = ['id' => $id, 'url' => $testo->collegamento];

        return '<w:hyperlink r:id="' . $id . '">' . $corsa . '</w:hyperlink>';
    }

    private function codice(string $testo): string
    {
        $righe = explode("\n", $testo);
        $fuori = '';
        foreach ($righe as $riga) {
            $fuori .= '<w:p><w:pPr><w:pStyle w:val="Codice"/></w:pPr>'
                    . '<w:r><w:rPr><w:rFonts w:ascii="Consolas" w:hAnsi="Consolas"/></w:rPr>'
                    . '<w:t xml:space="preserve">' . $this->xml($riga) . '</w:t></w:r></w:p>';
        }

        return $fuori;
    }

    private function tabella(Blocco $blocco): string
    {
        $larghezza = 0;
        foreach ($blocco->righe as $riga) {
            $larghezza = max($larghezza, count($riga));
        }
        if ($larghezza === 0) {
            return '';
        }
        $perCella = (int) floor(9360 / $larghezza);   // larghezza utile in twip

        $xml = '<w:tbl><w:tblPr><w:tblStyle w:val="Griglia"/>'
             . '<w:tblW w:w="0" w:type="auto"/>'
             . '<w:tblBorders>'
             . '<w:top w:val="single" w:sz="4" w:space="0" w:color="999999"/>'
             . '<w:left w:val="single" w:sz="4" w:space="0" w:color="999999"/>'
             . '<w:bottom w:val="single" w:sz="4" w:space="0" w:color="999999"/>'
             . '<w:right w:val="single" w:sz="4" w:space="0" w:color="999999"/>'
             . '<w:insideH w:val="single" w:sz="4" w:space="0" w:color="999999"/>'
             . '<w:insideV w:val="single" w:sz="4" w:space="0" w:color="999999"/>'
             . '</w:tblBorders></w:tblPr>';

        foreach ($blocco->righe as $indice => $riga) {
            // Si marca l'intestazione: Word la ripete a ogni pagina, e chi
            // rilegge il file sa che il grassetto è decorazione, non contenuto.
            $xml .= '<w:tr>' . ($indice === 0 ? '<w:trPr><w:tblHeader/></w:trPr>' : '');
            for ($c = 0; $c < $larghezza; $c++) {
                $celle = $riga[$c] ?? [];
                // La prima riga fa da intestazione: in grassetto, come ci si aspetta.
                if ($indice === 0) {
                    $celle = array_map(
                        static fn(Testo $t): Testo => new Testo($t->testo, true, $t->corsivo, $t->codice, $t->collegamento),
                        $celle
                    );
                }
                $xml .= '<w:tc><w:tcPr><w:tcW w:w="' . $perCella . '" w:type="dxa"/></w:tcPr>'
                      . $this->paragrafo($celle) . '</w:tc>';
            }
            $xml .= '</w:tr>';
        }

        return $xml . '</w:tbl>';
    }

    private function immagine(Blocco $blocco, Documento $documento): string
    {
        $percorso = $documento->immagini()[$blocco->extra] ?? null;
        if ($percorso === null || !is_file($percorso)) {
            // L'immagine non c'è più: si dice che c'era, invece di tacere.
            return $this->paragrafo([new Testo(
                '[immagine non disponibile: ' . ($blocco->alt !== '' ? $blocco->alt : basename($blocco->extra)) . ']',
                false,
                true
            )]);
        }

        $misure = @getimagesize($percorso);
        if ($misure === false) {
            return '';
        }

        // Word misura in EMU: 914.400 per pollice, e si assume 96 punti per pollice.
        $massima  = 5486400;                        // 6 pollici di larghezza utile
        $larghezza = (int) ($misure[0] * 9525);
        $altezza   = (int) ($misure[1] * 9525);
        if ($larghezza > $massima) {
            $altezza   = (int) ($altezza * $massima / $larghezza);
            $larghezza = $massima;
        }

        $numero = count($this->immagini) + 1;
        $id     = 'rIdImg' . $numero;
        $nome   = 'immagine' . $numero . '.' . (pathinfo($percorso, PATHINFO_EXTENSION) ?: 'png');
        $this->immagini[] = ['id' => $id, 'nome' => $nome, 'percorso' => $percorso];

        return '<w:p><w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0">'
            . '<wp:extent cx="' . $larghezza . '" cy="' . $altezza . '"/>'
            . '<wp:docPr id="' . $numero . '" name="Immagine ' . $numero . '" descr="' . $this->xml($blocco->alt) . '"/>'
            . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
            . '<a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<pic:nvPicPr><pic:cNvPr id="' . $numero . '" name="' . $nome . '"/><pic:cNvPicPr/></pic:nvPicPr>'
            . '<pic:blipFill><a:blip r:embed="' . $id . '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
            . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $larghezza . '" cy="' . $altezza . '"/></a:xfrm>'
            . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
            . '</pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';
    }

    private function xml(string $testo): string
    {
        $pulito = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $testo) ?? $testo;

        return htmlspecialchars($pulito, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }

    // ── le parti fisse del pacchetto ─────────────────────────────────────────

    private function apertura(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
            . ' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing">'
            . '<w:body>';
    }

    private function chiusura(): string
    {
        return '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/>'
            . '<w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134"'
            . ' w:header="709" w:footer="709" w:gutter="0"/></w:sectPr></w:body></w:document>';
    }

    private function contentTypes(): string
    {
        $tipi = '';
        foreach (['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'bmp' => 'image/bmp', 'emf' => 'image/x-emf', 'wmf' => 'image/x-wmf'] as $est => $tipo) {
            $tipi .= '<Default Extension="' . $est . '" ContentType="' . $tipo . '"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . $tipi
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
            . '<Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/>'
            . '</Types>';
    }

    private function relsRadice(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '</Relationships>';
    }

    private function relsDocumento(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '<Relationship Id="rIdNum" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering" Target="numbering.xml"/>';

        foreach ($this->immagini as $immagine) {
            $xml .= '<Relationship Id="' . $immagine['id'] . '"'
                 . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image"'
                 . ' Target="media/' . $immagine['nome'] . '"/>';
        }
        foreach ($this->collegamenti as $collegamento) {
            $xml .= '<Relationship Id="' . $collegamento['id'] . '"'
                 . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink"'
                 . ' Target="' . $this->xml($collegamento['url']) . '" TargetMode="External"/>';
        }

        return $xml . '</Relationships>';
    }

    private function stili(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:docDefaults><w:rPrDefault><w:rPr>'
            . '<w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:sz w:val="22"/>'
            . '</w:rPr></w:rPrDefault></w:docDefaults>'
            . '<w:style w:type="paragraph" w:default="1" w:styleId="Normale"><w:name w:val="Normal"/></w:style>';

        // Titoli: corpo decrescente, come li fa Word.
        $corpi = [1 => 32, 2 => 28, 3 => 26, 4 => 24, 5 => 22, 6 => 22];
        foreach ($corpi as $livello => $corpo) {
            $xml .= '<w:style w:type="paragraph" w:styleId="Titolo' . $livello . '">'
                 . '<w:name w:val="heading ' . $livello . '"/>'
                 . '<w:basedOn w:val="Normale"/>'
                 . '<w:pPr><w:keepNext/><w:spacing w:before="240" w:after="120"/>'
                 . '<w:outlineLvl w:val="' . ($livello - 1) . '"/></w:pPr>'
                 . '<w:rPr><w:b/><w:sz w:val="' . $corpo . '"/><w:color w:val="1F3864"/></w:rPr>'
                 . '</w:style>';
        }

        $xml .= '<w:style w:type="paragraph" w:styleId="Citazione"><w:name w:val="Quote"/>'
             . '<w:basedOn w:val="Normale"/>'
             . '<w:pPr><w:ind w:left="567"/><w:spacing w:before="120" w:after="120"/>'
             . '<w:pBdr><w:left w:val="single" w:sz="12" w:space="8" w:color="BBBBBB"/></w:pBdr></w:pPr>'
             . '<w:rPr><w:i/><w:color w:val="444444"/></w:rPr></w:style>';

        $xml .= '<w:style w:type="paragraph" w:styleId="Codice"><w:name w:val="Code"/>'
             . '<w:basedOn w:val="Normale"/>'
             . '<w:pPr><w:spacing w:after="0" w:line="240" w:lineRule="auto"/>'
             . '<w:shd w:val="clear" w:color="auto" w:fill="F4F4F4"/></w:pPr>'
             . '<w:rPr><w:rFonts w:ascii="Consolas" w:hAnsi="Consolas"/><w:sz w:val="19"/></w:rPr></w:style>';

        $xml .= '<w:style w:type="paragraph" w:styleId="Elenco"><w:name w:val="List Paragraph"/>'
             . '<w:basedOn w:val="Normale"/><w:pPr><w:spacing w:after="60"/></w:pPr></w:style>';

        $xml .= '<w:style w:type="table" w:styleId="Griglia"><w:name w:val="Table Grid"/></w:style>';

        return $xml . '</w:styles>';
    }

    private function numerazioni(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:numbering xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">';

        foreach ([1 => false, 2 => true] as $astratto => $numerato) {
            $xml .= '<w:abstractNum w:abstractNumId="' . $astratto . '">';
            for ($i = 0; $i < 9; $i++) {
                $rientro = 360 + $i * 360;
                $xml .= '<w:lvl w:ilvl="' . $i . '">'
                     . '<w:start w:val="1"/>'
                     . '<w:numFmt w:val="' . ($numerato ? 'decimal' : 'bullet') . '"/>'
                     . '<w:lvlText w:val="' . ($numerato ? '%' . ($i + 1) . '.' : '&#8226;') . '"/>'
                     . '<w:lvlJc w:val="left"/>'
                     . '<w:pPr><w:ind w:left="' . $rientro . '" w:hanging="360"/></w:pPr>'
                     . ($numerato ? '' : '<w:rPr><w:rFonts w:ascii="Symbol" w:hAnsi="Symbol" w:hint="default"/></w:rPr>')
                     . '</w:lvl>';
            }
            $xml .= '</w:abstractNum>';
        }

        $xml .= '<w:num w:numId="1"><w:abstractNumId w:val="1"/></w:num>'
             . '<w:num w:numId="2"><w:abstractNumId w:val="2"/></w:num>';

        return $xml . '</w:numbering>';
    }
}
