<?php
declare(strict_types=1);

namespace Vblite\Convert\Conversioni\Documenti\Scrittori;

use Vblite\Convert\Conversioni\Documenti\Blocco;
use Vblite\Convert\Conversioni\Documenti\Documento;
use Vblite\Convert\Conversioni\Documenti\Testo;

/**
 * Modello → RTF.
 *
 * L'RTF è molto più facile da scrivere che da leggere: si dichiarano font e
 * colori in testa e poi si accendono e spengono gli attributi. Lo capiscono
 * Word, Pages, TextEdit e LibreOffice senza discutere.
 */
final class ScrittoreRtf implements Scrittore
{
    /** Corpo del carattere, in mezzi punti come vuole l'RTF. */
    private const CORPI = [1 => 32, 2 => 28, 3 => 26, 4 => 24, 5 => 22, 6 => 22];
    private const CORPO_TESTO = 22;

    /** @var resource|null */
    private $f = null;

    private int $resi = 0;

    /** @var array<int,int> numerazione degli elenchi, per livello */
    private array $contatori = [];

    public static function estensione(): string
    {
        return 'rtf';
    }

    public static function nome(): string
    {
        return 'RTF';
    }

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
        $this->f         = $f;
        $this->resi      = 0;
        $this->contatori = [];

        fwrite($f, '{\rtf1\ansi\ansicpg1252\deff0'
            . '{\fonttbl{\f0\fswiss\fcharset0 Calibri;}{\f1\fmodern\fcharset0 Consolas;}}'
            . '{\colortbl;\red31\green56\blue100;\red68\green68\blue68;\red128\green128\blue128;}'
            . '\viewkind4\uc1\paperw11906\paperh16838\margl1134\margr1134\margt1134\margb1134' . "\n");
    }

    public function blocco(Blocco $blocco): void
    {
        if ($this->f === null) {
            return;
        }

        if ($blocco->tipo === Blocco::ELENCO && $blocco->ordinato) {
            $this->contatori[$blocco->livello] = ($this->contatori[$blocco->livello] ?? 0) + 1;
            foreach (array_keys($this->contatori) as $livello) {
                if ($livello > $blocco->livello) {
                    unset($this->contatori[$livello]);
                }
            }
        } elseif ($blocco->tipo !== Blocco::ELENCO) {
            $this->contatori = [];
        }

        fwrite($this->f, $this->rendi($blocco, $this->contatori[$blocco->livello] ?? 1) . "\n");
        $this->resi++;
    }

    public function chiudi(): int
    {
        if ($this->f !== null) {
            fwrite($this->f, "}\n");
            fclose($this->f);
            $this->f = null;
        }

        return $this->resi;
    }

    private function rendi(Blocco $blocco, int $numero): string
    {
        return match ($blocco->tipo) {
            Blocco::TITOLO => '\pard\sa120\sb240\keepn\cf1\b\fs' . (self::CORPI[$blocco->livello] ?? 22)
                              . ' ' . $this->inLinea($blocco->testi) . '\b0\cf0\fs' . self::CORPO_TESTO . '\par',

            Blocco::ELENCO => '\pard\fi-284\li' . (284 + $blocco->livello * 284) . '\sa60\fs' . self::CORPO_TESTO
                              . ' ' . ($blocco->ordinato ? $numero . '.' : '\'b7')
                              . '\tab ' . $this->inLinea($blocco->testi) . '\par',

            Blocco::CITAZIONE => '\pard\li567\sa120\sb120\cf2\i\fs' . self::CORPO_TESTO
                                 . ' ' . $this->inLinea($blocco->testi) . '\i0\cf0\par',

            Blocco::CODICE => $this->codice($blocco->nudo()),

            Blocco::TABELLA => $this->tabella($blocco),

            // Un RTF con le immagini incorporate va scritto in esadecimale e
            // pesa il doppio: qui si dichiara il rimando, come nel Markdown.
            Blocco::IMMAGINE => '\pard\qc\sa120\cf3\i\fs20 [immagine: '
                                . $this->testo($blocco->alt !== '' ? $blocco->alt : basename($blocco->extra))
                                . ']\i0\cf0\fs' . self::CORPO_TESTO . '\par',

            Blocco::RIGA => '\pard\brdrb\brdrs\brdrw10\brdrcf3\sa120 \par',

            default => '\pard\sa120\fs' . self::CORPO_TESTO . ' ' . $this->inLinea($blocco->testi) . '\par',
        };
    }

    private function codice(string $testo): string
    {
        $fuori = '';
        foreach (explode("\n", $testo) as $riga) {
            $fuori .= '\pard\f1\fs19\sa0 ' . $this->testo($riga) . '\par' . "\n";
        }

        return $fuori . '\pard\f0\fs' . self::CORPO_TESTO . ' ';
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
        $passo = (int) floor(9360 / $larghezza);

        $fuori = '';
        foreach ($blocco->righe as $indice => $riga) {
            // Ogni riga dichiara i propri confini di cella: l'RTF non ha un
            // concetto di colonna, solo di posizione del bordo destro.
            $fuori .= '\trowd\trgaph80';
            for ($c = 1; $c <= $larghezza; $c++) {
                $fuori .= '\clbrdrt\brdrs\clbrdrl\brdrs\clbrdrb\brdrs\clbrdrr\brdrs'
                        . '\cellx' . ($passo * $c);
            }
            for ($c = 0; $c < $larghezza; $c++) {
                $contenuto = $this->inLinea($riga[$c] ?? []);
                $fuori .= '\pard\intbl\fs' . self::CORPO_TESTO . ' '
                        . ($indice === 0 ? '\b ' . $contenuto . '\b0' : $contenuto)
                        . '\cell';
            }
            $fuori .= '\row' . "\n";
        }

        return $fuori . '\pard';
    }

    /** @param list<Testo> $testi */
    private function inLinea(array $testi): string
    {
        $fuori = '';
        foreach ($testi as $testo) {
            $apre = $chiude = '';
            if ($testo->grassetto) {
                $apre .= '\b ';
                $chiude = '\b0 ' . $chiude;
            }
            if ($testo->corsivo) {
                $apre .= '\i ';
                $chiude = '\i0 ' . $chiude;
            }
            if ($testo->codice) {
                $apre .= '\f1 ';
                $chiude = '\f0 ' . $chiude;
            }
            $fuori .= $apre . $this->testo($testo->testo) . $chiude;
        }

        return $fuori;
    }

    /**
     * Testo protetto per l'RTF.
     *
     * Le graffe e la barra rovesciata sono sintassi. Tutto ciò che esce dal
     * Latin-1 va scritto come \uNNNN con un carattere di ripiego dietro, per
     * i lettori che l'Unicode non lo conoscono.
     */
    private function testo(string $testo): string
    {
        $fuori = '';
        $lunghezza = mb_strlen($testo);

        for ($i = 0; $i < $lunghezza; $i++) {
            $c = mb_substr($testo, $i, 1);

            if ($c === '\\' || $c === '{' || $c === '}') {
                $fuori .= '\\' . $c;
                continue;
            }

            $codice = mb_ord($c, 'UTF-8');
            if ($codice === false) {
                continue;
            }
            if ($codice < 128) {
                $fuori .= $c;
                continue;
            }
            if ($codice < 256) {
                $fuori .= sprintf("\\'%02x", $codice);
                continue;
            }
            // Oltre i 32767 l'RTF vuole il valore con segno.
            $fuori .= '\u' . ($codice > 32767 ? $codice - 65536 : $codice) . '?';
        }

        return $fuori;
    }
}
