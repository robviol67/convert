<?php
declare(strict_types=1);

namespace Vblite\Convert\Supporto;

/**
 * Estrae un intervallo di pagine da un PDF, senza caricarlo.
 *
 * La libreria che legge i PDF li carica interi: l'albero degli oggetti di una
 * stampa da 1.849 pagine occupa 54 MB, e sull'hosting il processo viene ucciso
 * a venti. Qui il file si legge per byte — la tabella xref dice dove sta ogni
 * oggetto — e se ne copia solo quello che serve a un pezzo: le pagine scelte e
 * tutto quello a cui rimandano, font e risorse compresi. Il pezzo è un PDF
 * vero, piccolo, e la libreria lo legge senza problemi.
 *
 * Funziona sui PDF con tabella xref classica, che è quello che scrivono i
 * generatori di stampe (Crystal Reports compreso). Quelli con la xref
 * compressa (PDF 1.5 e oltre) o cifrati non si aprono: apri() lo dice
 * restituendo null, e chi chiama decide cosa fare.
 */
final class SpezzaPdf
{
    /** Attributi che una pagina eredita dai nodi sopra di lei. */
    private const EREDITABILI = ['Resources', 'MediaBox', 'CropBox', 'Rotate'];

    /** @var resource */
    private $f;

    /** @var array<int,int> oggetto → posizione nel file */
    private array $inizio = [];

    /** @var array<int,int> oggetto → dove finisce (l'inizio del successivo nel file) */
    private array $fine = [];

    /** @var list<int> le pagine, in ordine */
    private array $pagine = [];

    /** @var array<int,array<string,string>> pagina → attributi ereditati che non ha */
    private array $ereditati = [];

    /** @param resource $f */
    private function __construct($f)
    {
        $this->f = $f;
    }

    /** null se il file non ha la forma che sappiamo spezzare. */
    public static function apri(string $percorso): ?self
    {
        $f = @fopen($percorso, 'rb');
        if ($f === false) {
            return null;
        }

        $pdf = new self($f);
        try {
            if (!$pdf->leggiStruttura()) {
                $pdf->chiudi();

                return null;
            }
        } catch (\Throwable) {
            $pdf->chiudi();

            return null;
        }

        return $pdf;
    }

    public function pagine(): int
    {
        return count($this->pagine);
    }

