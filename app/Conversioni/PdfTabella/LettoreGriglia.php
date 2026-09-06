<?php
declare(strict_types=1);

namespace Vblite\Convert\Conversioni\PdfTabella;

use Smalot\PdfParser\Parser as PdfParser;

/**
 * Ricava una tabella dalle coordinate del testo di un PDF.
 *
 * Un PDF non contiene tabelle: contiene pezzi di testo con delle coordinate.
 * Quella che a occhio è una tabella, per il file è testo allineato — e
 * l'allineamento è l'unica cosa su cui si possa lavorare.
 *
 * Il metodo è quello che funziona sulla stampa Octorate, generalizzato: invece
 * di partire da intestazioni note, le colonne si trovano guardando **dove non
 * c'è mai niente**. Si proietta tutto il testo del documento su una riga
 * orizzontale; le fasce che restano vuote su tutte le pagine sono i corridoi
 * fra una colonna e l'altra. È robusto perché usa l'intero documento: un
 * corridoio che sopravvive a mille righe è un corridoio vero.
 *
 * Resta un metodo per indovinare, e va detto: su una tabella con colonne
 * strette e testo lungo i corridoi si chiudono e due colonne diventano una.
 * Per questo lo step 2 mostra il risultato prima di convertire.
 */
final class LettoreGriglia
{
    /** Due pezzi di testo sulla stessa riga se distano meno di così. */
    private const TOLLERANZA_RIGA = 3.0;

    /** Larghezza media di un carattere, in frazioni del corpo. */
    private const LARGHEZZA_CARATTERE = 0.5;

    /** @var list<float> i confini fra le colonne */
    private array $confini = [];

    /** @var array<string,int> firma della riga → su quante pagine compare */
    private array $ricorrenti = [];

    /** @var list<array{y:float,pezzi:list<array{x:float,larghezza:float,testo:string}>}> */
    private array $testateCandidate = [];

    /** La y della prima riga di dati della prima pagina. */
    private ?float $yPrimiDati = null;

    private int $pagine = 0;

    /**
     * @param float $corridoio larghezza minima di uno spazio vuoto perché conti
     *                         come separatore di colonna, in punti
     */
    public function __construct(
        private readonly string $percorso,
        private readonly float $corridoio = 6.0,
    ) {
    }

