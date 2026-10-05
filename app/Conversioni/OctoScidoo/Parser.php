<?php
declare(strict_types=1);

namespace Vblite\Convert\Conversioni\OctoScidoo;

use Smalot\PdfParser\Parser as PdfParser;
use Vblite\Convert\Supporto\SpezzaPdf;

/**
 * Estrae le righe di una stampa Octorate.
 *
 * La stampa e' una tabella a colonne fisse con piu' righe logiche per record.
 * Anziche' leggere il testo impaginato (fragile), ricostruiamo le colonne dalle
 * coordinate: le intestazioni si ripetono su ogni pagina e danno gli ancoraggi.
 *
 * Le stampe che sappiamo leggere sono due e hanno colonne diverse, ma lo stesso
 * impianto: quale sia si riconosce dal titolo a pagina 1, e quello che cambia
 * lo dichiara il Tracciato. Il motore qui sotto e' uno solo.
 */
final class Parser
{
    /** Passo verticale fra le righe logiche di un record. */
    private const PASSO_RIGA = 10.2;

    /**
     * Oltre questa dimensione un PDF che non si sa spezzare non si apre intero.
     * La stampa di 201 pagine pesa 446 KB e ne occupa una decina in memoria.
     */
    private const MAX_BYTE_INTERO = 700 * 1024;

    private ?\Closure $progresso = null;

    /** Quale stampa si sta leggendo: la decide la prima pagina. */
    private ?Tracciato $tracciato = null;

    /** @param callable(int,int):void|null $progresso pagina corrente, totale */
    public function __construct(?callable $progresso = null)
    {
        $this->progresso = $progresso !== null ? \Closure::fromCallable($progresso) : null;
    }

    /**
     * @return array{
     *   righe: list<array<string,string>>,
     *   pagine: int,
     *   intestazione: array{struttura:string,dal:?string,al:?string}
     * }
     */
    public function estrai(string $percorsoPdf): array
    {
        $this->tracciato = null;
        $righe = [];
        $esito = $this->scorri($percorsoPdf, 1, static function (array $riga) use (&$righe): void {
            $righe[] = $riga;
        });

        return ['righe' => $righe, 'pagine' => $esito['pagine'], 'intestazione' => $esito['intestazione']];
    }

    /**
     * Legge un PDF e passa le righe una alla volta, invece di accumularle.
     *
     * Il PDF può essere intero oppure un pezzo che comincia dalla pagina
     * $primaPagina dell'originale: le righe portano il numero di pagina vero.
     * Una riga estratta pesa circa 3 KB, e una stampa di 1.849 pagine ne ha
     * undicimila: tenerle tutte in memoria per raggrupparle dopo non si può.
     *
     * @param callable(array<string,string>):void $perRiga
     * @return array{pagine:int,intestazione:array<string,mixed>}
     */
    public function scorri(string $percorsoPdf, int $primaPagina, callable $perRiga): array
    {
        $documento = (new PdfParser())->parseFile($percorsoPdf);
        $pagine    = $documento->getPages();
        $totale    = count($pagine);

        $intestazione = ['struttura' => '', 'dal' => null, 'al' => null];

        foreach ($pagine as $indice => $pagina) {
            $chunk = $this->chunkOrdinati($pagina->getDataTm());
            self::liberaPagina($pagina);

            // Nei pezzi dopo il primo la stampa è già nota (usa()); nel primo
            // si riconosce dalla prima pagina.
            if ($this->tracciato === null) {
                $this->tracciato = Tracciato::riconosci(implode(' ', array_column($chunk, 't')));
                if ($this->tracciato === null) {
                    throw new \RuntimeException('Non riconosco questa stampa: accetto ' . Tracciato::nomi() . '.');
                }
            }
            if ($indice === 0) {
                $intestazione = $this->leggiTestata($chunk) + ['stampa' => $this->tracciato->nome];
            }

            $colonne = $this->bandeColonne($chunk);
            if ($colonne !== null) {
                foreach ($this->righePagina($chunk, $colonne, $primaPagina + $indice) as $riga) {
                    $perRiga($riga);
                }
            }
            // una pagina senza tabella si scarta insieme alla testata

            if ($this->progresso !== null) {
                ($this->progresso)($indice + 1, $totale);
            }
        }

        return ['pagine' => $totale, 'intestazione' => $intestazione];
    }