    /**
     * Scrive un PDF con le pagine da $da (contando da 1) per $quante pagine.
     *
     * Gli oggetti si rinumerano da 1: un pezzo di trenta pagine non deve
     * portarsi dietro una tabella xref da settemila righe.
     */
    public function estrai(int $da, int $quante, string $destinazione): int
    {
        $scelte = array_slice($this->pagine, max(0, $da - 1), $quante);
        if ($scelte === []) {
            return 0;
        }

        $sonoPagine = array_flip($scelte);

        // Tutto quello a cui le pagine rimandano, esclusa la risalita all'albero:
        // /Parent porterebbe dentro l'intero documento.
        $servono = [];
        $daVedere = $scelte;
        while ($daVedere !== []) {
            $n = array_pop($daVedere);
            if (isset($servono[$n]) || !isset($this->inizio[$n])) {
                continue;
            }
            $servono[$n] = true;

            $dizionario = $this->dizionario($n);
            if (isset($sonoPagine[$n])) {
                $dizionario = self::togli($dizionario, 'Parent')
                    . ' ' . implode(' ', $this->ereditati[$n] ?? []);
            }
            foreach (self::riferimenti($dizionario) as $altro) {
                if (!isset($servono[$altro])) {
                    $daVedere[] = $altro;
                }
            }
        }

        $vecchi = array_keys($servono);
        sort($vecchi);
        $nuovo = [];
        foreach ($vecchi as $i => $vecchio) {
            $nuovo[$vecchio] = $i + 1;
        }
        $numeroPagine  = count($vecchi) + 1;
        $numeroCatalogo = count($vecchi) + 2;

        $out = fopen($destinazione, 'wb');
        if ($out === false) {
            throw new \RuntimeException("Non riesco a scrivere {$destinazione}");
        }
        $posizioni = [];
        $scritti   = 0;
        $scrivi = static function (string $s) use ($out, &$scritti): void {
            fwrite($out, $s);
            $scritti += strlen($s);
        };

        $scrivi("%PDF-1.4\n%\xE2\xE3\xCF\xD3\n");

        $rinumera = static fn(string $testo): string => (string) preg_replace_callback(
            '~\b(\d+)\s+(\d+)\s+R\b~',
            static fn(array $m): string => isset($nuovo[(int) $m[1]]) ? $nuovo[(int) $m[1]] . ' 0 R' : 'null',
            $testo
        );

        foreach ($vecchi as $vecchio) {
            $posizioni[$nuovo[$vecchio]] = $scritti;
            [$dizionario, $flusso] = $this->pezzi($vecchio);

            if (isset($sonoPagine[$vecchio])) {
                // La pagina punta al nuovo albero, e si porta dietro quello che
                // prima ereditava: nel pezzo non c'è più chi glielo dia.
                $dizionario = self::togli($dizionario, 'Parent');
                $aggiunte   = '/Parent ' . $numeroPagine . ' 0 R ' . implode(' ', $this->ereditati[$vecchio] ?? []);
                $dizionario = (string) preg_replace('~<<~', '<< ' . $aggiunte . ' ', $dizionario, 1);
            }

            $scrivi($nuovo[$vecchio] . " 0 obj\n" . $rinumera($dizionario));
            if ($flusso !== null) {
                $scrivi("\nstream\n");
                $this->copia($flusso[0], $flusso[1], $out, $scritti);
                $scrivi("\nendstream");
            }
            $scrivi("\nendobj\n");
        }

        $figli = implode(' ', array_map(static fn(int $p): string => $nuovo[$p] . ' 0 R', $scelte));
        $posizioni[$numeroPagine] = $scritti;
        $scrivi("{$numeroPagine} 0 obj\n<< /Type /Pages /Kids [ {$figli} ] /Count " . count($scelte) . " >>\nendobj\n");
        $posizioni[$numeroCatalogo] = $scritti;
        $scrivi("{$numeroCatalogo} 0 obj\n<< /Type /Catalog /Pages {$numeroPagine} 0 R >>\nendobj\n");

        $xref = $scritti;
        $totale = $numeroCatalogo + 1;
        $scrivi("xref\n0 {$totale}\n0000000000 65535 f \n");
        for ($n = 1; $n < $totale; $n++) {
            $scrivi(sprintf("%010d 00000 n \n", $posizioni[$n]));
        }
        $scrivi("trailer\n<< /Size {$totale} /Root {$numeroCatalogo} 0 R >>\nstartxref\n{$xref}\n%%EOF\n");
        fclose($out);

        return count($scelte);
    }

    public function chiudi(): void
    {
        if (is_resource($this->f)) {
            fclose($this->f);
        }
    }

    // ── Lettura della struttura ─────────────────────────────────────────────

