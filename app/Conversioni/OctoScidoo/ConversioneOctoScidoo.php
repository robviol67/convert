<?php
declare(strict_types=1);

namespace Vblite\Convert\Conversioni\OctoScidoo;

use Vblite\Convert\Conversioni\Conversione;

/** Octorate «Stampa clienti presenti» → Scidoo «File Import Prenotazioni». */
final class ConversioneOctoScidoo implements Conversione
{
    public static function chiave(): string
    {
        return 'octo_scidoo';
    }

    public function manifest(): array
    {
        return require __DIR__ . '/manifest.php';
    }

    public function verifica(string $percorsoIngresso): array
    {
        return (new Parser())->riconosci($percorsoIngresso);
    }

    public function converti(string $percorsoIngresso, string $percorsoUscita, array $regole, ?callable $progresso = null): array
    {
        $avvisa = static function (string $passo, int $corrente, int $totale) use ($progresso): void {
            if ($progresso !== null) {
                $progresso($passo, $corrente, $totale);
            }
        };

        $avvisa('lettura', 0, 1);
        $parser = new Parser(static function (int $pagina, int $totale) use ($avvisa): void {
            $avvisa('lettura', $pagina, $totale);
        });
        $estratto = $parser->estrai($percorsoIngresso);

        $avvisa('raggruppamento', 0, 1);
        $risultato = (new Raggruppatore($regole))->raggruppa($estratto['righe']);

        $avvisa('scrittura', 0, 1);
        $formato  = ($regole['formato'] ?? 'xlsx') === 'csv' ? 'csv' : 'xlsx';
        $scritte  = (new ScrittoreScidoo())->scrivi($risultato['prenotazioni'], $percorsoUscita, $formato);
        $avvisa('scrittura', 1, 1);

        return [
            'righe_lette'   => $risultato['righe_lette'],
            'righe_scritte' => $scritte,
            'pagine'        => $estratto['pagine'],
            'intestazione'  => $estratto['intestazione'],
            'anomalie'      => $risultato['anomalie'],
            'anteprima'     => array_slice($risultato['prenotazioni'], 0, 8),
        ];
    }

    /**
     * Lettura sola del PDF, per lo step 2: serve a mostrare i conteggi veri
     * (pagine, righe, prenotazioni, periodo) prima di lanciare la conversione.
     *
     * @param array<string,mixed> $regole
     * @return array<string,mixed>
     */
    public function analizza(string $percorsoIngresso, array $regole = []): array
    {
        $estratto  = (new Parser())->estrai($percorsoIngresso);
        $risultato = (new Raggruppatore($regole))->raggruppa($estratto['righe']);

        // «Da rivedere» conta solo cio' che chiede una decisione umana: le voci
        // informative dicono cosa e' stato fatto e non vanno annunciate come lavoro.
        $daCorreggere = 0;
        foreach ($risultato['anomalie'] as $anomalia) {
            $daCorreggere += ($anomalia['gravita'] ?? 'correggi') === 'correggi' ? 1 : 0;
        }

        return [
            'pagine'        => $estratto['pagine'],
            'intestazione'  => $estratto['intestazione'],
            'righe_lette'   => $risultato['righe_lette'],
            'prenotazioni'  => count($risultato['prenotazioni']),
            'anomalie'      => $daCorreggere,
            'anteprima'     => array_slice($risultato['prenotazioni'], 0, 2),
        ];
    }
}
