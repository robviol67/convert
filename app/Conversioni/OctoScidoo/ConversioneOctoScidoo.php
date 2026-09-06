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
        \Vblite\Convert\Errori::passo('motore · riconoscimento, apro il PDF');
        $esito = (new Parser())->riconosci($percorsoIngresso);
        \Vblite\Convert\Errori::passo('motore · riconoscimento concluso');

        return $esito;
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
            'anteprima'     => $this->anteprima($risultato['prenotazioni']),
        ];
    }

    /**
     * Le prime righe del tracciato, gia' formattate per la schermata «Pronto».
     * Si costruiscono qui, mentre le prenotazioni sono in memoria: rileggere il
     * file appena scritto costerebbe una seconda copia in memoria, che su questo
     * hosting non c'e'.
     *
     * @param list<array<string,mixed>> $prenotazioni
     * @return array{testate:list<string>,righe:list<list<string>>}
     */
    private function anteprima(array $prenotazioni, int $quante = 4): array
    {
        $colonne = require __DIR__ . '/colonne.php';
        $mostra  = ['id', 'cognome', 'arrivo', 'adulti', 'prezzo_retta'];

        $testate = [];
        $tipi    = [];
        foreach ($mostra as $chiave) {
            foreach ($colonne as $definizione) {
                if ($definizione['chiave'] === $chiave) {
                    $testate[] = rtrim($definizione['testata']);
                    $tipi[$chiave] = $definizione['tipo'];
                }
            }
        }

        $righe = [];
        foreach (array_slice($prenotazioni, 0, $quante) as $prenotazione) {
            $riga = [];
            foreach ($mostra as $chiave) {
                $valore = $prenotazione[$chiave] ?? null;
                $riga[] = match (true) {
                    $valore === null || $valore === ''  => '',
                    $tipi[$chiave] === 'data'           => \Vblite\Convert\Vista::data((float) $valore),
                    $tipi[$chiave] === 'valuta'         => '€ ' . \Vblite\Convert\Vista::valuta((float) $valore),
                    default                             => (string) $valore,
                };
            }
            $righe[] = $riga;
        }

        return ['testate' => $testate, 'righe' => $righe];
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
        // Briciole: smalot legge tutto il PDF dentro parseFile(), quindi se il
        // processo muore prima della prima pagina il guasto e' li', non nel
        // nostro giro sulle pagine.
        \Vblite\Convert\Errori::passo('motore · apro il PDF');
        $parser = new Parser(static function (int $pagina, int $totale): void {
            if ($pagina === 1 || $pagina % 25 === 0 || $pagina === $totale) {
                \Vblite\Convert\Errori::passo('motore · pagina', ['n' => $pagina, 'su' => $totale]);
            }
        });
        $estratto = $parser->estrai($percorsoIngresso);
        \Vblite\Convert\Errori::passo('motore · lettura finita', ['righe' => count($estratto['righe'])]);

        $risultato = (new Raggruppatore($regole))->raggruppa($estratto['righe']);
        \Vblite\Convert\Errori::passo('motore · raggruppamento finito');

        // «Da rivedere» conta solo cio' che chiede una decisione umana: le voci
        // informative dicono cosa e' stato fatto e non vanno annunciate come lavoro.
        $daCorreggere = 0;
        foreach ($risultato['anomalie'] as $anomalia) {
            $daCorreggere += ($anomalia['gravita'] ?? 'correggi') === 'correggi' ? 1 : 0;
        }

        $periodo = ($estratto['intestazione']['dal'] ?? null) !== null
            ? $estratto['intestazione']['dal'] . ' – ' . $estratto['intestazione']['al']
            : 'periodo non dichiarato';

        return [
            'sommario' => sprintf(
                '%s pagine · %s righe cliente · %s prenotazioni · %s · testo nativo',
                number_format($estratto['pagine'], 0, ',', '.'),
                number_format($risultato['righe_lette'], 0, ',', '.'),
                number_format(count($risultato['prenotazioni']), 0, ',', '.'),
                $periodo
            ),
            'pagine'        => $estratto['pagine'],
            'intestazione'  => $estratto['intestazione'],
            'righe_lette'   => $risultato['righe_lette'],
            'prenotazioni'  => count($risultato['prenotazioni']),
            'anomalie'      => $daCorreggere,
            'anteprima'     => array_slice($risultato['prenotazioni'], 0, 2),
        ];
    }
}
