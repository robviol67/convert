<?php
declare(strict_types=1);

namespace Vblite\Convert\Conversioni\Documenti\Lettori;

use Smalot\PdfParser\Parser as PdfParser;
use Vblite\Convert\Conversioni\Documenti\Blocco;
use Vblite\Convert\Conversioni\Documenti\Documento;
use Vblite\Convert\Conversioni\Documenti\Testo;

/**
 * PDF → modello.
 *
 * È la conversione meno fedele delle cinque, e per un motivo strutturale: un
 * PDF non contiene un documento, contiene la sua stampa. Non ci sono titoli,
 * paragrafi o elenchi — solo pezzi di testo con delle coordinate. Tutto quello
 * che qui somiglia a una struttura è dedotto:
 *
 *   · i titoli dal corpo del carattere, più grande del testo corrente;
 *   · i paragrafi dalla distanza fra una riga e l'altra;
 *   · gli elenchi dal trattino o dal punto a inizio riga.
 *
 * Ogni deduzione viene dichiarata in «Da rivedere»: meglio un avviso che una
 * struttura inventata e presentata come certa.
 */
final class LettorePdf implements Lettore
{
    public static function estensioni(): array
    {
        return ['pdf'];
    }

    public static function nome(): string
    {
        return 'PDF';
    }

    public function leggi(string $percorso, string $cartellaMedia, ?Documento $documento = null): Documento
    {
        $documento ??= new Documento();

        $pdf    = (new PdfParser())->parseFile($percorso);
        $pagine = $pdf->getPages();

        // Primo giro: le altezze di riga, per sapere qual è il corpo normale.
        $righe = [];
        foreach ($pagine as $indice => $pagina) {
            foreach ($this->righeDi($pagina) as $riga) {
                $riga['pagina'] = $indice + 1;
                $righe[] = $riga;
            }
            $this->libera($pagina);
        }
        unset($pagine, $pdf);

        if ($righe === []) {
            $documento->perdita(
                'Nessun testo estraibile',
                'Il PDF sembra fatto di immagini: servirebbe un riconoscimento ottico, che questo strumento non fa.'
            );
            $documento->concludi();

            return $documento;
        }

        $this->componi($righe, $documento);
        $documento->concludi();

        return $documento;
    }

    /**
     * Le righe di una pagina, ognuna col suo corpo del carattere.
     *
     * @return list<array{y:float,x:float,testo:string,corpo:float}>
     */
    private function righeDi(object $pagina): array
    {
        $pezzi = [];
        foreach ($pagina->getDataTm() as $elemento) {
            $testo = $elemento[1];
            if (trim($testo) === '') {
                continue;
            }
            $matrice = $elemento[0];
            // La scala della matrice dà l'altezza del carattere.
            $corpo = round(sqrt(abs((float) $matrice[0] * (float) $matrice[3] - (float) $matrice[1] * (float) $matrice[2])), 1);
            $pezzi[] = [
                'x'     => (float) $matrice[4],
                'y'     => (float) $matrice[5],
                'testo' => $testo,
                'corpo' => $corpo > 0 ? $corpo : 1.0,
            ];
        }

        if ($pezzi === []) {
            return [];
        }

        // Si raggruppa per riga: stessa quota verticale, a meno di mezzo punto.
        usort($pezzi, static fn(array $a, array $b): int => ($b['y'] <=> $a['y']) ?: ($a['x'] <=> $b['x']));

        $righe   = [];
        $inCorso = null;
        foreach ($pezzi as $pezzo) {
            if ($inCorso !== null && abs($inCorso['y'] - $pezzo['y']) < 2.0) {
                // Se fra due pezzi c'è spazio, era uno spazio.
                $inCorso['testo'] .= (str_ends_with($inCorso['testo'], ' ') ? '' : ' ') . $pezzo['testo'];
                $inCorso['corpo']  = max($inCorso['corpo'], $pezzo['corpo']);
                continue;
            }
            if ($inCorso !== null) {
                $righe[] = $inCorso;
            }
            $inCorso = $pezzo;
        }
        if ($inCorso !== null) {
            $righe[] = $inCorso;
        }

        return $righe;
    }

    /**
     * Dalle righe ai blocchi.
     *
     * @param list<array{y:float,x:float,testo:string,corpo:float,pagina:int}> $righe
     */
    private function componi(array $righe, Documento $documento): void
    {
        $normale = $this->corpoAbituale($righe);
        $dedotti = 0;

        $paragrafo = [];
        $chiudi = static function () use (&$paragrafo, $documento): void {
            if ($paragrafo !== []) {
                $documento->aggiungi(Blocco::paragrafo([new Testo(implode(' ', $paragrafo))]));
                $paragrafo = [];
            }
        };

        $precedente = null;
        foreach ($righe as $riga) {
            $testo = trim(preg_replace('~\s+~u', ' ', $riga['testo']) ?? $riga['testo']);
            if ($testo === '') {
                continue;
            }

            // Cambio pagina o salto verticale ampio: nuovo paragrafo.
            if ($precedente !== null
                && ($precedente['pagina'] !== $riga['pagina']
                    || abs($precedente['y'] - $riga['y']) > $normale * 1.8)) {
                $chiudi();
            }
            $precedente = $riga;

            if (preg_match('~^([-•·*\x{2022}]|\d+[.)])\s+(.*)$~u', $testo, $m) === 1) {
                $chiudi();
                $documento->aggiungi(Blocco::elenco([new Testo($m[2])], preg_match('~^\d~', $m[1]) === 1));
                continue;
            }

            if ($riga['corpo'] >= $normale + 1.5 && mb_strlen($testo) <= 120) {
                $chiudi();
                $livello = $riga['corpo'] >= $normale + 5 ? 1 : ($riga['corpo'] >= $normale + 3 ? 2 : 3);
                $documento->aggiungi(Blocco::titolo($livello, [new Testo($testo)]));
                $dedotti++;
                continue;
            }

            $paragrafo[] = $testo;
        }
        $chiudi();

        $documento->perdita(
            'Struttura dedotta dall\'aspetto',
            'Un PDF non contiene titoli o paragrafi, solo testo posizionato: '
            . ($dedotti > 0 ? "{$dedotti} titoli sono stati riconosciuti perché più grandi del testo. " : '')
            . 'Rileggi il risultato prima di fidartene.'
        );
    }

    /** @param list<array{corpo:float}> $righe */
    private function corpoAbituale(array $righe): float
    {
        $conta = [];
        foreach ($righe as $riga) {
            $chiave = (string) $riga['corpo'];
            $conta[$chiave] = ($conta[$chiave] ?? 0) + mb_strlen($riga['testo'] ?? '');
        }
        arsort($conta);

        return (float) array_key_first($conta);
    }

    /**
     * Libera i dati che la pagina si è tenuta: senza, un PDF lungo si porta
     * dietro l'intero documento estratto. Stessa ragione del lettore Octorate.
     */
    private function libera(object $pagina): void
    {
        static $proprieta = false;
        if ($proprieta === false) {
            $proprieta = null;
            try {
                $proprieta = new \ReflectionProperty($pagina, 'dataTm');
            } catch (\ReflectionException) {
                // La libreria è cambiata: si tira dritto, costa solo memoria.
            }
        }
        $proprieta?->setValue($pagina, null);
    }
}
