<?php
declare(strict_types=1);

namespace Vblite\Convert\Conversioni\Documenti\Lettori;

use Vblite\Convert\Conversioni\Documenti\Blocco;
use Vblite\Convert\Conversioni\Documenti\Documento;
use Vblite\Convert\Conversioni\Documenti\Testo;

/**
 * Word (.docx) → modello.
 *
 * Un .docx è uno zip con dentro XML strutturato, e la struttura è vera: gli
 * stili dicono quali paragrafi sono titoli, w:numPr quali sono elenchi, w:b e
 * w:i i tratti. È il formato in ingresso che si converte meglio.
 *
 * Si legge con XMLReader, a flusso: un document.xml di qualche megabyte
 * caricato in un DOM ne occuperebbe dieci volte tanti, e qui il tetto è basso.
 */
final class LettoreDocx implements Lettore
{
    /** Gli stili «titolo» cambiano nome con la lingua di Word. */
    private const TITOLI = '~^(heading|titolo|t[ií]tulo|titre|berschrift|overskrift|kop)\s*([1-6])$~i';

    /** @var array<string,string> rId → nome del file in word/media */
    private array $relazioni = [];

    /** @var array<string,bool> id di numerazione → è numerato? */
    private array $numerazioni = [];

    public static function estensioni(): array
    {
        return ['docx'];
    }

    public static function nome(): string
    {
        return 'Word (.docx)';
    }

    public function leggi(string $percorso, string $cartellaMedia, ?Documento $documento = null): Documento
    {
        $documento ??= new Documento();

        $zip = new \ZipArchive();
        if ($zip->open($percorso) !== true) {
            throw new \RuntimeException('Il file non è un .docx leggibile.');
        }

        $xml = $zip->getFromName('word/document.xml');
        if ($xml === false) {
            $zip->close();
            throw new \RuntimeException('Manca word/document.xml: non è un documento Word.');
        }

        $this->relazioni   = $this->leggiRelazioni($zip);
        $this->numerazioni = $this->leggiNumerazioni($zip);
        $this->estraiImmagini($zip, $cartellaMedia, $documento);
        $zip->close();

        $this->analizza($xml, $documento);
        unset($xml);

        $documento->concludi();

        return $documento;
    }

    /**
     * Scorre il corpo del documento.
     *
     * Attenzione al cursore: dopo readOuterXml() serve next() per passare al
     * fratello, ma NON un read() dopo — read() entrerebbe dentro quel fratello
     * saltandolo come elemento. Scritto con «while (read())» il lettore
     * processava un paragrafo su due, e il documento usciva dimezzato senza
     * che niente lo segnalasse.
     */
    private function analizza(string $xml, Documento $documento): void
    {
        $lettore = new \XMLReader();
        $lettore->XML($xml, 'UTF-8', LIBXML_NOENT | LIBXML_NONET);

        if (!$lettore->read()) {
            $lettore->close();

            return;
        }

        while ($lettore->nodeType !== \XMLReader::NONE) {
            if ($lettore->nodeType === \XMLReader::ELEMENT
                && ($lettore->name === 'w:p' || $lettore->name === 'w:tbl')) {
                $frammento = (string) $lettore->readOuterXml();

                if ($lettore->name === 'w:tbl') {
                    $this->tabella($frammento, $documento);
                } else {
                    $this->paragrafo($frammento, $documento);
                }

                if (!$lettore->next()) {
                    break;
                }
                continue;
            }

            if (!$lettore->read()) {
                break;
            }
        }

        $lettore->close();
    }

    /**
     * Un paragrafo Word puo' contenere testo e immagini insieme: si rendono
     * entrambi, prima il testo e poi le immagini. Trattarlo come «o l'uno o le
     * altre» faceva sparire il testo dei paragrafi illustrati.
     */
    private function paragrafo(string $xml, Documento $documento): void
    {
        $blocco = $this->daParagrafo($xml);
        if ($blocco !== null) {
            $documento->aggiungi($blocco);
        }

        if (preg_match_all('~r:embed="([^"]+)"~', $xml, $trovate) > 0) {
            foreach (array_unique($trovate[1]) as $id) {
                if (isset($this->relazioni[$id])) {
                    $documento->aggiungi(Blocco::immagine('media/' . $this->nomeSicuro($this->relazioni[$id])));
                }
            }
        }
    }

