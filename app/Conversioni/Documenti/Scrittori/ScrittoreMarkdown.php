<?php
declare(strict_types=1);

namespace Vblite\Convert\Conversioni\Documenti\Scrittori;

use Vblite\Convert\Conversioni\Documenti\Blocco;
use Vblite\Convert\Conversioni\Documenti\Documento;
use Vblite\Convert\Conversioni\Documenti\Testo;

/** Modello → Markdown. */
final class ScrittoreMarkdown implements Scrittore
{
    /** @var resource|null */
    private $f = null;

    private int $resi = 0;
    private bool $primo = true;
    private ?string $precedente = null;

    /** @var array<int,int> numerazione degli elenchi, per livello di rientro */
    private array $contatori = [];

    public static function estensione(): string
    {
        return 'md';
    }

    public static function nome(): string
    {
        return 'Markdown';
    }

    public function apri(string $percorso, Documento $documento): void
    {
        $f = fopen($percorso, 'w');
        if ($f === false) {
            throw new \RuntimeException("Non riesco a scrivere {$percorso}");
        }
        $this->f          = $f;
        $this->resi       = 0;
        $this->primo      = true;
        $this->precedente = null;
        $this->contatori  = [];
    }

    public function blocco(Blocco $blocco): void
    {
        if ($this->f === null) {
            return;
        }

        // Gli elenchi numerati si numerano davvero. Markdown rinumera da solo
        // anche scrivendo «1.» ovunque, ma il file resta anche un testo da
        // leggere, e «1. 1. 1.» sembra un errore.
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

        // Fra due voci di elenco basta un a capo; fra tutto il resto ne servono
        // due, altrimenti Markdown incolla i paragrafi.
        if (!$this->primo) {
            $attaccati = $this->precedente === Blocco::ELENCO && $blocco->tipo === Blocco::ELENCO;
            fwrite($this->f, $attaccati ? "\n" : "\n\n");
        }

        fwrite($this->f, $this->rendi($blocco, $this->contatori[$blocco->livello] ?? 1));
        $this->precedente = $blocco->tipo;
        $this->primo      = false;
        $this->resi++;
    }

    public function chiudi(): int
    {
        if ($this->f !== null) {
            fwrite($this->f, "\n");
            fclose($this->f);
            $this->f = null;
        }

        return $this->resi;
    }

    private function rendi(Blocco $blocco, int $numero): string
    {
        return match ($blocco->tipo) {
            Blocco::TITOLO    => str_repeat('#', $blocco->livello) . ' ' . $this->inLinea($blocco->testi),
            Blocco::ELENCO    => str_repeat('  ', $blocco->livello)
                                 . ($blocco->ordinato ? $numero . '. ' : '- ')
                                 . $this->inLinea($blocco->testi),
            Blocco::CITAZIONE => '> ' . str_replace("\n", "\n> ", $this->inLinea($blocco->testi)),
            Blocco::CODICE    => "```" . $blocco->extra . "\n" . $blocco->nudo() . "\n```",
            Blocco::TABELLA   => $this->tabella($blocco),
            Blocco::IMMAGINE  => '![' . $this->fuga($blocco->alt) . '](' . $blocco->extra . ')',
            Blocco::RIGA      => '---',
            default           => $this->inLinea($blocco->testi),
        };
    }

    private function tabella(Blocco $blocco): string
    {
        if ($blocco->righe === []) {
            return '';
        }

        $larghezza = 0;
        foreach ($blocco->righe as $riga) {
            $larghezza = max($larghezza, count($riga));
        }

        $rese = [];
        foreach ($blocco->righe as $riga) {
            $celle = [];
            for ($i = 0; $i < $larghezza; $i++) {
                // Le barre verticali dentro una cella spezzerebbero la tabella.
                $celle[] = str_replace('|', '\\|', $this->inLinea($riga[$i] ?? []));
            }
            $rese[] = '| ' . implode(' | ', $celle) . ' |';
        }

        // Markdown vuole la riga di separazione: senza, la tabella non è tabella.
        array_splice($rese, 1, 0, '|' . str_repeat(' --- |', $larghezza));

        return implode("\n", $rese);
    }

    /** @param list<Testo> $testi */
    private function inLinea(array $testi): string
    {
        $fuori = '';
        foreach ($testi as $testo) {
            $pezzo = $testo->codice ? '`' . $testo->testo . '`' : $this->fuga($testo->testo);

            if ($testo->grassetto) {
                $pezzo = '**' . $pezzo . '**';
            }
            if ($testo->corsivo) {
                $pezzo = '*' . $pezzo . '*';
            }
            if ($testo->collegamento !== null && $testo->collegamento !== '') {
                $pezzo = '[' . $pezzo . '](' . $testo->collegamento . ')';
            }
            $fuori .= $pezzo;
        }

        return trim($fuori);
    }

    /**
     * Protegge i caratteri che in Markdown vogliono dire qualcosa.
     *
     * Si protegge solo dove servirebbe davvero: proteggere ogni asterisco e
     * ogni trattino renderebbe il file illeggibile a occhio, che è metà del
     * senso del Markdown.
     */
    private function fuga(string $testo): string
    {
        $testo = str_replace('\\', '\\\\', $testo);

        // Gli underscore dentro una parola non sono corsivo per nessun lettore
        // Markdown: proteggerli riempirebbe di barre i nomi_dei_file.
        $testo = preg_replace('~(?<![\\\\\w])_(?!\w*_?\w)~', '\\\\_', $testo) ?? $testo;
        $testo = preg_replace('~(?<!\\\\)([*`\[\]])~', '\\\\$1', $testo) ?? $testo;

        // A inizio riga anche questi diventano marcatori.
        return preg_replace('~^(\s*)([#>]|\d+\.|[-+])(\s)~', '$1\\\\$2$3', $testo) ?? $testo;
    }
}