    private function leggiStruttura(): bool
    {
        $dimensione = (int) fstat($this->f)['size'];
        fseek($this->f, max(0, $dimensione - 2048));
        $coda = (string) fread($this->f, 2048);
        if (preg_match_all('~startxref\s+(\d+)~', $coda, $m) === 0) {
            return false;
        }
        $posizione = (int) end($m[1]);

        $radice = null;
        $confini = [$dimensione];
        $visitate = [];

        // Le sezioni si seguono all'indietro con /Prev: la più recente vince.
        while ($posizione > 0 && !isset($visitate[$posizione])) {
            $visitate[$posizione] = true;
            $confini[] = $posizione;
            fseek($this->f, $posizione);

            if (trim((string) fgets($this->f)) !== 'xref') {
                return false;   // xref compressa: non è un caso che gestiamo
            }

            while (($riga = fgets($this->f)) !== false) {
                $riga = trim($riga);
                if ($riga === '') {
                    continue;
                }
                if (str_starts_with($riga, 'trailer')) {
                    break;
                }
                if (preg_match('~^(\d+)\s+(\d+)$~', $riga, $sez) !== 1) {
                    return false;
                }
                [$primo, $quanti] = [(int) $sez[1], (int) $sez[2]];
                for ($i = 0; $i < $quanti; $i++) {
                    $voce = (string) fgets($this->f);
                    if (preg_match('~^(\d{10})\s+(\d{5})\s+([nf])~', $voce, $v) !== 1) {
                        return false;
                    }
                    $n = $primo + $i;
                    if ($v[3] === 'n' && !isset($this->inizio[$n])) {
                        $this->inizio[$n] = (int) $v[1];
                    }
                }
            }

            $trailer = (string) fread($this->f, 1024);
            if (str_contains($trailer, '/Encrypt')) {
                return false;
            }
            if ($radice === null && preg_match('~/Root\s+(\d+)\s+\d+\s+R~', $trailer, $r) === 1) {
                $radice = (int) $r[1];
            }
            $posizione = preg_match('~/Prev\s+(\d+)~', $trailer, $p) === 1 ? (int) $p[1] : 0;
        }

        if ($radice === null || $this->inizio === []) {
            return false;
        }

        // Un oggetto finisce dove comincia il primo oggetto che lo segue nel
        // file — non il successivo per numero: i generatori li scrivono in
        // ordine sparso.
        $tutti = array_merge(array_values($this->inizio), $confini);
        sort($tutti);
        $tutti = array_values(array_unique($tutti));
        $dopo  = [];
        foreach ($tutti as $i => $pos) {
            $dopo[$pos] = $tutti[$i + 1] ?? $dimensione;
        }
        foreach ($this->inizio as $n => $pos) {
            $this->fine[$n] = $dopo[$pos];
        }

        $catalogo = $this->dizionario($radice);
        if (preg_match('~/Pages\s+(\d+)\s+\d+\s+R~', $catalogo, $pp) !== 1) {
            return false;
        }
        $this->albero((int) $pp[1], []);

        return $this->pagine !== [];
    }

    /**
     * Scende nell'albero delle pagine, portandosi dietro quello che si eredita.
     *
     * @param array<string,string> $eredita
     */
    private function albero(int $nodo, array $eredita, int $profondita = 0): void
    {
        if ($profondita > 64) {
            return;   // un albero così profondo è un file rotto, non una stampa
        }
        $dizionario = $this->dizionario($nodo);
        $proprio    = [];
        foreach (self::EREDITABILI as $chiave) {
            $valore = self::valore($dizionario, $chiave);
            if ($valore !== null) {
                $proprio[$chiave] = $valore;
            }
        }

        if (preg_match('~/Type\s*/Pages\b~', $dizionario) === 1) {
            $figli = self::valore($dizionario, 'Kids') ?? '';
            foreach (self::riferimenti($figli) as $figlio) {
                $this->albero($figlio, $proprio + $eredita, $profondita + 1);
            }

            return;
        }

        $this->pagine[] = $nodo;
        $mancanti = [];
        foreach ($eredita as $chiave => $valore) {
            if (!isset($proprio[$chiave])) {
                $mancanti[$chiave] = '/' . $chiave . ' ' . $valore;
            }
        }
        if ($mancanti !== []) {
            $this->ereditati[$nodo] = $mancanti;
        }
    }

    // ── Lettura degli oggetti ───────────────────────────────────────────────

    /** Il dizionario di un oggetto, senza il flusso. */
    private function dizionario(int $n): string
    {
        return $this->pezzi($n)[0];
    }