    private function daParagrafo(string $xml): ?Blocco
    {
        $tratti = $this->tratti($xml);

        // Un paragrafo vuoto con solo un bordo inferiore è una riga
        // orizzontale: è così che Word rende un separatore.
        if ($tratti === []) {
            return preg_match('~<w:pBdr>.*?<w:bottom~s', $xml) === 1 ? Blocco::riga() : null;
        }

        $stile = preg_match('~<w:pStyle w:val="([^"]+)"~', $xml, $m) === 1 ? $m[1] : '';

        if (preg_match(self::TITOLI, $stile, $t) === 1) {
            return Blocco::titolo((int) $t[2], $tratti);
        }
        // Alcuni modelli chiamano gli stili solo col numero.
        if (preg_match('~^([1-6])$~', $stile, $t) === 1) {
            return Blocco::titolo((int) $t[1], $tratti);
        }
        if (preg_match('~^(quote|citazione|cita|intensequote)~i', $stile) === 1) {
            return Blocco::citazione($tratti);
        }
        if (preg_match('~^(codice|code|preformatted|html\s*pre)~i', $stile) === 1) {
            return Blocco::codice(Testo::nudo($tratti));
        }

        if (preg_match('~<w:numPr>~', $xml) === 1) {
            $livello = preg_match('~<w:ilvl w:val="(\d+)"~', $xml, $l) === 1 ? (int) $l[1] : 0;
            $numId   = preg_match('~<w:numId w:val="(\d+)"~', $xml, $n) === 1 ? $n[1] : '';

            return Blocco::elenco($tratti, $this->numerazioni[$numId] ?? false, $livello);
        }

        return Blocco::paragrafo($tratti);
    }

    /**
     * I tratti di testo di un paragrafo, con grassetto, corsivo e collegamenti.
     *
     * @return list<Testo>
     */
    private function tratti(string $xml): array
    {
        $tratti = [];

        // Si scorre <w:r> per <w:r>: ognuno porta i propri attributi in <w:rPr>.
        if (preg_match_all('~<w:hyperlink[^>]*r:id="([^"]*)"[^>]*>(.*?)</w:hyperlink>|<w:r(?:\s[^>]*)?>(.*?)</w:r>~s', $xml, $trovati, PREG_SET_ORDER) === 0) {
            return [];
        }

        foreach ($trovati as $t) {
            $collegamento = null;
            if (($t[1] ?? '') !== '') {
                $collegamento = $this->relazioni['link:' . $t[1]] ?? null;
                $contenuto    = $t[2];
                foreach ($this->testiDi($contenuto) as $pezzo) {
                    $tratti[] = new Testo($pezzo['testo'], $pezzo['b'], $pezzo['i'], false, $collegamento);
                }
                continue;
            }

            $corpo = $t[3] ?? '';
            $prop  = preg_match('~<w:rPr>(.*?)</w:rPr>~s', $corpo, $p) === 1 ? $p[1] : '';
            $b     = preg_match('~<w:b(?:\s+w:val="(?!0|false)[^"]*")?\s*/?>~', $prop) === 1;
            $i     = preg_match('~<w:i(?:\s+w:val="(?!0|false)[^"]*")?\s*/?>~', $prop) === 1;
            $mono  = self::monospazio($prop);

            $testo = $this->testoDiCorsa($corpo);
            if ($testo !== '') {
                $tratti[] = new Testo($testo, $b, $i, $mono);
            }
        }

        return Testo::unisci($tratti);
    }

    /**
     * Un carattere a spaziatura fissa vuol dire «codice».
     *
     * Non è una certezza — qualcuno scrive in Courier per gusto — ma è
     * l'unico indizio che Word lascia, e sbagliare qui costa poco: nel
     * Markdown diventano apici, non si perde testo.
     */
    private static function monospazio(string $prop): bool
    {
        return preg_match('~<w:rFonts[^>]*(?:ascii|hAnsi)="(Consolas|Courier[^"]*|Menlo|Monaco|Lucida Console|Cascadia[^"]*)"~i', $prop) === 1;
    }

    /**
     * Il testo di una corsa, nell'ordine in cui compare.
     *
     * Gli a capo e le tabulazioni diventano spazi — il modello non li
     * rappresenta e non fanno un blocco a se' — ma vanno resi al loro posto:
     * Word scrive spesso <w:br/> PRIMA del testo, e mettendo lo spazio in
     * fondo si attaccavano le frasi.
     */
    private function testoDiCorsa(string $corpo): string
    {
        if (preg_match_all('~<w:t(?:\s[^>]*)?>(.*?)</w:t>|<w:(?:br|tab)\s*/?>~s', $corpo, $pezzi, PREG_SET_ORDER) === 0) {
            return '';
        }

        $testo = '';
        foreach ($pezzi as $pezzo) {
            $testo .= isset($pezzo[1]) && $pezzo[1] !== ''
                ? html_entity_decode($pezzo[1], ENT_QUOTES | ENT_XML1, 'UTF-8')
                : ' ';
        }

        return $testo;
    }

    /** @return list<array{testo:string,b:bool,i:bool}> */
    private function testiDi(string $xml): array
    {
        $fuori = [];
        if (preg_match_all('~<w:r(?:\s[^>]*)?>(.*?)</w:r>~s', $xml, $corse, PREG_SET_ORDER) === 0) {
            return [];
        }
        foreach ($corse as $corsa) {
            $prop = preg_match('~<w:rPr>(.*?)</w:rPr>~s', $corsa[1], $p) === 1 ? $p[1] : '';
            $testo = $this->testoDiCorsa($corsa[1]);
            if ($testo !== '') {
                $fuori[] = [
                    'testo' => $testo,
                    'b' => preg_match('~<w:b(?:\s+w:val="(?!0|false)[^"]*")?\s*/?>~', $prop) === 1,
                    'i' => preg_match('~<w:i(?:\s+w:val="(?!0|false)[^"]*")?\s*/?>~', $prop) === 1,
                ];
            }
        }

        return $fuori;
    }

