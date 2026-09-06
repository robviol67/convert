<?php
declare(strict_types=1);

namespace Vblite\Convert\Conversioni\Trascrizioni;

use Vblite\Convert\Conversioni\Documenti\Blocco;
use Vblite\Convert\Conversioni\Documenti\Documento;
use Vblite\Convert\Conversioni\Documenti\Lettori\Lettore;
use Vblite\Convert\Conversioni\Documenti\Testo;

/**
 * Sottotitoli e trascrizioni → modello di documento.
 *
 * Un file di sottotitoli non è un documento: è un elenco di battute con dei
 * tempi, spezzate ogni quaranta caratteri perché devono stare in fondo a uno
 * schermo. Leggerlo così com'è dà un testo a scalini, illeggibile. Il lavoro
 * vero è tutto qui: ricucire le battute in periodi e i periodi in paragrafi.
 *
 * Si leggono quattro forme, perché sono quelle che la gente ha davvero:
 *
 * - **SRT**, il formato più diffuso, col numero di battuta e i tempi separati
 *   da `-->`;
 * - **VTT**, quello che YouTube consegna, coi tempi uguali ma con dentro anche
 *   marcatori parola per parola e le etichette dei parlanti;
 * - **SBV**, quello che YouTube Studio esporta, coi due tempi separati da una
 *   virgola;
 * - **il testo incollato** dal pannello «Mostra trascrizione» di YouTube, dove
 *   il tempo sta da solo su una riga sua.
 *
 * I sottotitoli generati automaticamente meritano due parole a parte, perché
 * sono il caso peggiore e il più comune: non hanno **nessuna punteggiatura**,
 * e ripetono ogni riga due volte — prima da sola, poi in coda alla successiva,
 * per fare l'effetto di scorrimento. Senza toglierla, la trascrizione esce
 * lunga il doppio; senza un limite di lunghezza, senza punti fermi diventa un
 * paragrafo unico da diecimila parole.
 */
final class LettoreTrascrizione implements Lettore
{
    /** Un periodo chiude il paragrafo solo da qui in su: sotto sarebbe uno spezzatino. */
    private const PAROLE_MINIME = 55;

    /**
     * Oltre questa lunghezza il paragrafo si chiude comunque, punto fermo o no.
     *
     * Serve ai sottotitoli automatici, che di punti fermi non ne hanno uno:
     * senza questo limite un'ora di parlato uscirebbe come un paragrafo solo.
     */
    private const PAROLE_MASSIME = 120;

    /** Tempi «00:01:02,500 --> 00:01:05,000» e «0:01:02.500,0:01:05.000». */
    private const INTERVALLO = '~^\s*((?:\d{1,3}:)?\d{1,2}:\d{2}(?:[.,]\d{1,3})?)\s*(?:-->|,)\s*((?:\d{1,3}:)?\d{1,2}:\d{2}(?:[.,]\d{1,3})?)~';

    /** Il tempo da solo su una riga, o seguito dal testo: la trascrizione incollata. */
    private const SOLO_TEMPO = '~^\s*((?:\d{1,3}:)?\d{1,2}:\d{2})(?:\s+(.*))?$~';

    private string $raggruppa = 'periodi';
    private string $tempi     = 'no';
    private bool   $pulisci   = true;

    public static function estensioni(): array
    {
        return ['srt', 'vtt', 'sbv', 'txt'];
    }

    public static function nome(): string
    {
        return 'Sottotitoli e trascrizioni';
    }

    /** @param array<string,mixed> $regole */
    public function configura(array $regole): void
    {
        $this->raggruppa = in_array($regole['tr_raggruppa'] ?? '', ['periodi', 'battuta', 'parlante'], true)
            ? (string) $regole['tr_raggruppa']
            : 'periodi';
        $this->tempi = in_array($regole['tr_tempi'] ?? '', ['no', 'paragrafo', 'battuta'], true)
            ? (string) $regole['tr_tempi']
            : 'no';
        $this->pulisci = !isset($regole['tr_pulisci']) || (bool) $regole['tr_pulisci'];
    }