    /** Per i pezzi dopo il primo: la stampa è stata riconosciuta all'inizio. */
    public function usa(Tracciato $tracciato): void
    {
        $this->tracciato = $tracciato;
    }

    public function tracciato(): ?Tracciato
    {
        return $this->tracciato;
    }

    /**
     * Verifica che il PDF sia una delle stampe Octorate che sappiamo leggere.
     *
     * Guarda solo la prima pagina, e per guardarla non apre il documento
     * intero: la libreria che legge i PDF li carica tutti, e su una stampa di
     * 1.849 pagine costava 54 MB solo aprirlo — il processo veniva ucciso qui,
     * al caricamento, prima ancora di poter dire che il file era troppo grande.
     *
     * @return array{ok:bool,motivo:?string,pagine:int,intestazione:array}
     */
    public function riconosci(string $percorsoPdf): array
    {
        $this->tracciato = null;
        $spezzabile = SpezzaPdf::apri($percorsoPdf);

        if ($spezzabile === null) {
            // Un PDF che non sappiamo spezzare si può solo aprire intero: va
            // bene finché è piccolo, e oltre si dice invece di morire.
            if ((int) @filesize($percorsoPdf) > self::MAX_BYTE_INTERO) {
                return [
                    'ok' => false,
                    'motivo' => 'Questo PDF è grande e ha una struttura che non so leggere a pezzi '
                        . '(xref compressa o cifratura): intero non sta nella memoria del server. '
                        . 'Esporta la stampa da Octorate per un periodo più corto.',
                    'pagine' => 0,
                    'intestazione' => [],
                ];
            }

            return $this->riconosciPrimaPagina($percorsoPdf, null);
        }

        $pagine = $spezzabile->pagine();
        $prima  = (string) tempnam(sys_get_temp_dir(), 'octo');
        try {
            $spezzabile->estrai(1, 1, $prima);
            $spezzabile->chiudi();

            return $this->riconosciPrimaPagina($prima, $pagine);
        } finally {
            @unlink($prima);
        }
    }

    /** @return array{ok:bool,motivo:?string,pagine:int,intestazione:array} */
    private function riconosciPrimaPagina(string $percorsoPdf, ?int $pagineVere): array
    {
        try {
            $documento = (new PdfParser())->parseFile($percorsoPdf);
        } catch (\Throwable $e) {
            return ['ok' => false, 'motivo' => 'PDF illeggibile', 'pagine' => 0, 'intestazione' => []];
        }

        $pagine = $documento->getPages();
        if ($pagine === []) {
            return ['ok' => false, 'motivo' => 'PDF vuoto', 'pagine' => 0, 'intestazione' => []];
        }
        $quante = $pagineVere ?? count($pagine);

        $chunk = $this->chunkOrdinati($pagine[0]->getDataTm());
        $testo = implode(' ', array_map(static fn(array $c): string => $c['t'], $chunk));

        $this->tracciato = Tracciato::riconosci($testo);
        if ($this->tracciato === null) {
            return [
                'ok' => false,
                'motivo' => 'Non è una stampa Octorate di quelle che leggo: accetto ' . Tracciato::nomi()
                    . '. Se quella che hai è un\'altra stampa, dimmelo: il metodo è lo stesso.',
                'pagine' => $quante,
                'intestazione' => [],
            ];
        }
        if ($this->bandeColonne($chunk) === null) {
            return [
                'ok' => false,
                'motivo' => 'È una «' . $this->tracciato->nome . '», ma non ne riconosco le colonne: '
                    . 'la testata è diversa da quella attesa.',
                'pagine' => $quante,
                'intestazione' => [],
            ];
        }

        return [
            'ok' => true,
            'motivo' => null,
            'pagine' => $quante,
            'intestazione' => $this->leggiTestata($chunk) + ['stampa' => $this->tracciato->nome],
        ];
    }

