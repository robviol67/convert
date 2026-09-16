<?php
declare(strict_types=1);

namespace Vblite\Convert\Conversioni\OctoScidoo;

/**
 * Le righe estratte dalla stampa, su disco invece che in memoria.
 *
 * Una riga pesa circa 3 KB in memoria, e una stampa di 1.849 pagine ne ha
 * undicimila: 35 MB, contro un tetto di venti. Su disco una riga è una riga
 * di testo, e per raggruppare basta sapere dove sta.
 *
 * Il raggruppamento dà lo stesso risultato di quello in memoria: prenotazioni
 * nell'ordine in cui compaiono per la prima volta, ognuna con tutte le sue
 * righe anche se sono sparse nella stampa — succede, 4 volte su 584 nel file
 * di esempio, e raggruppare «finché il numero non cambia» le avrebbe doppiate.
 *
 * Il formato è una riga per riga estratta: numero di prenotazione, tabulazione,
 * JSON. Il numero in testa serve a costruire l'indice senza decodificare niente.
 */
final class ArchivioRighe
{
    /** @var resource|null */
    private $scrittura = null;

    public function __construct(private readonly string $percorso)
    {
    }

    /** Quanto è lungo il file: è il punto a cui tornare se un passo muore a metà. */
    public function lunghezza(): int
    {
        clearstatcache(true, $this->percorso);

        return is_file($this->percorso) ? (int) filesize($this->percorso) : 0;
    }

    /**
     * Riporta il file alla fine dell'ultimo passo concluso.
     *
     * Un passo ucciso a metà lascia righe scritte a metà lettura: rileggendo
     * quelle pagine uscirebbero doppie. Si torna al punto sicuro e si riparte.
     */
    public function tronca(int $lunghezza): void
    {
        if ($this->lunghezza() <= $lunghezza) {
            return;
        }
        $f = fopen($this->percorso, 'r+');
        if ($f !== false) {
            ftruncate($f, $lunghezza);
            fclose($f);
        }
    }

    /** @param array<string,string> $riga */
    public function aggiungi(array $riga): void
    {
        if ($this->scrittura === null) {
            $f = fopen($this->percorso, 'ab');
            if ($f === false) {
                throw new \RuntimeException("Non riesco a scrivere {$this->percorso}");
            }
            $this->scrittura = $f;
        }

        $numero = str_replace(["\t", "\n", "\r"], ' ', (string) $riga['npren']);
        fwrite($this->scrittura, $numero . "\t" . json_encode($riga, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
    }

    public function chiudiScrittura(): void
    {
        if ($this->scrittura !== null) {
            fclose($this->scrittura);
            $this->scrittura = null;
        }
    }

    /**
     * Passa le prenotazioni una alla volta, con tutte le loro righe.
     *
     * In memoria sta solo l'indice — dove comincia ogni riga, per numero — che
     * per cinquemila prenotazioni è meno di un megabyte.
     *
     * @param callable(string,list<array<string,string>>):void $consuma
     * @return int righe lette
     */
    public function perPrenotazione(callable $consuma): int
    {
        $this->chiudiScrittura();

        $f = @fopen($this->percorso, 'rb');
        if ($f === false) {
            return 0;
        }

        // Le posizioni si impaccano in una stringa: un vettore PHP di interi
        // costerebbe dieci volte tanto.
        $indice = [];
        $righe  = 0;
        while (true) {
            $posizione = ftell($f);
            $linea = fgets($f);
            if ($linea === false) {
                break;
            }
            $tab = strpos($linea, "\t");
            if ($tab === false) {
                continue;
            }
            $numero = 'n' . substr($linea, 0, $tab);   // la «n» evita che «999» diventi una chiave intera
            $indice[$numero] = ($indice[$numero] ?? '') . pack('J', $posizione);
            $righe++;
        }

        foreach ($indice as $numero => $posizioni) {
            $gruppo = [];
            foreach (str_split($posizioni, 8) as $impaccata) {
                fseek($f, unpack('J', $impaccata)[1]);
                $linea = (string) fgets($f);
                $gruppo[] = json_decode(substr($linea, strpos($linea, "\t") + 1), true, 16, JSON_THROW_ON_ERROR);
            }
            unset($indice[$numero]);
            $consuma(substr((string) $numero, 1), $gruppo);
        }
        fclose($f);

        return $righe;
    }

    public function elimina(): void
    {
        $this->chiudiScrittura();
        @unlink($this->percorso);
    }
}