    public function leggi(string $percorso, string $cartellaMedia, ?Documento $documento = null): Documento
    {
        $documento ??= new Documento();

        $paragrafo = [];          // parole accumulate
        $inizio    = null;        // secondi della prima battuta del paragrafo
        $parlante  = '';          // di chi è il paragrafo aperto
        $battute   = 0;
        $conTempi  = 0;

        $chiudi = function () use (&$paragrafo, &$inizio, &$parlante, $documento): void {
            if ($paragrafo === []) {
                return;
            }
            $tratti = [];
            if ($this->tempi === 'paragrafo' && $inizio !== null) {
                $tratti[] = new Testo('[' . self::orologio($inizio) . '] ', false, false, true);
            }
            if ($parlante !== '') {
                $tratti[] = new Testo($parlante . ': ', true);
            }
            $tratti[] = new Testo(implode(' ', $paragrafo));

            $documento->aggiungi(Blocco::paragrafo($tratti));
            $paragrafo = [];
            $inizio    = null;
        };

        $precedente = '';
        $this->perOgniBattuta(
            $percorso,
            function (?float $secondi, string $testo, string $chi) use (
                &$paragrafo, &$inizio, &$parlante, &$precedente, &$battute, &$conTempi, $chiudi, $documento
            ): void {
                $battute++;
                if ($secondi !== null) {
                    $conTempi++;
                }

                $testo = $this->ripulisci($testo);
                $testo = $this->senzaRipetizione($precedente, $testo);
                if (trim($testo) === '') {
                    return;
                }
                $precedente = $testo;

                // Cambia chi parla: il paragrafo di prima è finito, sempre.
                if ($chi !== '' && $chi !== $parlante) {
                    $chiudi();
                    $parlante = $chi;
                }

                if ($this->raggruppa === 'battuta') {
                    $chiudi();
                }

                $inizio ??= $secondi;
                foreach (preg_split('~\s+~u', trim($testo)) ?: [] as $parola) {
                    if ($parola !== '') {
                        $paragrafo[] = $parola;
                    }
                }

                if ($this->tempi === 'battuta' || $this->raggruppa === 'battuta') {
                    $chiudi();
                    return;
                }

                if ($this->raggruppa === 'parlante') {
                    return;   // chiude solo quando cambia chi parla
                }

                $chiuso = preg_match('~[.!?…:]["»”\')\]]?$~u', trim($testo)) === 1;
                if (count($paragrafo) >= self::PAROLE_MASSIME
                    || ($chiuso && count($paragrafo) >= self::PAROLE_MINIME)) {
                    $chiudi();
                }
            }
        );
        $chiudi();

        if ($battute === 0) {
            $documento->perdita('Nessuna battuta riconosciuta', 'Il file non sembra una trascrizione.');
        } elseif ($conTempi === 0) {
            $documento->perdita(
                'Nessun marcatore di tempo',
                'Il testo è stato solo riformattato in paragrafi: non c\'era nessun tempo da tenere.'
            );
        }

        $documento->concludi();

        return $documento;
    }

    /**
     * Scorre il file una battuta alla volta.
     *
     * Riga per riga, senza tenere in memoria niente più della battuta corrente:
     * una trascrizione di tre ore è un file piccolo, ma il modo di leggerlo è
     * lo stesso di tutto il resto dell'applicazione.
     *
     * @param callable(?float,string,string):void $consuma secondi, testo, parlante
     */
    private function perOgniBattuta(string $percorso, callable $consuma): void
    {
        $f = fopen($percorso, 'r');
        if ($f === false) {
            throw new \RuntimeException("Non riesco a leggere {$percorso}");
        }

        $secondi  = null;
        $righe    = [];
        $saltoFino = false;   // dentro un blocco NOTE / STYLE / REGION del VTT

        $emetti = function () use (&$secondi, &$righe, $consuma): void {
            if ($righe === []) {
                $secondi = null;
                return;
            }
            $testo = implode(' ', $righe);
            $righe = [];
            [$testo, $chi] = $this->staccaParlante($testo);
            $consuma($secondi, $testo, $chi);
            $secondi = null;
        };

        while (($riga = fgets($f)) !== false) {
            $riga = rtrim($riga, "\r\n");
            if (!mb_check_encoding($riga, 'UTF-8')) {
                $riga = mb_convert_encoding($riga, 'UTF-8', 'Windows-1252');
            }
            $nuda = trim($riga);

            if ($saltoFino) {
                $saltoFino = $nuda !== '';
                continue;
            }
            if ($nuda === '') {
                $emetti();
                continue;
            }
            if (preg_match('~^(WEBVTT|NOTE|STYLE|REGION)\b~', $nuda) === 1) {
                $saltoFino = true;
                continue;
            }
            if (preg_match(self::INTERVALLO, $nuda, $m) === 1) {
                $emetti();
                $secondi = self::secondi($m[1]);
                continue;
            }
            // Il numero di battuta dell'SRT: sta da solo, prima dei tempi.
            if ($righe === [] && $secondi === null && preg_match('~^\d{1,6}$~', $nuda) === 1) {
                continue;
            }
            if (preg_match(self::SOLO_TEMPO, $nuda, $m) === 1) {
                $emetti();
                $secondi = self::secondi($m[1]);
                if (($m[2] ?? '') !== '') {
                    $righe[] = $m[2];
                }
                continue;
            }

            $righe[] = $nuda;
        }
        $emetti();
        fclose($f);
    }

