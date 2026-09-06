<?php
declare(strict_types=1);

namespace Vblite\Convert\Conversioni\Tabelle;

/**
 * Legge una tabella da CSV o XLSX, una riga alla volta.
 *
 * Non carica il foglio in memoria: le righe si scorrono con un generatore, e
 * un file da decine di migliaia di righe passa senza avvicinarsi al tetto
 * dell'hosting. L'unica cosa che sta in memoria per intero è la tavola delle
 * stringhe condivise di un XLSX — è il formato a volerla così, e la si tiene
 * sotto controllo col limite di dimensione del file in ingresso.
 */
final class LettoreTabella
{
    /** I formati di data incorporati in XLSX, per riconoscere le celle data. */
    private const FORMATI_DATA = [14, 15, 16, 17, 18, 19, 20, 21, 22, 45, 46, 47];

    /** @var list<string> le intestazioni lette dalla prima riga */
    private array $intestazioni = [];

    public function __construct(private readonly string $percorso)
    {
    }

    public static function riconosciuto(string $percorso): bool
    {
        return in_array(strtolower(pathinfo($percorso, PATHINFO_EXTENSION)), ['csv', 'tsv', 'txt', 'xlsx'], true);
    }

    /** @return list<string> */
    public function intestazioni(): array
    {
        if ($this->intestazioni === []) {
            foreach ($this->righe(1) as $riga) {
                $this->intestazioni = $this->nomiUnivoci($riga);
                break;
            }
        }

        return $this->intestazioni;
    }

    /**
     * Le righe di dati, senza l'intestazione.
     *
     * @return \Generator<int,list<string>>
     */
    public function dati(): \Generator
    {
        $prima = true;
        foreach ($this->righe() as $riga) {
            if ($prima) {
                $prima = false;
                continue;
            }
            yield $riga;
        }
    }

    /**
     * Le prime righe, per l'anteprima dello step 2.
     *
     * @return list<list<string>>
     */
    public function assaggio(int $quante = 3): array
    {
        $righe = [];
        foreach ($this->dati() as $riga) {
            $righe[] = $riga;
            if (count($righe) >= $quante) {
                break;
            }
        }

        return $righe;
    }

    /** @return \Generator<int,list<string>> */
    private function righe(int $massimo = 0): \Generator
    {
        $estensione = strtolower(pathinfo($this->percorso, PATHINFO_EXTENSION));

        yield from $estensione === 'xlsx'
            ? $this->righeXlsx($massimo)
            : $this->righeCsv($massimo);
    }

    // ── CSV ──────────────────────────────────────────────────────────────────

    /** @return \Generator<int,list<string>> */
    private function righeCsv(int $massimo): \Generator
    {
        $f = fopen($this->percorso, 'r');
        if ($f === false) {
            throw new \RuntimeException("Non riesco a leggere {$this->percorso}");
        }

        // Il segno d'ordine dei byte, se c'è, non è un dato.
        $inizio = fread($f, 3);
        if ($inizio !== "\xEF\xBB\xBF") {
            rewind($f);
        }

        $separatore = $this->separatore();
        $lette      = 0;

        while (($riga = fgetcsv($f, 0, $separatore, '"', '\\')) !== false) {
            if ($riga === [null] || $riga === []) {
                continue;   // riga vuota
            }
            yield array_map(fn($c): string => $this->pulisci((string) ($c ?? '')), $riga);

            if ($massimo > 0 && ++$lette >= $massimo) {
                break;
            }
        }
        fclose($f);
    }

    /**
     * Il separatore, indovinato dalla prima riga.
     *
     * Un CSV italiano usa quasi sempre il punto e virgola, perché la virgola
     * è già il separatore decimale; ma arrivano file di ogni provenienza, e
     * chiederlo all'utente sarebbe una domanda a cui il file sa già rispondere.
     */
    private function separatore(): string
    {
        if (strtolower(pathinfo($this->percorso, PATHINFO_EXTENSION)) === 'tsv') {
            return "\t";
        }

        $prima = '';
        $f = fopen($this->percorso, 'r');
        if ($f !== false) {
            $prima = (string) fgets($f, 8192);
            fclose($f);
        }

        $migliore = ';';
        $quanti   = 0;
        foreach ([';', ',', "\t", '|'] as $candidato) {
            $n = substr_count($prima, $candidato);
            if ($n > $quanti) {
                $quanti   = $n;
                $migliore = $candidato;
            }
        }

        return $migliore;
    }

