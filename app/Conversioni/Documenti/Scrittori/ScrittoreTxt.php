<?php
declare(strict_types=1);

namespace Vblite\Convert\Conversioni\Documenti\Scrittori;

use Vblite\Convert\Conversioni\Documenti\Blocco;
use Vblite\Convert\Conversioni\Documenti\Documento;

/**
 * Modello → testo semplice.
 *
 * Il testo semplice non ha tratti: grassetto e corsivo spariscono, e i titoli
 * si distinguono solo per la sottolineatura. È una perdita dichiarata, non un
 * difetto.
 */
final class ScrittoreTxt implements Scrittore
{
    private const LARGHEZZA = 78;

    public static function estensione(): string
    {
        return 'txt';
    }

    public static function nome(): string
    {
        return 'Testo semplice';
    }

    /** @var resource|null */
    private $f = null;

    private int $resi = 0;
    private bool $primo = true;

    /** Questo formato non ha impostazioni: le regole non lo riguardano. */
    public function configura(array $regole): void
    {
    }

    public function apri(string $percorso, Documento $documento): void
    {
        $f = fopen($percorso, 'w');
        if ($f === false) {
            throw new \RuntimeException("Non riesco a scrivere {$percorso}");
        }
        $this->f     = $f;
        $this->resi  = 0;
        $this->primo = true;
    }

    public function blocco(Blocco $blocco): void
    {
        if ($this->f === null) {
            return;
        }
        if (!$this->primo) {
            fwrite($this->f, "\n");
        }
        fwrite($this->f, $this->rendi($blocco) . "\n");
        $this->primo = false;
        $this->resi++;
    }

    public function chiudi(): int
    {
        if ($this->f !== null) {
            fclose($this->f);
            $this->f = null;
        }

        return $this->resi;
    }

    private function rendi(Blocco $blocco): string
    {
        return match ($blocco->tipo) {
            Blocco::TITOLO => $this->titolo($blocco),
            Blocco::ELENCO => $this->avvolgi(
                str_repeat('  ', $blocco->livello) . ($blocco->ordinato ? '1. ' : '· ') . $blocco->nudo(),
                str_repeat('  ', $blocco->livello) . '   '
            ),
            Blocco::CITAZIONE => $this->avvolgi('    ' . $blocco->nudo(), '    '),
            Blocco::CODICE    => '    ' . str_replace("\n", "\n    ", $blocco->nudo()),
            Blocco::TABELLA   => $this->tabella($blocco),
            Blocco::IMMAGINE  => '[immagine: ' . ($blocco->alt !== '' ? $blocco->alt : basename($blocco->extra)) . ']',
            Blocco::RIGA      => str_repeat('─', self::LARGHEZZA),
            default           => $this->avvolgi($blocco->nudo()),
        };
    }

    private function titolo(Blocco $blocco): string
    {
        $testo = $blocco->nudo();
        if ($blocco->livello === 1) {
            return mb_strtoupper($testo) . "\n" . str_repeat('═', min(self::LARGHEZZA, mb_strlen($testo)));
        }
        if ($blocco->livello === 2) {
            return $testo . "\n" . str_repeat('─', min(self::LARGHEZZA, mb_strlen($testo)));
        }

        return str_repeat(' ', ($blocco->livello - 3) * 2) . $testo;
    }

    /** Tabella a colonne allineate: senza, in un .txt diventa illeggibile. */
    private function tabella(Blocco $blocco): string
    {
        $matrice = [];
        $larghezze = [];
        foreach ($blocco->righe as $r => $riga) {
            foreach ($riga as $c => $cella) {
                $testo = \Vblite\Convert\Conversioni\Documenti\Testo::nudo($cella);
                $matrice[$r][$c] = $testo;
                $larghezze[$c] = max($larghezze[$c] ?? 0, mb_strlen($testo));
            }
        }

        $righe = [];
        foreach ($matrice as $r => $riga) {
            $celle = [];
            foreach ($larghezze as $c => $larghezza) {
                $celle[] = $this->riempi($riga[$c] ?? '', $larghezza);
            }
            $righe[] = rtrim(implode('  ', $celle));
            if ($r === 0) {
                $righe[] = implode('  ', array_map(static fn(int $l): string => str_repeat('─', $l), $larghezze));
            }
        }

        return implode("\n", $righe);
    }

    /** Allunga a lunghezza fissa contando i caratteri, non i byte. */
    private function riempi(string $testo, int $lunghezza): string
    {
        return $testo . str_repeat(' ', max(0, $lunghezza - mb_strlen($testo)));
    }

    /** Manda a capo alla larghezza di lettura, tenendo il rientro. */
    private function avvolgi(string $testo, string $rientro = ''): string
    {
        $avvolto = wordwrap($testo, self::LARGHEZZA, "\n", false);

        return $rientro === '' ? $avvolto : str_replace("\n", "\n" . $rientro, $avvolto);
    }
}
