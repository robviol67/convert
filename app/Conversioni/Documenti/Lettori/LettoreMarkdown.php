<?php
declare(strict_types=1);

namespace Vblite\Convert\Conversioni\Documenti\Lettori;

use Vblite\Convert\Conversioni\Documenti\Blocco;
use Vblite\Convert\Conversioni\Documenti\Documento;
use Vblite\Convert\Conversioni\Documenti\Testo;

/**
 * Markdown → modello.
 *
 * Riconosce quello che il modello sa rappresentare: titoli, paragrafi, elenchi
 * puntati e numerati con rientri, citazioni, blocchi di codice, tabelle,
 * immagini, righe orizzontali. Il resto — HTML incorporato, note a piè di
 * pagina, definizioni — passa come testo, e viene segnalato.
 */
final class LettoreMarkdown implements Lettore
{
    public static function estensioni(): array
    {
        return ['md', 'markdown', 'mdown'];
    }

    public static function nome(): string
    {
        return 'Markdown';
    }

    public function leggi(string $percorso, string $cartellaMedia, ?Documento $documento = null): Documento
    {
        $documento ??= new Documento();

        $f = fopen($percorso, 'r');
        if ($f === false) {
            throw new \RuntimeException("Non riesco a leggere {$percorso}");
        }

        // Si legge riga per riga: un documento grande non deve stare in memoria.
        $righe = [];
        while (($riga = fgets($f)) !== false) {
            $righe[] = rtrim($riga, "\r\n");
        }
        fclose($f);

        $this->analizza($righe, $documento);

        $documento->concludi();

        return $documento;
    }

    /** @param list<string> $righe */
    private function analizza(array $righe, Documento $documento): void
    {
        $paragrafo = [];
        $htmlVisto = false;

        $chiudiParagrafo = static function () use (&$paragrafo, $documento): void {
            if ($paragrafo !== []) {
                $documento->aggiungi(Blocco::paragrafo($paragrafo));
                $paragrafo = [];
            }
        };

        for ($i = 0; $i < count($righe); $i++) {
            $riga = $righe[$i];

            // ── blocco di codice recintato ────────────────────────────────
            if (preg_match('~^\s*```+\s*(\S*)~', $riga, $m) === 1) {
                $chiudiParagrafo();
                $linguaggio = $m[1];
                $dentro = [];
                $i++;
                while ($i < count($righe) && preg_match('~^\s*```+\s*$~', $righe[$i]) !== 1) {
                    $dentro[] = $righe[$i];
                    $i++;
                }
                $documento->aggiungi(Blocco::codice(implode("\n", $dentro), $linguaggio));
                continue;
            }

            // ── riga vuota: chiude il paragrafo ───────────────────────────
            if (trim($riga) === '') {
                $chiudiParagrafo();
                continue;
            }

            // ── riga orizzontale ──────────────────────────────────────────
            if (preg_match('~^\s{0,3}([-*_])\s*(?:\1\s*){2,}$~', $riga) === 1) {
                $chiudiParagrafo();
                $documento->aggiungi(Blocco::riga());
                continue;
            }

            // ── titolo con i cancelletti ──────────────────────────────────
            if (preg_match('~^\s{0,3}(#{1,6})\s+(.*?)\s*#*\s*$~', $riga, $m) === 1) {
                $chiudiParagrafo();
                $documento->aggiungi(Blocco::titolo(strlen($m[1]), $this->inLinea($m[2])));
                continue;
            }

            // ── titolo sottolineato ───────────────────────────────────────
            if ($paragrafo === [] && isset($righe[$i + 1])
                && preg_match('~^\s{0,3}(=+|-+)\s*$~', $righe[$i + 1], $m) === 1
                && trim($riga) !== '') {
                $documento->aggiungi(Blocco::titolo($m[1][0] === '=' ? 1 : 2, $this->inLinea(trim($riga))));
                $i++;
                continue;
            }

            // ── tabella ───────────────────────────────────────────────────
            if (str_contains($riga, '|') && isset($righe[$i + 1])
                && preg_match('~^\s*\|?[\s:|-]+\|[\s:|-]*$~', $righe[$i + 1]) === 1) {
                $chiudiParagrafo();
                $tabella = [$this->celle($riga)];
                $i += 2;
                while ($i < count($righe) && str_contains($righe[$i], '|') && trim($righe[$i]) !== '') {
                    $tabella[] = $this->celle($righe[$i]);
                    $i++;
                }
                $i--;
                $documento->aggiungi(Blocco::tabella($tabella));
                continue;
            }

            // ── citazione ─────────────────────────────────────────────────
            if (preg_match('~^\s{0,3}>\s?(.*)$~', $riga, $m) === 1) {
                $chiudiParagrafo();
                $dentro = [$m[1]];
                while (isset($righe[$i + 1]) && preg_match('~^\s{0,3}>\s?(.*)$~', $righe[$i + 1], $m2) === 1) {
                    $dentro[] = $m2[1];
                    $i++;
                }
                $documento->aggiungi(Blocco::citazione($this->inLinea(implode(' ', $dentro))));
                continue;
            }

            // ── immagine da sola ──────────────────────────────────────────
            if (preg_match('~^\s*!\[([^\]]*)\]\(([^)\s]+)[^)]*\)\s*$~', $riga, $m) === 1) {
                $chiudiParagrafo();
                $documento->aggiungi(Blocco::immagine($m[2], $m[1]));
                continue;
            }

            // ── voce di elenco ────────────────────────────────────────────
            if (preg_match('~^(\s*)([-*+]|\d+[.)])\s+(.*)$~', $riga, $m) === 1) {
                $chiudiParagrafo();
                $documento->aggiungi(Blocco::elenco(
                    $this->inLinea($m[3]),
                    preg_match('~^\d~', $m[2]) === 1,
                    (int) floor(strlen(str_replace("\t", '    ', $m[1])) / 2)
                ));
                continue;
            }

            // ── HTML incorporato: si tiene com'è, ma si segnala ───────────
            if (!$htmlVisto && preg_match('~^\s*<[a-zA-Z/!]~', $riga) === 1) {
                $documento->perdita(
                    'HTML incorporato nel Markdown',
                    'Tenuto come testo: i formati di destinazione non lo interpretano.'
                );
                $htmlVisto = true;
            }

            // ── testo normale, che continua il paragrafo ──────────────────
            if ($paragrafo !== []) {
                $paragrafo[] = new Testo(' ');
            }
            foreach ($this->inLinea($riga) as $tratto) {
                $paragrafo[] = $tratto;
            }
        }

        $chiudiParagrafo();
    }

