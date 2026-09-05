<?php
declare(strict_types=1);

namespace Vblite\Convert\Conversioni\Documenti\Lettori;

use Vblite\Convert\Conversioni\Documenti\Blocco;
use Vblite\Convert\Conversioni\Documenti\Documento;
use Vblite\Convert\Conversioni\Documenti\Testo;

/**
 * RTF → modello.
 *
 * L'RTF non è XML: è una sequenza di gruppi fra graffe con parole di controllo
 * che accendono e spengono attributi. Si legge con un automa e una pila di
 * stati — entrando in un gruppo si eredita la formattazione, uscendo la si
 * ripristina.
 *
 * Attenzione alla qualità della sorgente: un RTF scritto da Word ha struttura
 * (stili, elenchi, tabelle) e si converte bene; uno prodotto da un generatore
 * di stampe posiziona ogni pezzo in modo assoluto e non ha struttura alcuna —
 * lì si recupera il testo e poco altro, e lo si dichiara.
 */
final class LettoreRtf implements Lettore
{
    /** Destinazioni il cui contenuto non è testo del documento. */
    private const DA_SALTARE = [
        'fonttbl', 'colortbl', 'stylesheet', 'info', 'pict', 'object', 'header',
        'footer', 'headerl', 'headerr', 'headerf', 'footerl', 'footerr', 'footerf',
        'themedata', 'colorschememapping', 'latentstyles', 'datastore', 'listtable',
        'listoverridetable', 'rsidtbl', 'generator', 'xmlnstbl', 'nonesttables',
        'shppict', 'bkmkstart', 'bkmkend', 'field', 'fldinst', 'filetbl', 'mmath',
    ];

    public static function estensioni(): array
    {
        return ['rtf'];
    }

    public static function nome(): string
    {
        return 'RTF';
    }

    public function leggi(string $percorso, string $cartellaMedia, ?Documento $documento = null): Documento
    {
        $documento ??= new Documento();
        $grezzo    = file_get_contents($percorso);
        if ($grezzo === false) {
            throw new \RuntimeException("Non riesco a leggere {$percorso}");
        }
        if (!str_starts_with(ltrim($grezzo), '{\\rtf')) {
            throw new \RuntimeException("Il file non comincia con {\\rtf: non è un RTF.");
        }

        $this->analizza($grezzo, $documento);
        unset($grezzo);

        $documento->concludi();

        return $documento;
    }

