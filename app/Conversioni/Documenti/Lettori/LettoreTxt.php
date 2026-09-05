<?php
declare(strict_types=1);

namespace Vblite\Convert\Conversioni\Documenti\Lettori;

use Vblite\Convert\Conversioni\Documenti\Blocco;
use Vblite\Convert\Conversioni\Documenti\Documento;
use Vblite\Convert\Conversioni\Documenti\Testo;

/**
 * Testo semplice → modello.
 *
 * Un .txt non dichiara struttura, ma quasi sempre ne ha una convenzionale: le
 * righe vuote separano i paragrafi, i trattini o gli asterischi a inizio riga
 * fanno un elenco. Si riconosce quello, senza inventare titoli che non ci sono.
 */
final class LettoreTxt implements Lettore
{
    public static function estensioni(): array
    {
        return ['txt', 'text'];
    }

    public static function nome(): string
    {
        return 'Testo semplice';
    }

    public function leggi(string $percorso, string $cartellaMedia, ?Documento $documento = null): Documento
    {
        $documento ??= new Documento();

        $f = fopen($percorso, 'r');
        if ($f === false) {
            throw new \RuntimeException("Non riesco a leggere {$percorso}");
        }

        $paragrafo = [];
        $chiudi = static function () use (&$paragrafo, $documento): void {
            if ($paragrafo !== []) {
                $documento->aggiungi(Blocco::paragrafo([new Testo(implode(' ', $paragrafo))]));
                $paragrafo = [];
            }
        };

        $nonUtf8 = false;
        while (($riga = fgets($f)) !== false) {
            $riga = rtrim($riga, "\r\n");

            if (!mb_check_encoding($riga, 'UTF-8')) {
                $riga = mb_convert_encoding($riga, 'UTF-8', 'Windows-1252');
                $nonUtf8 = true;
            }

            if (trim($riga) === '') {
                $chiudi();
                continue;
            }

            if (preg_match('~^(\s*)([-*•]|\d+[.)])\s+(.*)$~u', $riga, $m) === 1) {
                $chiudi();
                $documento->aggiungi(Blocco::elenco(
                    [new Testo($m[3])],
                    preg_match('~^\d~', $m[2]) === 1,
                    (int) floor(mb_strlen(str_replace("\t", '    ', $m[1])) / 2)
                ));
                continue;
            }

            $paragrafo[] = trim($riga);
        }
        $chiudi();
        fclose($f);

        if ($nonUtf8) {
            $documento->perdita(
                'Il file non era in UTF-8',
                'Interpretato come Windows-1252. Controlla le lettere accentate.'
            );
        }

        $documento->concludi();

        return $documento;
    }
}