    /**
     * Dizionario e, se c'è, dove sta il flusso nel file.
     *
     * @return array{0:string,1:array{0:int,1:int}|null}
     */
    private function pezzi(int $n): array
    {
        $da  = $this->inizio[$n];
        $a   = $this->fine[$n];
        $leggi = min($a - $da, 262144);

        fseek($this->f, $da);
        $testa = (string) fread($this->f, $leggi);

        // Via l'intestazione «N G obj».
        $corpo = (string) preg_replace('~^\s*\d+\s+\d+\s+obj\b~', '', $testa, 1);
        $tolti = strlen($testa) - strlen($corpo);

        if (preg_match('~(?<![a-z])stream\r?\n~', $corpo, $s, PREG_OFFSET_CAPTURE) !== 1) {
            $fineOggetto = strrpos($corpo, 'endobj');

            return [trim($fineOggetto === false ? $corpo : substr($corpo, 0, $fineOggetto)), null];
        }

        $dizionario = trim(substr($corpo, 0, $s[0][1]));
        $inizioFlusso = $da + $tolti + $s[0][1] + strlen($s[0][0]);

        // La misura giusta del flusso è /Length. Tagliare a occhio l'a capo
        // prima di «endstream» rischia di mangiarsi l'ultimo byte dei dati
        // compressi quando è proprio un a capo — e il flusso non si apre più.
        $lunghezza = $this->lunghezza($dizionario);
        if ($lunghezza !== null && $inizioFlusso + $lunghezza <= $a) {
            return [$dizionario, [$inizioFlusso, $inizioFlusso + $lunghezza]];
        }

        // Senza /Length leggibile, la fine si cerca dal fondo dell'oggetto: i
        // dati binari possono contenere qualunque sequenza, il fondo no.
        $coda = min(512, $a - $inizioFlusso);
        fseek($this->f, $a - $coda);
        $ultimi = (string) fread($this->f, $coda);
        $pos = strrpos($ultimi, 'endstream');
        if ($pos === false) {
            throw new \RuntimeException("Oggetto {$n}: flusso senza fine");
        }
        $fineFlusso = $a - $coda + $pos;
        // L'a capo prima di «endstream» non fa parte dei dati.
        fseek($this->f, max($inizioFlusso, $fineFlusso - 2));
        $prima = (string) fread($this->f, $fineFlusso - max($inizioFlusso, $fineFlusso - 2));
        $fineFlusso -= strlen($prima) - strlen(rtrim($prima, "\r\n"));

        return [$dizionario, [$inizioFlusso, $fineFlusso]];
    }

    /** /Length, anche quando sta in un oggetto a parte. */
    private function lunghezza(string $dizionario): ?int
    {
        $valore = self::valore($dizionario, 'Length');
        if ($valore === null) {
            return null;
        }
        if (preg_match('~^\d+$~', $valore) === 1) {
            return (int) $valore;
        }
        if (preg_match('~^(\d+)\s+\d+\s+R$~', $valore, $m) === 1 && isset($this->inizio[(int) $m[1]])) {
            $testo = trim($this->pezzi((int) $m[1])[0]);

            return preg_match('~^\d+$~', $testo) === 1 ? (int) $testo : null;
        }

        return null;
    }

    /** @param resource $out */
    private function copia(int $da, int $a, $out, int &$scritti): void
    {
        fseek($this->f, $da);
        $resta = $a - $da;
        while ($resta > 0) {
            $pezzo = (string) fread($this->f, min(65536, $resta));
            if ($pezzo === '') {
                break;
            }
            fwrite($out, $pezzo);
            $scritti += strlen($pezzo);
            $resta   -= strlen($pezzo);
        }
    }

    // ── Dizionari, senza un parser completo ─────────────────────────────────

    /** @return list<int> */
    private static function riferimenti(string $testo): array
    {
        preg_match_all('~\b(\d+)\s+\d+\s+R\b~', $testo, $m);

        return array_map('intval', $m[1]);
    }