    /**
     * Prima passata: dove sono le colonne, e quali righe si ripetono.
     *
     * Si separa dalla lettura dei dati perché tenere in memoria tutte le righe
     * di una stampa lunga non si può: 201 pagine fanno 8.876 righe e da sole
     * superano il tetto dell'hosting. Il PDF si legge due volte — costa mezzo
     * secondo — e la memoria non dipende più dalla lunghezza del documento.
     *
     * @param callable(int,int):void|null $progresso
     */
    public function analizza(?callable $progresso = null): void
    {
        // Prima passata: quali righe si ripetono.
        //
        // La mappa è limitata, ma le firme già viste continuano sempre a
        // contare: una testata compare dalla prima pagina, quindi entra subito
        // e cresce. Le righe di dati riempiono il resto e poi smettono di
        // entrare, il che va benissimo — nessuna riga di dati arriverà mai
        // alla soglia.
        $pagineDi = [];
        $this->perOgniRiga(
            function (int $pagina, float $y, array $pezzi) use (&$pagineDi): void {
                foreach ([$this->firma($pezzi), $this->firmaSenzaNumeri($pezzi)] as $chiave) {
                    if ($chiave === '') {
                        continue;
                    }
                    if (isset($pagineDi[$chiave])) {
                        $pagineDi[$chiave][$pagina] = true;
                    } elseif (count($pagineDi) < 3000) {
                        $pagineDi[$chiave] = [$pagina => true];
                    }
                }
            },
            $progresso
        );
        $this->ricorrenti = $this->firmeRicorrenti($pagineDi);
        unset($pagineDi);

        // Seconda passata: dove sono le colonne, guardando SOLO i dati.
        //
        // Un piè di pagina piazzato in mezzo alla larghezza spezza in due un
        // corridoio e inventa una colonna che non esiste. Le righe che si
        // ripetono non devono avere voce in capitolo su dove stiano le
        // colonne: non sono dati.
        $occupazione            = [];
        $larghezza              = 0;
        $this->testateCandidate = [];
        $this->yPrimiDati       = null;

        $this->perOgniRiga(function (int $pagina, float $y, array $pezzi) use (&$occupazione, &$larghezza): void {
            $ripetuta = isset($this->ricorrenti[$this->firma($pezzi)])
                || isset($this->ricorrenti[$this->firmaSenzaNumeri($pezzi)]);

            // Le righe scartate della prima pagina si tengono da parte: fra
            // loro c'è quasi sempre la riga dei nomi di colonna, che si ripete
            // su ogni pagina ed è l'unica cosa che dia un nome alle colonne.
            if ($pagina === 1) {
                if ($ripetuta) {
                    if (count($this->testateCandidate) < 20) {
                        $this->testateCandidate[] = ['y' => $y, 'pezzi' => $pezzi];
                    }
                } elseif ($this->yPrimiDati === null) {
                    $this->yPrimiDati = $y;
                }
            }

            if ($ripetuta) {
                return;
            }
            foreach ($pezzi as $pezzo) {
                $da = (int) floor($pezzo['x']);
                $a  = (int) ceil($pezzo['x'] + $pezzo['larghezza']);
                for ($i = $da; $i <= $a; $i++) {
                    $occupazione[$i] = true;
                }
                $larghezza = max($larghezza, $a);
            }
        });

        $this->confini = $this->confiniDaOccupazione($occupazione, $larghezza);
    }

    /**
     * Seconda passata: le righe, una alla volta.
     *
     * @param callable(int,array<int,string>):void $consuma pagina, celle
     * @param bool $saltaRipetute testate e piè di pagina fuori dai dati
     */
    public function righe(callable $consuma, bool $saltaRipetute = true): int
    {
        $quante = 0;

        $this->perOgniRiga(function (int $pagina, float $y, array $pezzi) use ($consuma, $saltaRipetute, &$quante): void {
            // La firma si calcola sul testo grezzo, come nell'analisi: là le
            // colonne non erano ancora note, e due firme calcolate in modi
            // diversi non combaciano mai — solo le righe di una cella sola
            // finivano per corrispondere, e le testate restavano nei dati.
            if ($saltaRipetute
                && (isset($this->ricorrenti[$this->firma($pezzi)])
                    || isset($this->ricorrenti[$this->firmaSenzaNumeri($pezzi)]))) {
                return;
            }

            $celle = $this->celleDi($pezzi);

            if (trim(implode('', $celle)) === '') {
                return;
            }


            $consuma($pagina, $celle);
            $quante++;
        });

        return $quante;
    }

    /**
     * La riga che dà i nomi alle colonne, se il documento ne ha una.
     *
     * È la riga ripetuta su ogni pagina che sta sopra ai dati e riempie più
     * colonne: il titolo del documento ne riempie una, i nomi delle colonne
     * tutte. Va cercata fra le righe **scartate**, non fra i dati: le
     * intestazioni di una stampa si ripetono a ogni pagina, quindi il filtro
     * delle testate se le prende per prime — e chiedere «i nomi stanno nella
     * prima riga» finiva per consumare una riga di dati vera.
     *
     * @return array<int,string>|null celle per colonna
     */
    public function intestazioneRipetuta(): ?array
    {
        $migliore = null;
        $quante   = 1;

        foreach ($this->testateCandidate as $riga) {
            if ($this->yPrimiDati !== null && $riga['y'] <= $this->yPrimiDati) {
                continue;   // sta sotto ai dati: è un piè di pagina
            }
            $celle = $this->celleDi($riga['pezzi']);
            // Le righe arrivano dall'alto in basso: a parità di colonne vince
            // l'ultima, cioè quella attaccata ai dati.
            if (count($celle) >= $quante) {
                $quante   = count($celle);
                $migliore = $celle;
            }
        }

        return $migliore;
    }