    /**
     * Butta via i dati che la pagina si e' tenuta.
     *
     * getDataTm() memorizza il risultato in Page::$dataTm, e il Document tiene
     * tutte le pagine: su una stampa di 201 pagine questo significa portarsi
     * dietro l'intero documento estratto fino alla fine. Su un hosting con poca
     * memoria e' la differenza fra finire e non finire — misurato: il processo
     * moriva alla pagina 186. I dati di una pagina non servono piu' appena
     * l'abbiamo letta.
     *
     * La proprieta' e' protected e la libreria non offre un modo di svuotarla:
     * si passa dalla riflessione, con la cautela di non rompersi se un domani
     * quella proprieta' cambiasse nome.
     */
    private static function liberaPagina(object $pagina): void
    {
        static $proprieta = false;

        if ($proprieta === false) {
            $proprieta = null;
            try {
                // Da PHP 8.1 le proprieta' protette sono gia' raggiungibili
                // dalla riflessione: setAccessible() non serve piu'.
                $proprieta = new \ReflectionProperty($pagina, 'dataTm');
            } catch (\ReflectionException) {
                // La libreria e' cambiata: si tira dritto, costa solo memoria.
            }
        }

        $proprieta?->setValue($pagina, null);
    }

    /** @return list<array{x:float,y:float,t:string}> ordinati per riga, poi per colonna */
    private function chunkOrdinati(array $dataTm): array
    {
        $chunk = [];
        foreach ($dataTm as $elemento) {
            $testo = $elemento[1];
            if (trim($testo) === '') {
                continue;
            }
            $chunk[] = ['x' => (float) $elemento[0][4], 'y' => (float) $elemento[0][5], 't' => $testo];
        }
        usort($chunk, static fn(array $a, array $b): int => ($b['y'] <=> $a['y']) ?: ($a['x'] <=> $b['x']));

        return $chunk;
    }

    /** @return array{struttura:string,dal:?string,al:?string} */
    private function leggiTestata(array $chunk): array
    {
        $struttura = '';
        $dal = $al = null;

        foreach ($chunk as $c) {
            $t = trim($c['t']);
            if ($struttura === '' && $c['x'] < 40 && $c['y'] > 500 && mb_strlen($t) > 8
                && !str_starts_with($t, 'Stampa')) {
                $struttura = $t;
            }
            if (preg_match('~Dal\s+(\d{2}/\d{2}/\d{4})\s+al\s+(\d{2}/\d{2}/\d{4})~u', $t, $m)) {
                [$dal, $al] = [$m[1], $m[2]];
            }
        }

        return ['struttura' => $struttura, 'dal' => $dal, 'al' => $al];
    }