    // ── XLSX ─────────────────────────────────────────────────────────────────

    /** @return \Generator<int,list<string>> */
    private function righeXlsx(int $massimo): \Generator
    {
        $zip = new \ZipArchive();
        if ($zip->open($this->percorso) !== true) {
            throw new \RuntimeException('Il file non è un XLSX leggibile.');
        }

        $condivise = $this->stringheCondivise($zip);
        $stiliData = $this->stiliData($zip);

        $foglio = $this->primoFoglio($zip);
        $xml    = $zip->getFromName($foglio);
        $zip->close();

        if ($xml === false) {
            throw new \RuntimeException('Il foglio di calcolo è vuoto o illeggibile.');
        }

        $lettore = new \XMLReader();
        $lettore->XML($xml, 'UTF-8', LIBXML_NOENT | LIBXML_NONET);
        unset($xml);

        $lette = 0;
        if (!$lettore->read()) {
            $lettore->close();

            return;
        }

        while ($lettore->nodeType !== \XMLReader::NONE) {
            if ($lettore->nodeType === \XMLReader::ELEMENT && $lettore->name === 'row') {
                $riga = $this->cellePerRiga((string) $lettore->readOuterXml(), $condivise, $stiliData);
                if ($riga !== []) {
                    yield $riga;
                    if ($massimo > 0 && ++$lette >= $massimo) {
                        break;
                    }
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
     * Le celle di una riga, messe al loro posto.
     *
     * XLSX salta le celle vuote invece di scriverle: senza guardare il
     * riferimento (A1, C1…) le colonne scivolerebbero a sinistra e ogni riga
     * con un buco sballerebbe la mappatura.
     *
     * @param list<string>      $condivise
     * @param array<int,bool>   $stiliData
     * @return list<string>
     */
    private function cellePerRiga(string $xml, array $condivise, array $stiliData): array
    {
        // La cattura degli attributi va pigra: se e' avida si mangia anche la
        // barra di una cella vuota autochiusa — «<c r="A3"/>» — e la cella
        // finisce per assorbire il contenuto di quella dopo, spostando tutta
        // la riga di una colonna.
        if (preg_match_all('~<c\b([^>]*?)(?:/>|>(.*?)</c>)~s', $xml, $celle, PREG_SET_ORDER) === 0) {
            return [];
        }

        $valori = [];
        $ultima = -1;

        foreach ($celle as $cella) {
            $attributi = $cella[1];
            $dentro    = $cella[2] ?? '';

            $colonna = preg_match('~\br="([A-Z]+)~', $attributi, $r) === 1
                ? $this->indiceColonna($r[1])
                : $ultima + 1;
            $ultima = $colonna;

            $tipo  = preg_match('~\bt="([^"]+)"~', $attributi, $t) === 1 ? $t[1] : '';
            $stile = preg_match('~\bs="(\d+)"~', $attributi, $s) === 1 ? (int) $s[1] : 0;

            $valore = '';
            if ($tipo === 'inlineStr') {
                if (preg_match_all('~<t[^>]*>(.*?)</t>~s', $dentro, $pezzi) > 0) {
                    $valore = implode('', $pezzi[1]);
                }
            } elseif (preg_match('~<v>(.*?)</v>~s', $dentro, $v) === 1) {
                $valore = $v[1];
                if ($tipo === 's') {
                    $valore = $condivise[(int) $valore] ?? '';
                }
            }

            $valore = html_entity_decode($valore, ENT_QUOTES | ENT_XML1, 'UTF-8');

            // Una data in XLSX è un numero: senza guardare lo stile uscirebbe
            // «45658» invece di «01/01/2025».
            if ($valore !== '' && $tipo === '' && isset($stiliData[$stile]) && is_numeric($valore)) {
                $valore = $this->dataDaSeriale((float) $valore);
            }

            $valori[$colonna] = $this->pulisci($valore);
        }

        if ($valori === []) {
            return [];
        }

        // I buchi si riempiono, così ogni riga ha la stessa forma.
        $piena = [];
        for ($i = 0; $i <= max(array_keys($valori)); $i++) {
            $piena[] = $valori[$i] ?? '';
        }

        return trim(implode('', $piena)) === '' ? [] : $piena;
    }

    /** «A» → 0, «B» → 1, «AA» → 26 */
    private function indiceColonna(string $lettere): int
    {
        $n = 0;
        foreach (str_split($lettere) as $c) {
            $n = $n * 26 + (ord($c) - 64);
        }

        return $n - 1;
    }

    /** @return list<string> */
    private function stringheCondivise(\ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        $lista = [];
        if (preg_match_all('~<si\b[^>]*>(.*?)</si>~s', $xml, $voci) > 0) {
            foreach ($voci[1] as $voce) {
                $testo = '';
                if (preg_match_all('~<t[^>]*>(.*?)</t>~s', $voce, $pezzi) > 0) {
                    $testo = implode('', $pezzi[1]);
                }
                $lista[] = html_entity_decode($testo, ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }

        return $lista;
    }

    /**
     * Quali stili sono formati data.
     *
     * @return array<int,bool> indice di stile → è una data?
     */
    private function stiliData(\ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/styles.xml');
        if ($xml === false) {
            return [];
        }

        // I formati personalizzati si riconoscono dal codice: se contiene
        // giorni, mesi o anni è una data.
        $personalizzati = [];
        if (preg_match_all('~<numFmt\b[^>]*numFmtId="(\d+)"[^>]*formatCode="([^"]*)"~i', $xml, $formati, PREG_SET_ORDER) > 0) {
            foreach ($formati as $formato) {
                $codice = strtolower(html_entity_decode($formato[2], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                // Si tolgono le parti fra virgolette: «"kg"» non è un mese.
                $codice = (string) preg_replace('~"[^"]*"~', '', $codice);
                $personalizzati[(int) $formato[1]] = preg_match('~[dmy]~', $codice) === 1
                    && preg_match('~[#0]~', $codice) !== 1;
            }
        }

        $stili = [];
        if (preg_match('~<cellXfs\b[^>]*>(.*?)</cellXfs>~s', $xml, $blocco) === 1
            && preg_match_all('~<xf\b([^>]*)~', $blocco[1], $xf) > 0) {
            foreach ($xf[1] as $indice => $attributi) {
                $id = preg_match('~numFmtId="(\d+)"~', $attributi, $n) === 1 ? (int) $n[1] : 0;
                if (in_array($id, self::FORMATI_DATA, true) || ($personalizzati[$id] ?? false)) {
                    $stili[$indice] = true;
                }
            }
        }

        return $stili;
    }

    private function primoFoglio(\ZipArchive $zip): string
    {
        // Il primo foglio dello workbook, non il primo file dello zip: l'ordine
        // dentro un archivio non vuol dire niente.
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        $book = $zip->getFromName('xl/workbook.xml');

        if ($book !== false && $rels !== false
            && preg_match('~<sheet\b[^>]*r:id="([^"]+)"~i', $book, $s) === 1
            && preg_match('~<Relationship[^>]*Id="' . preg_quote($s[1], '~') . '"[^>]*Target="([^"]+)"~i', $rels, $t) === 1) {
            return 'xl/' . ltrim($t[1], '/');
        }

        return 'xl/worksheets/sheet1.xml';
    }

    /** Seriale Excel → «gg/mm/aaaa». Epoca 1899-12-30, come nel resto dell'app. */
    private function dataDaSeriale(float $seriale): string
    {
        if ($seriale < 1 || $seriale > 2958465) {
            return rtrim(rtrim(number_format($seriale, 6, '.', ''), '0'), '.');
        }

        return gmdate('d/m/Y', ((int) $seriale - 25569) * 86400);
    }

    private function pulisci(string $valore): string
    {
        if (!mb_check_encoding($valore, 'UTF-8')) {
            $valore = (string) mb_convert_encoding($valore, 'UTF-8', 'Windows-1252');
        }

        return trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $valore));
    }

    /**
     * Nomi di colonna utilizzabili: niente vuoti, niente doppioni.
     * Servono come chiavi della mappatura, e due colonne con lo stesso nome
     * renderebbero ambigua ogni regola.
     *
     * @param list<string> $riga
     * @return list<string>
     */
    private function nomiUnivoci(array $riga): array
    {
        $nomi  = [];
        $visti = [];

        foreach ($riga as $i => $grezzo) {
            $nome = trim($grezzo);
            if ($nome === '') {
                $nome = 'Colonna ' . ($i + 1);
            }
            if (isset($visti[$nome])) {
                $visti[$nome]++;
                $nome .= ' (' . $visti[$nome] . ')';
            } else {
                $visti[$nome] = 1;
            }
            $nomi[] = $nome;
        }

        return $nomi;
    }
}