    /**
     * I pezzi di una riga distribuiti nelle colonne.
     *
     * @param list<array{x:float,larghezza:float,testo:string}> $pezzi
     * @return array<int,string>
     */
    private function celleDi(array $pezzi): array
    {
        $celle = [];
        foreach ($pezzi as $pezzo) {
            $colonna = $this->colonnaDi($pezzo['x']);
            $celle[$colonna] = isset($celle[$colonna])
                ? $celle[$colonna] . ' ' . $pezzo['testo']
                : $pezzo['testo'];
        }
        ksort($celle);

        return $celle;
    }

    public function colonne(): int
    {
        return count($this->confini) + 1;
    }

    public function pagine(): int
    {
        return $this->pagine;
    }

    /** Quante righe sono state riconosciute come testate o piè di pagina. */
    public function quanteRipetute(): int
    {
        return count($this->ricorrenti);
    }

    /**
     * La firma di una riga corta con i numeri sostituiti.
     *
     * «Pagina 1», «Pagina 2»… non sono la stessa riga per il confronto
     * letterale, ma sono lo stesso piè di pagina. Si applica solo alle righe
     * di pochi pezzi, che è quello che sono testate e numeri di pagina: su una
     * riga di dati collasserebbe informazione vera.
     *
     * @param list<array{x:float,larghezza:float,testo:string}> $pezzi
     */
    private function firmaSenzaNumeri(array $pezzi): string
    {
        // Al massimo due pezzi e poco testo: è la forma di un piè di pagina.
        // Con un limite più largo la regola divorava le righe di dati, che in
        // una tabella differiscono dalle altre proprio nei numeri — «ART-1
        // Articolo 1 1,50 10» e «ART-2 Articolo 2 3,00 20» hanno la stessa
        // firma senza cifre, e sparivano tutte.
        if (count($pezzi) > 2) {
            return '';
        }
        $firma = $this->firma($pezzi);
        if (mb_strlen($firma) > 60) {
            return '';
        }
        $senza = (string) preg_replace('~\d+~u', '#', $firma);

        return $senza === $firma ? '' : $senza;
    }

    /**
     * La firma di una riga: il suo testo, normalizzato.
     *
     * @param list<array{x:float,larghezza:float,testo:string}> $pezzi
     */
    public function firma(array $pezzi): string
    {
        return mb_strtolower(trim((string) preg_replace('~\s+~u', ' ', $this->testoDi($pezzi))));
    }

    /**
     * Scorre il PDF una volta, passando ogni riga a chi la vuole.
     *
     * @param callable(int,float,list<array{x:float,larghezza:float,testo:string}>):void $perRiga
     * @param callable(int,int):void|null $progresso
     */
    private function perOgniRiga(callable $perRiga, ?callable $progresso = null): void
    {
        $documento = (new PdfParser())->parseFile($this->percorso);
        $pagine    = $documento->getPages();
        $this->pagine = count($pagine);

        foreach ($pagine as $indice => $pagina) {
            foreach ($this->righeDiPagina($pagina) as $riga) {
                $perRiga($indice + 1, $riga['y'], $riga['pezzi']);
            }
            $this->libera($pagina);

            if ($progresso !== null) {
                $progresso($indice + 1, $this->pagine);
            }
        }
    }

    /** @param list<array{x:float,larghezza:float,testo:string}> $pezzi */
    private function testoDi(array $pezzi): string
    {
        return implode(' ', array_column($pezzi, 'testo'));
    }