    /**
     * Bande orizzontali delle colonne, ricavate dalle intestazioni della pagina.
     * I confini stanno a meta' fra due ancore: i valori sono allineati a sinistra,
     * a destra o centrati a seconda della colonna, ma restano sempre dentro la banda.
     *
     * @return array{y0:float,limiti:array<string,array{0:float,1:float}>}|null
     */
    private function bandeColonne(array $chunk): ?array
    {
        $ancore = $this->tracciato?->ancore ?? [];
        if ($ancore === []) {
            return null;
        }

        // Prima si cerca la colonna del numero: la sua e' la riga di testata, e
        // da li' in poi si accettano solo ancore che stiano su quella riga.
        // Senza questo vincolo un'etichetta di una lettera sola — la «A» di
        // «Adulti» — si farebbe riconoscere in mezzo al testo di un'altra riga.
        $x  = [];
        $y0 = null;
        foreach ($chunk as $c) {
            if (in_array(trim($c['t']), (array) $ancore['npren'], true)) {
                $x['npren'] = $c['x'];
                $y0         = $c['y'];
                break;
            }
        }
        if ($y0 === null) {
            return null;
        }

        // Mezza riga di tolleranza, non meno: sulla stampa clienti le ultime tre
        // intestazioni sono stampate 3,4 punti piu' in basso delle altre, e con
        // un margine stretto sparivano — e con loro tutta la tabella.
        foreach ($chunk as $c) {
            if (abs($c['y'] - $y0) > self::PASSO_RIGA / 2) {
                continue;
            }
            $t = trim($c['t']);
            foreach ($ancore as $chiave => $etichetta) {
                if ($etichetta !== null && !isset($x[$chiave]) && in_array($t, (array) $etichetta, true)) {
                    $x[$chiave] = $c['x'];
                }
            }
        }

        $chiavi = array_keys($ancore);

        // Un'ancora dichiarata senza etichetta e' stampata in verticale — il
        // «Pax» della stampa clienti e' una P, una a e una x su tre righe — e
        // come etichetta non si puo' cercare. Si prende quel che c'e' fra le
        // due colonne vicine, e se non c'e' niente si sta nel mezzo.
        foreach ($chiavi as $i => $chiave) {
            if ($ancore[$chiave] !== null || isset($x[$chiave])) {
                continue;
            }
            $prima = $chiavi[$i - 1] ?? null;
            $dopo  = $chiavi[$i + 1] ?? null;
            if ($prima === null || $dopo === null || !isset($x[$prima], $x[$dopo])) {
                continue;
            }
            foreach ($chunk as $c) {
                if (abs($c['y'] - $y0) < 1 && $c['x'] > $x[$prima] && $c['x'] < $x[$dopo]) {
                    $x[$chiave] = $c['x'];
                    break;
                }
            }
            $x[$chiave] ??= ($x[$prima] + $x[$dopo]) / 2;
        }
        foreach ($chiavi as $chiave) {
            if (!isset($x[$chiave])) {
                return null; // testata incompleta: pagina non tabellare
            }
        }

        $limiti = [];
        foreach ($chiavi as $i => $chiave) {
            $sinistra = $i === 0 ? -INF : ($x[$chiavi[$i - 1]] + $x[$chiave]) / 2;
            $destra   = $i === count($chiavi) - 1 ? INF : ($x[$chiave] + $x[$chiavi[$i + 1]]) / 2;
            $limiti[$chiave] = [$sinistra, $destra];
        }

        return ['y0' => $y0, 'limiti' => $limiti];
    }

    /**
     * Un record inizia dove compare un N°pren. nella prima colonna e finisce
     * dove inizia il successivo. Le quattro righe logiche si distinguono per
     * scostamento verticale dalla prima.
     *
     * @return list<array<string,string>>
     */
    private function righePagina(array $chunk, array $colonne, int $numeroPagina): array
    {
        $limiti = $colonne['limiti'];
        $sottoTestata = $colonne['y0'] - ($this->tracciato->righeTestata - 1) * self::PASSO_RIGA - 4;

        // 1. individua gli inizi di record
        $inizi = [];
        foreach ($chunk as $i => $c) {
            if ($c['y'] >= $sottoTestata) {
                continue; // testata
            }
            if ($this->inBanda($c['x'], $limiti['npren'])
                && preg_match('~^\s*(\d{1,3}(?:\.\d{3})+|\d{3,6})\s*$~u', $c['t'])) {
                $inizi[] = ['i' => $i, 'y' => $c['y'], 'npren' => trim($c['t'])];
            }
        }
        if ($inizi === []) {
            return [];
        }

        // 2. per ogni record, raccogli i chunk fino all'inizio del successivo
        $righe = [];
        foreach ($inizi as $k => $inizio) {
            $yAlto  = $inizio['y'] + self::PASSO_RIGA / 2;
            $yBasso = isset($inizi[$k + 1]) ? $inizi[$k + 1]['y'] + self::PASSO_RIGA / 2 : -INF;

            $celle = [];
            foreach ($chunk as $c) {
                if ($c['y'] > $yAlto || $c['y'] <= $yBasso || $c['y'] >= $sottoTestata) {
                    continue;
                }
                $colonna = $this->colonnaDi($c['x'], $limiti);
                if ($colonna === null) {
                    continue;
                }
                $sottoRiga = (int) round(($inizio['y'] - $c['y']) / self::PASSO_RIGA);
                $celle[$colonna][] = ['r' => max(0, $sottoRiga), 'y' => $c['y'], 't' => $c['t']];
            }

            $righe[] = $this->componiRiga($inizio['npren'], $celle, $numeroPagina);
        }

        return $righe;
    }