    /**
     * Toglie i marcatori del formato e, se richiesto, i rumori di scena.
     *
     * I marcatori parola per parola del VTT automatico — «&lt;00:00:03.120&gt;» —
     * non sono testo: sono l'evidenziazione progressiva del karaoke.
     */
    private function ripulisci(string $testo): string
    {
        $testo = (string) preg_replace('~<\d{1,3}:\d{2}:\d{2}[.,]\d{1,3}>~', '', $testo);
        $testo = (string) preg_replace('~</?[a-z][^>]*>~i', '', $testo);
        $testo = html_entity_decode($testo, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if ($this->pulisci) {
            // [Musica], [Applausi], (inaudibile): indicazioni per chi non sente,
            // non parole dette. In un documento da leggere sono rumore.
            $testo = (string) preg_replace('~[\[\(][^\]\)]{1,40}[\]\)]~u', ' ', $testo);
            $testo = str_replace(['♪', '♫'], ' ', $testo);
        }

        return trim((string) preg_replace('~\s+~u', ' ', $testo));
    }

    /**
     * Toglie la ripetizione dei sottotitoli a scorrimento.
     *
     * Quelli automatici ripetono la riga di prima in testa a quella dopo, per
     * dare l'effetto di scorrimento in fondo allo schermo. Su carta è solo il
     * doppio delle parole.
     */
    private function senzaRipetizione(string $precedente, string $corrente): string
    {
        if ($precedente === '' || $corrente === '') {
            return $corrente;
        }
        if ($corrente === $precedente) {
            return '';
        }
        if (str_starts_with($corrente, $precedente . ' ')) {
            return substr($corrente, strlen($precedente) + 1);
        }

        return $corrente;
    }

    /**
     * Stacca l'etichetta di chi parla dal testo della battuta.
     *
     * Tre notazioni, tutte in giro: quella del VTT «<v Nome>», quella delle
     * trascrizioni televisive «>> Nome:» e quella scritta a mano «NOME:».
     * L'ultima si accetta solo se è corta e in maiuscolo, altrimenti una frase
     * qualunque con due punti diventerebbe un parlante.
     *
     * @return array{string,string} testo, parlante
     */
    private function staccaParlante(string $testo): array
    {
        if (preg_match('~<v(?:\.[^\s>]+)*\s+([^>]+)>~u', $testo, $m) === 1) {
            return [trim((string) preg_replace('~<v[^>]*>~u', '', $testo)), trim($m[1])];
        }

        $testo = trim($testo);
        if (preg_match('~^>>+\s*~u', $testo) === 1) {
            $testo = (string) preg_replace('~^>>+\s*~u', '', $testo);
        }
        if (preg_match('~^-\s+~u', $testo) === 1) {
            $testo = (string) preg_replace('~^-\s+~u', '', $testo);
        }

        if (preg_match('~^([\p{Lu}][\p{Lu}\p{Nd}\s.\'’-]{0,28}):\s+(.*)$~u', $testo, $m) === 1) {
            $nome = trim($m[1]);
            // Al massimo quattro parole: oltre non è un nome, è una frase.
            if (mb_strlen($nome) >= 2 && count(preg_split('~\s+~u', $nome) ?: []) <= 4) {
                return [trim($m[2]), $nome];
            }
        }

        return [$testo, ''];
    }

    /** «01:02:03,500» → 3723.5 secondi. */
    private static function secondi(string $tempo): float
    {
        $pezzi = array_reverse(explode(':', str_replace(',', '.', trim($tempo))));
        $totale = 0.0;
        foreach ($pezzi as $i => $pezzo) {
            $totale += (float) $pezzo * (60 ** $i);
        }

        return $totale;
    }

    /** 3723.5 → «1:02:03». Sotto l'ora restano i minuti, come su YouTube. */
    private static function orologio(float $secondi): string
    {
        $s = (int) floor($secondi);
        $h = intdiv($s, 3600);
        $m = intdiv($s % 3600, 60);

        return $h > 0
            ? sprintf('%d:%02d:%02d', $h, $m, $s % 60)
            : sprintf('%d:%02d', $m, $s % 60);
    }
}