    /**
     * Le righe che si ripetono su quasi tutte le pagine: sono testate e piè di
     * pagina, non dati. Sulla stampa Octorate erano il primo ostacolo, e su
     * qualunque stampa lo sono.
     *
     * La soglia è metà delle pagine: una riga di dati non si ripete identica
     * su cento pagine, una testata sì.
     *
     * @param array<string,array<int,bool>> $pagineDi
     * @return array<string,int>
     */
    private function firmeRicorrenti(array $pagineDi): array
    {
        if ($this->pagine < 3) {
            return [];
        }

        $soglia     = max(3, (int) ($this->pagine * 0.5));
        $ricorrenti = [];
        foreach ($pagineDi as $firma => $pagine) {
            if (count($pagine) >= $soglia) {
                $ricorrenti[(string) $firma] = count($pagine);
            }
        }

        return $ricorrenti;
    }

    /**
     * Le righe di una pagina, ognuna coi suoi pezzi di testo.
     *
     * @return list<array{y:float,pezzi:list<array{x:float,larghezza:float,testo:string}>}>
     */
    private function righeDiPagina(object $pagina): array
    {
        $pezzi = [];
        foreach ($pagina->getDataTm() as $elemento) {
            $testo = trim((string) $elemento[1]);
            if ($testo === '') {
                continue;
            }
            $matrice = $elemento[0];
            $corpo   = sqrt(abs(
                (float) $matrice[0] * (float) $matrice[3] - (float) $matrice[1] * (float) $matrice[2]
            ));
            $corpo = $corpo > 0 ? $corpo : 10.0;

            $pezzi[] = [
                'x'         => (float) $matrice[4],
                'y'         => (float) $matrice[5],
                'testo'     => $testo,
                // La larghezza vera richiederebbe le metriche del font
                // incorporato; qui basta una stima, perché serve solo a capire
                // dove il testo finisce e comincia il vuoto.
                'larghezza' => mb_strlen($testo) * $corpo * self::LARGHEZZA_CARATTERE,
            ];
        }

        if ($pezzi === []) {
            return [];
        }

        usort($pezzi, static fn(array $a, array $b): int => ($b['y'] <=> $a['y']) ?: ($a['x'] <=> $b['x']));

        $righe   = [];
        $corrente = null;
        foreach ($pezzi as $pezzo) {
            if ($corrente !== null && abs($corrente['y'] - $pezzo['y']) <= self::TOLLERANZA_RIGA) {
                $corrente['pezzi'][] = $pezzo;
                continue;
            }
            if ($corrente !== null) {
                $righe[] = $corrente;
            }
            $corrente = ['y' => $pezzo['y'], 'pezzi' => [$pezzo]];
        }
        if ($corrente !== null) {
            $righe[] = $corrente;
        }

        return $righe;
    }

    /**
     * I confini di colonna: il centro di ogni corridoio vuoto.
     *
     * @param array<int,bool> $occupazione
     * @return list<float>
     */
    private function confiniDaOccupazione(array $occupazione, int $larghezza): array
    {
        $confini = [];
        $inizio  = null;

        // Si parte dal primo punto occupato: il margine sinistro non è una colonna.
        $primo = $occupazione === [] ? 0 : min(array_keys($occupazione));

        for ($i = $primo; $i <= $larghezza; $i++) {
            if (isset($occupazione[$i])) {
                if ($inizio !== null && $i - $inizio >= $this->corridoio) {
                    $confini[] = ($inizio + $i) / 2;
                }
                $inizio = null;
                continue;
            }
            $inizio ??= $i;
        }

        return $confini;
    }

    private function colonnaDi(float $x): int
    {
        $colonna = 0;
        foreach ($this->confini as $confine) {
            if ($x >= $confine) {
                $colonna++;
                continue;
            }
            break;
        }

        return $colonna;
    }

    /** Come nel lettore Octorate: i dati di una pagina letta non servono più. */
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