    private function tabella(string $xml, Documento $documento): void
    {
        $righe = [];
        if (preg_match_all('~<w:tr(?:\s[^>]*)?>(.*?)</w:tr>~s', $xml, $trovate, PREG_SET_ORDER) === 0) {
            return;
        }

        foreach ($trovate as $riga) {
            // Una riga marcata come intestazione è in grassetto per decorazione:
            // tenerlo farebbe crescere gli asterischi a ogni andata e ritorno.
            $intestazione = preg_match('~<w:tblHeader\s*/?>~', $riga[1]) === 1;

            $celle = [];
            if (preg_match_all('~<w:tc(?:\s[^>]*)?>(.*?)</w:tc>~s', $riga[1], $trovateCelle, PREG_SET_ORDER) > 0) {
                foreach ($trovateCelle as $cella) {
                    $tratti = $this->tratti($cella[1]);
                    if ($intestazione) {
                        $tratti = array_map(
                            static fn(Testo $t): Testo => new Testo($t->testo, false, $t->corsivo, $t->codice, $t->collegamento),
                            $tratti
                        );
                    }
                    $celle[] = $tratti;
                }
            }
            if ($celle !== []) {
                $righe[] = $celle;
            }
        }

        if ($righe !== []) {
            $documento->aggiungi(Blocco::tabella($righe));
        }
    }

    /** @return array<string,string> */
    private function leggiRelazioni(\ZipArchive $zip): array
    {
        $xml = $zip->getFromName('word/_rels/document.xml.rels');
        if ($xml === false) {
            return [];
        }

        $mappa = [];
        if (preg_match_all('~<Relationship([^>]+)/>~', $xml, $trovate) > 0) {
            foreach ($trovate[1] as $attributi) {
                if (preg_match('~Id="([^"]+)"~', $attributi, $id) !== 1
                    || preg_match('~Target="([^"]+)"~', $attributi, $target) !== 1) {
                    continue;
                }
                if (str_contains($attributi, '/image')) {
                    $mappa[$id[1]] = basename($target[1]);
                } elseif (str_contains($attributi, '/hyperlink')) {
                    $mappa['link:' . $id[1]] = html_entity_decode($target[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
                }
            }
        }

        return $mappa;
    }

    /**
     * Quali numerazioni sono numerate e quali puntate.
     * Sta in numbering.xml, non nel paragrafo: il paragrafo cita solo un id.
     *
     * @return array<string,bool>
     */
    private function leggiNumerazioni(\ZipArchive $zip): array
    {
        $xml = $zip->getFromName('word/numbering.xml');
        if ($xml === false) {
            return [];
        }

        // numId → abstractNumId → formato del primo livello
        $formati = [];
        if (preg_match_all('~<w:abstractNum w:abstractNumId="(\d+)".*?</w:abstractNum>~s', $xml, $astratti, PREG_SET_ORDER) > 0) {
            foreach ($astratti as $astratto) {
                $numerato = preg_match('~<w:numFmt w:val="(?!bullet|none)[^"]+"~', $astratto[0]) === 1;
                $formati[$astratto[1]] = $numerato;
            }
        }

        $mappa = [];
        if (preg_match_all('~<w:num w:numId="(\d+)"[^>]*>.*?<w:abstractNumId w:val="(\d+)"~s', $xml, $numeri, PREG_SET_ORDER) > 0) {
            foreach ($numeri as $numero) {
                $mappa[$numero[1]] = $formati[$numero[2]] ?? false;
            }
        }

        return $mappa;
    }

    private function estraiImmagini(\ZipArchive $zip, string $cartellaMedia, Documento $documento): void
    {
        if ($this->relazioni === []) {
            return;
        }
        if (!is_dir($cartellaMedia)) {
            @mkdir($cartellaMedia, 0770, true);
        }

        foreach ($this->relazioni as $chiave => $nome) {
            if (str_starts_with((string) $chiave, 'link:')) {
                continue;
            }
            $dentro = $zip->getFromName('word/media/' . $nome);
            if ($dentro === false) {
                continue;
            }
            $sicuro       = $this->nomeSicuro($nome);
            $destinazione = $cartellaMedia . '/' . $sicuro;
            file_put_contents($destinazione, $dentro);
            $documento->registraImmagine('media/' . $sicuro, $destinazione);
            unset($dentro);
        }
    }

    /** Nessun percorso e nessun carattere strano nei nomi presi da un file altrui. */
    private function nomeSicuro(string $nome): string
    {
        $nome = basename($nome);

        return preg_replace('~[^A-Za-z0-9._-]~', '-', $nome) ?? 'immagine';
    }
}
