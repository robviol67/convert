<?php
declare(strict_types=1);

namespace Vblite\Convert\Conversioni\OctoScidoo;

use Vblite\Convert\Supporto\FoglioXlsx;

/**
 * Scrive il «File Import Prenotazioni» di Scidoo: intestazioni in riga 1 da B1,
 * date come date vere (seriali Excel) e importi come numeri.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * PERCHE' NON PHPSPREADSHEET
 *
 * PhpSpreadsheet tiene tutto il foglio in memoria: su queste 584 righe arrivava
 * a 56 MB. L'hosting di produzione uccide il processo poco sopra i 20 MB di dati
 * — misurato, non stimato: allocando a blocchi il processo muore fra i 18 e i
 * 20 MB, senza errore PHP e senza una riga di log, perche' e' il sistema a
 * fermarlo, non PHP.
 *
 * Un XLSX pero' e' solo uno zip con dentro qualche XML. Scriverlo di getto,
 * una riga alla volta su file, costa memoria costante: qui il picco non dipende
 * dal numero di righe. In cambio si rinuncia a formule, grafici e a tutto il
 * resto — che questo tracciato non usa.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class ScrittoreScidoo
{
    /** Stili definiti in styles.xml, nell'ordine in cui li scriviamo. */
    private const STILE_NORMALE   = 0;
    private const STILE_TESTATA   = 1;
    private const STILE_DATA      = 2;
    private const STILE_VALUTA    = 3;
    private const STILE_INTERO    = 4;

    /** @var list<array{chiave:string,testata:string,tipo:string,larghezza:float}> */
    private array $colonne;

    /** @param list<array{chiave:string,testata:string,tipo:string,larghezza:float}>|null $colonne */
    public function __construct(?array $colonne = null)
    {
        $this->colonne = $colonne ?? require __DIR__ . '/colonne.php';
    }

    /** @return list<string> */
    public function testate(): array
    {
        return array_map(static fn(array $c): string => $c['testata'], $this->colonne);
    }

    /**
     * @param iterable<array<string,mixed>> $prenotazioni
     * @return int righe scritte
     */
    public function scrivi(iterable $prenotazioni, string $percorso, string $formato = 'xlsx'): int
    {
        $this->apri($percorso, $formato);
        foreach ($prenotazioni as $prenotazione) {
            $this->aggiungi($prenotazione);
        }

        return $this->chiudi();
    }

    /** @var resource|null */
    private $csv = null;

    private ?FoglioXlsx $foglio = null;

    private int $scritte = 0;

    /**
     * Apre il file per scriverci una prenotazione alla volta.
     *
     * Chi raggruppa da disco non ha mai tutte le prenotazioni in mano: le
     * consegna man mano, e qui si scrivono man mano.
     */
    public function apri(string $percorso, string $formato = 'xlsx'): void
    {
        $this->scritte = 0;

        if ($formato === 'csv') {
            $f = fopen($percorso, 'w');
            if ($f === false) {
                throw new \RuntimeException("Non riesco a scrivere {$percorso}");
            }
            fwrite($f, "\u{FEFF}");                       // BOM: Excel apra in UTF-8
            fputcsv($f, array_merge([''], $this->testate()), ';', '"', '\\');
            $this->csv = $f;

            return;
        }

        // La meccanica dell'XLSX — ordine degli elementi, stili, scrittura in
        // streaming — sta in FoglioXlsx: qui resta solo il tracciato Scidoo.
        $this->foglio = new FoglioXlsx();
        $this->foglio->apri($percorso, $this->colonne, 2);   // la colonna A resta libera
    }

    /** @param array<string,mixed> $prenotazione */
    public function aggiungi(array $prenotazione): void
    {
        if ($this->csv !== null) {
            $riga = [''];
            foreach ($this->colonne as $colonna) {
                $riga[] = $this->testoCsv($prenotazione[$colonna['chiave']] ?? null, $colonna['tipo']);
            }
            fputcsv($this->csv, $riga, ';', '"', '\\');
        } elseif ($this->foglio !== null) {
            $riga = [];
            foreach ($this->colonne as $colonna) {
                $riga[] = $prenotazione[$colonna['chiave']] ?? null;
            }
            $this->foglio->riga($riga);
        } else {
            throw new \LogicException('Scrittore non aperto');
        }
        $this->scritte++;
    }

    public function chiudi(): int
    {
        if ($this->csv !== null) {
            fclose($this->csv);
            $this->csv = null;

            return $this->scritte;
        }
        if ($this->foglio !== null) {
            $scritte = $this->foglio->chiudi();
            $this->foglio = null;

            return $scritte;
        }

        return 0;
    }

    private function testoCsv(mixed $valore, string $tipo): string
    {
        if ($valore === null || $valore === '') {
            return '';
        }

        return match ($tipo) {
            // Nel CSV le date tornano leggibili: un seriale non direbbe niente.
            'data'   => \Vblite\Convert\Vista::data((float) $valore),
            'valuta' => number_format((float) $valore, 2, ',', ''),
            'intero' => (string) (int) $valore,
            default  => (string) $valore,
        };
    }

    /** Indice 0 → «B», perche' la colonna A resta libera. */
    private function lettera(int $indice): string
    {
        $n       = $indice + 2;
        $lettera = '';
        while ($n > 0) {
            $resto   = ($n - 1) % 26;
            $lettera = chr(65 + $resto) . $lettera;
            $n       = (int) (($n - $resto) / 26);
        }

        return $lettera;
    }
}