    /** @return list<list<Testo>> */
    private function celle(string $riga): array
    {
        $grezze = preg_split('~(?<!\\\\)\|~', trim($riga, " \t|")) ?: [];

        return array_map(fn(string $c): array => $this->inLinea(trim($c)), $grezze);
    }

    /**
     * Grassetto, corsivo, codice e collegamenti.
     *
     * Si procede a scansione invece che con una regex sola: le combinazioni
     * annidate (**grassetto con _corsivo_ dentro**) non si esprimono bene con
     * un'espressione regolare, e sbagliarle silenziosamente sarebbe peggio.
     *
     * @return list<Testo>
     */
    private function inLinea(string $riga): array
    {
        $tratti    = [];
        $corrente  = '';
        $grassetto = false;
        $corsivo   = false;
        $lunghezza = strlen($riga);

        $chiudi = static function () use (&$corrente, &$tratti, &$grassetto, &$corsivo): void {
            if ($corrente !== '') {
                $tratti[] = new Testo($corrente, $grassetto, $corsivo);
                $corrente = '';
            }
        };

        for ($i = 0; $i < $lunghezza; $i++) {
            $c = $riga[$i];

            // Carattere protetto da barra rovesciata
            if ($c === '\\' && $i + 1 < $lunghezza && str_contains('\\`*_{}[]()#+-.!|>', $riga[$i + 1])) {
                $corrente .= $riga[$i + 1];
                $i++;
                continue;
            }

            // Codice in linea
            if ($c === '`') {
                $fine = strpos($riga, '`', $i + 1);
                if ($fine !== false) {
                    $chiudi();
                    $tratti[] = new Testo(substr($riga, $i + 1, $fine - $i - 1), $grassetto, $corsivo, true);
                    $i = $fine;
                    continue;
                }
            }

            // Immagine o collegamento
            if (($c === '[' || ($c === '!' && ($riga[$i + 1] ?? '') === '[')) && preg_match(
                '~^(!?)\[([^\]]*)\]\(([^)\s]*)[^)]*\)~',
                substr($riga, $i),
                $m
            ) === 1) {
                $chiudi();
                $tratti[] = $m[1] === '!'
                    ? new Testo($m[2] !== '' ? $m[2] : basename($m[3]), $grassetto, $corsivo)
                    : new Testo($m[2], $grassetto, $corsivo, false, $m[3]);
                $i += strlen($m[0]) - 1;
                continue;
            }

            // Grassetto e corsivo
            if ($c === '*' || $c === '_') {
                $doppio = ($riga[$i + 1] ?? '') === $c;
                $salto  = $doppio ? 2 : 1;

                // Un delimitatore conta solo se «tocca» il testo: apre se ha
                // del contenuto subito dopo, chiude se ne ha subito prima.
                // Senza questa regola «2 * 3 = 6» diventa corsivo aperto e mai
                // chiuso, e si porta dietro tutto il resto della riga.
                $prima = $i > 0 ? $riga[$i - 1] : ' ';
                $dopo  = $riga[$i + $salto] ?? ' ';
                $puoAprire  = trim($dopo) !== '';
                $puoChiudere = trim($prima) !== '';

                // Un underscore in mezzo a una parola non è corsivo: è un nome_file.
                $dentroParola = $c === '_'
                    && preg_match('~\w~', $prima) === 1
                    && preg_match('~\w~', $dopo) === 1;

                $aperto = $doppio ? $grassetto : $corsivo;
                $valido = !$dentroParola && ($aperto ? $puoChiudere : $puoAprire);

                if ($valido) {
                    $chiudi();
                    if ($doppio) {
                        $grassetto = !$grassetto;
                        $i++;
                    } else {
                        $corsivo = !$corsivo;
                    }
                    continue;
                }
            }

            $corrente .= $c;
        }

        $chiudi();

        return Testo::unisci($tratti);
    }
}