    private function inBanda(float $x, array $banda): bool
    {
        return $x >= $banda[0] && $x < $banda[1];
    }

    /** @return string|null chiave di colonna, null se fuori da ogni banda */
    private function colonnaDi(float $x, array $limiti): ?string
    {
        foreach ($limiti as $chiave => $banda) {
            if ($this->inBanda($x, $banda)) {
                return $chiave;
            }
        }

        return null;
    }

    /**
     * Distribuisce le celle sui 23 campi della stampa: le colonne multiriga
     * (anagrafica, agenzia, importi) si leggono per indice di sotto-riga; i testi
     * lunghi (Commenti, Supplementi) si concatenano nell'ordine verticale.
     *
     * @return array<string,string>
     */
    private function componiRiga(string $npren, array $celle, int $numeroPagina): array
    {
        // I numeri col separatore delle migliaia arrivano spezzati («1» + «.490,20»):
        // sulle colonne monetarie si ricuce senza spazio.
        $sub = static function (array $celle, string $colonna, int $riga, bool $numerica = false): string {
            $pezzi = [];
            foreach ($celle[$colonna] ?? [] as $cella) {
                if ($cella['r'] === $riga) {
                    $pezzi[] = trim($cella['t']);
                }
            }
            $testo = trim(implode(' ', $pezzi));

            return $numerica ? (string) preg_replace('~\s+~u', '', $testo) : $testo;
        };

        $tutte = static function (array $celle, string $colonna): string {
            $lista = $celle[$colonna] ?? [];
            usort($lista, static fn(array $a, array $b): int => $b['y'] <=> $a['y']);

            return implode("\n", array_map(static fn(array $c): string => rtrim($c['t']), $lista));
        };

        // La colonna anagrafica ha Cognome/Nome/Telefono/E-Mail su quattro righe,
        // ma il passo reale non e' costante: e' piu' sicuro riconoscerle dal contenuto.
        $anagrafica = $celle['cognome'] ?? [];
        usort($anagrafica, static fn(array $a, array $b): int => $b['y'] <=> $a['y']);
        $cognome = $nome = $telefono = $email = '';
        foreach ($anagrafica as $indice => $cella) {
            $t = trim($cella['t']);
            if ($t === '') {
                continue;
            }
            if (str_contains($t, '@')) {
                $email = $email === '' ? $t : $email . $t;
            } elseif (preg_match('~^\+?\d[\d\s./+-]*$~u', $t)) {
                // Solo cifre e separatori: è telefono, qualunque sia la lunghezza.
                // La stampa spezza la colonna e lascia spesso il prefisso da solo
                // su una riga — «0341» — e con una lunghezza minima quel pezzo
                // finiva attaccato al nome: «Chiara 0341». Nei nomi le cifre non
                // ci sono, quindi non c'è niente da proteggere.
                $telefono = $telefono === '' ? $t : $telefono . ' ' . $t;
            } elseif ($cognome === '' && $indice === 0) {
                $cognome = $t;
            } elseif ($nome === '') {
                $nome = $t;
            } else {
                $nome .= ' ' . $t;
            }
        }

        // Il resto delle colonne lo dichiara il tracciato: campo, banda e
        // quale delle righe logiche del record. È l'unico punto in cui le due
        // stampe divergono davvero.
        $riga = Tracciato::rigaVuota();
        $riga['npren']    = $npren;
        $riga['cognome']  = $cognome;
        $riga['nome']     = $nome;
        $riga['telefono'] = $telefono;
        $riga['email']    = $email;
        $riga['pagina']   = (string) $numeroPagina;

        foreach ($this->tracciato->campi as $campo => $dove) {
            $riga[$campo] = $dove[1] === 'tutte'
                ? $tutte($celle, $dove[0])
                : $sub($celle, $dove[0], (int) $dove[1], ($dove[2] ?? '') === 'num');
        }

        return $riga;
    }
}