    private function analizza(string $rtf, Documento $documento): void
    {
        $lunghezza = strlen($rtf);

        // Stato corrente e pila dei gruppi.
        $stato = ['b' => false, 'i' => false, 'fs' => 0, 'salta' => false, 'ucSalta' => 1];
        $pila  = [];

        $tratti     = [];      // tratti del paragrafo in corso
        $corrente   = '';      // testo del tratto in corso
        $corpi      = [];      // corpi del carattere visti, per dedurre i titoli
        $paragrafi  = [];      // paragrafi accumulati: [tratti, corpo]
        $inTabella  = false;
        $celle      = [];
        $righeTab   = [];
        $posizionato = false;

        $chiudiTratto = static function () use (&$corrente, &$tratti, &$stato): void {
            if ($corrente !== '') {
                $tratti[] = new Testo($corrente, $stato['b'], $stato['i']);
                $corrente = '';
            }
        };

        for ($i = 0; $i < $lunghezza; $i++) {
            $c = $rtf[$i];

            if ($c === '{') {
                $chiudiTratto();
                $pila[] = $stato;
                continue;
            }

            if ($c === '}') {
                $chiudiTratto();
                $stato = array_pop($pila) ?? $stato;
                continue;
            }

            if ($c === '\\') {
                $prossimo = $rtf[$i + 1] ?? '';

                // Caratteri protetti
                if ($prossimo === '\\' || $prossimo === '{' || $prossimo === '}') {
                    if (!$stato['salta']) {
                        $corrente .= $prossimo;
                    }
                    $i++;
                    continue;
                }

                // Byte in esadecimale
                if ($prossimo === "'") {
                    $esa = substr($rtf, $i + 2, 2);
                    if (!$stato['salta'] && ctype_xdigit($esa)) {
                        $corrente .= mb_convert_encoding(chr((int) hexdec($esa)), 'UTF-8', 'Windows-1252');
                    }
                    $i += 3;
                    continue;
                }

                // Barra rovesciata seguita da un a capo vero: e' un fine
                // paragrafo, non spazio bianco da buttare. TextEdit scrive
                // cosi' invece di \par, e scartandolo tutto il documento
                // diventava un unico paragrafo lungo.
                if ($prossimo === "\n" || $prossimo === "\r") {
                    $chiudiTratto();
                    if ($tratti !== []) {
                        $paragrafi[] = ['tratti' => $tratti, 'fs' => $stato['fs']];
                        if ($stato['fs'] > 0) {
                            $corpi[] = $stato['fs'];
                        }
                        $tratti = [];
                    }
                    $i++;
                    continue;
                }

                // Parola di controllo
                if (preg_match('~\\\\([a-zA-Z]+)(-?\d+)? ?~A', $rtf, $m, 0, $i) !== 1) {
                    $i++;
                    continue;
                }
                $parola = $m[1];
                $valore = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : null;
                $i += strlen($m[0]) - 1;

                // Destinazione da saltare: si spegne tutto fino alla graffa
                if (in_array($parola, self::DA_SALTARE, true)) {
                    $stato['salta'] = true;
                    continue;
                }
                if ($stato['salta']) {
                    continue;
                }

                switch ($parola) {
                    case 'par':
                    case 'line':
                        $chiudiTratto();
                        if ($tratti !== []) {
                            $paragrafi[] = ['tratti' => $tratti, 'fs' => $stato['fs']];
                            if ($stato['fs'] > 0) {
                                $corpi[] = $stato['fs'];
                            }
                        }
                        $tratti = [];
                        break;

                    case 'pard':
                        $stato['b'] = false;
                        $stato['i'] = false;
                        break;

                    case 'b':  $stato['b'] = $valore !== 0; break;
                    case 'i':  $stato['i'] = $valore !== 0; break;
                    case 'fs': $stato['fs'] = $valore ?? 0; break;

                    case 'cell':
                        $chiudiTratto();
                        $celle[] = $tratti;
                        $tratti  = [];
                        $inTabella = true;
                        break;

                    case 'row':
                        if ($celle !== []) {
                            $righeTab[] = $celle;
                            $celle = [];
                        }
                        break;

                    case 'u':
                        // Carattere Unicode, seguito da un ripiego da scartare.
                        if ($valore !== null) {
                            $corrente .= mb_chr($valore < 0 ? $valore + 65536 : $valore, 'UTF-8') ?: '';
                            $i += $stato['ucSalta'];
                        }
                        break;

                    case 'uc':
                        $stato['ucSalta'] = $valore ?? 1;
                        break;

                    case 'posx':
                    case 'posy':
                    case 'absw':
                        // Testo piazzato a coordinate assolute: è una stampa
                        // impaginata, non un documento con struttura.
                        $posizionato = true;
                        break;

                    case 'tab':
                        $corrente .= ' ';
                        break;
                }
                continue;
            }

            if ($c === "\r" || $c === "\n") {
                continue;
            }

            if (!$stato['salta']) {
                $corrente .= $c;
            }
        }

        $chiudiTratto();
        if ($tratti !== []) {
            $paragrafi[] = ['tratti' => $tratti, 'fs' => $stato['fs']];
        }

        if ($righeTab !== []) {
            $documento->aggiungi(Blocco::tabella($righeTab));
        }

        $this->componi($paragrafi, $corpi, $documento);

        if ($posizionato) {
            $documento->perdita(
                'RTF con testo posizionato in modo assoluto',
                'Sembra la stampa di un gestionale, non un documento scritto: '
                . 'il testo si recupera, la struttura in origine non c\'è.'
            );
        }
    }

    /**
     * Dai paragrafi grezzi ai blocchi.
     *
     * L'RTF non dice quali paragrafi sono titoli: lo si deduce dal corpo del
     * carattere. Un paragrafo corto e più grande del corpo abituale è un
     * titolo; tutto il resto è testo. È una deduzione, e come tale va detta.
     *
     * @param list<array{tratti:list<Testo>,fs:int}> $paragrafi
     * @param list<int>                              $corpi
     */
    private function componi(array $paragrafi, array $corpi, Documento $documento): void
    {
        $normale = $this->corpoAbituale($corpi);
        $dedotti = 0;

        foreach ($paragrafi as $paragrafo) {
            $testi = Testo::unisci($paragrafo['tratti']);
            $nudo  = trim(Testo::nudo($testi));
            if ($nudo === '') {
                continue;
            }

            // Voce di elenco scritta a mano, come capita spesso negli RTF.
            if (preg_match('~^([-•·*]|\d+[.)])\s+(.*)$~u', $nudo, $m) === 1) {
                $documento->aggiungi(Blocco::elenco(
                    [new Testo($m[2])],
                    preg_match('~^\d~', $m[1]) === 1
                ));
                continue;
            }

            $piuGrande = $normale > 0 && $paragrafo['fs'] >= $normale + 4;
            if ($piuGrande && mb_strlen($nudo) <= 120) {
                $livello = $paragrafo['fs'] >= $normale + 12 ? 1 : ($paragrafo['fs'] >= $normale + 8 ? 2 : 3);
                $documento->aggiungi(Blocco::titolo($livello, $testi));
                $dedotti++;
                continue;
            }

            $documento->aggiungi(Blocco::paragrafo($testi));
        }

        if ($dedotti > 0) {
            $documento->perdita(
                "{$dedotti} titoli dedotti dal corpo del carattere",
                'L\'RTF non marca i titoli: sono stati riconosciuti perché più grandi del testo. Controllali.'
            );
        }
    }

    /** @param list<int> $corpi */
    private function corpoAbituale(array $corpi): int
    {
        if ($corpi === []) {
            return 0;
        }
        $conta = array_count_values($corpi);
        arsort($conta);

        return (int) array_key_first($conta);
    }
}