    /**
     * Il valore di una chiave al primo livello del dizionario, così com'è scritto.
     *
     * Basta a riconoscere riferimenti, numeri, nomi, vettori e dizionari:
     * quello che serve per ereditare MediaBox e Resources, non di più.
     */
    private static function valore(string $dizionario, string $chiave): ?string
    {
        $inizio = self::posizioneChiave($dizionario, $chiave);
        if ($inizio === null) {
            return null;
        }
        $resto = ltrim(substr($dizionario, $inizio + strlen($chiave) + 1));

        if (preg_match('~^\d+\s+\d+\s+R\b~', $resto, $m) === 1) {
            return $m[0];
        }
        if ($resto !== '' && ($resto[0] === '[' || str_starts_with($resto, '<<'))) {
            return self::racchiuso($resto);
        }
        if (preg_match('~^(/[^\s/<>\[\]()]+|[-+]?[\d.]+|true|false|null)~', $resto, $m) === 1) {
            return $m[0];
        }

        return null;
    }

    /** Toglie una chiave e il suo valore, al primo livello. */
    private static function togli(string $dizionario, string $chiave): string
    {
        $inizio = self::posizioneChiave($dizionario, $chiave);
        $valore = self::valore($dizionario, $chiave);
        if ($inizio === null || $valore === null) {
            return $dizionario;
        }
        $fine = strpos($dizionario, $valore, $inizio) + strlen($valore);

        return substr($dizionario, 0, $inizio) . ' ' . substr($dizionario, $fine);
    }

    /** Dove sta «/Chiave» al primo livello del dizionario esterno. */
    private static function posizioneChiave(string $dizionario, string $chiave): ?int
    {
        $livello = 0;
        $lunghezza = strlen($dizionario);
        for ($i = 0; $i < $lunghezza; $i++) {
            $c = $dizionario[$i];
            if ($c === '(') {
                $i = self::saltaStringa($dizionario, $i);
                continue;
            }
            if ($c === '<' && ($dizionario[$i + 1] ?? '') === '<') {
                $livello++;
                $i++;
                continue;
            }
            if ($c === '>' && ($dizionario[$i + 1] ?? '') === '>') {
                $livello--;
                $i++;
                continue;
            }
            if ($c === '[') {
                $livello++;
                continue;
            }
            if ($c === ']') {
                $livello--;
                continue;
            }
            if ($c === '/' && $livello === 1
                && substr($dizionario, $i + 1, strlen($chiave)) === $chiave
                && preg_match('~[\s/<>\[\]()]~', $dizionario[$i + 1 + strlen($chiave)] ?? ' ') === 1) {
                return $i;
            }
        }

        return null;
    }

    /** Un vettore o un dizionario, dall'apertura alla chiusura che le corrisponde. */
    private static function racchiuso(string $testo): string
    {
        $livello = 0;
        $lunghezza = strlen($testo);
        for ($i = 0; $i < $lunghezza; $i++) {
            $c = $testo[$i];
            if ($c === '(') {
                $i = self::saltaStringa($testo, $i);
                continue;
            }
            if ($c === '[' || ($c === '<' && ($testo[$i + 1] ?? '') === '<')) {
                $livello++;
                $i += $c === '<' ? 1 : 0;
            } elseif ($c === ']' || ($c === '>' && ($testo[$i + 1] ?? '') === '>')) {
                $livello--;
                $i += $c === '>' ? 1 : 0;
                if ($livello === 0) {
                    return substr($testo, 0, $i + 1);
                }
            }
        }

        return $testo;
    }

    /** Salta una stringa letterale fra parentesi, con le sue parentesi e barre. */
    private static function saltaStringa(string $testo, int $da): int
    {
        $livello = 0;
        $lunghezza = strlen($testo);
        for ($i = $da; $i < $lunghezza; $i++) {
            $c = $testo[$i];
            if ($c === '\\') {
                $i++;
                continue;
            }
            if ($c === '(') {
                $livello++;
            } elseif ($c === ')') {
                $livello--;
                if ($livello === 0) {
                    return $i;
                }
            }
        }

        return $lunghezza;
    }
}
