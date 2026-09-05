<?php
declare(strict_types=1);

namespace Vblite\Convert\Conversioni\OctoScidoo;

use Smalot\PdfParser\Parser as PdfParser;

/**
 * Estrae le righe-ospite dalla «Stampa clienti presenti» di Octorate.
 *
 * La stampa e' una tabella a colonne fisse con quattro righe logiche per ospite.
 * Anziche' leggere il testo impaginato (fragile), ricostruiamo le colonne dalle
 * coordinate: le intestazioni si ripetono su ogni pagina e danno gli ancoraggi.
 */
final class Parser
{
    /** Intestazioni della prima riga di testata, in ordine di colonna. */
    private const ANCORE = [
        'npren'       => 'N°pren.',
        'cognome'     => 'Cognome',
        'arrivo'      => 'Arrivo',
        'camera'      => 'Cam.',
        'pax'         => null,          // «P a x» su tre righe: interpolato
        'gruppo'      => 'Gruppo',
        'trattamento' => 'Trattamento',
        'importo'     => 'Importo',
        'supplementi' => 'Supplementi',
        'commenti'    => 'Commenti',
        'caparre'     => 'Caparre',
    ];

    /** Passo verticale fra le quattro righe logiche di un record. */
    private const PASSO_RIGA = 10.2;

    private ?\Closure $progresso = null;

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
        $documento = (new PdfParser())->parseFile($percorsoPdf);
        $pagine    = $documento->getPages();
        $totale    = count($pagine);

        $righe        = [];
        $intestazione = ['struttura' => '', 'dal' => null, 'al' => null];

        foreach ($pagine as $indice => $pagina) {
            $chunk = $this->chunkOrdinati($pagina->getDataTm());
            self::liberaPagina($pagina);

            if ($indice === 0) {
                $intestazione = $this->leggiTestata($chunk);
            }

            $colonne = $this->bandeColonne($chunk);
            if ($colonne === null) {
                continue; // pagina senza tabella: si scarta insieme alla testata
            }

            foreach ($this->righePagina($chunk, $colonne, $indice + 1) as $riga) {
                $righe[] = $riga;
            }

            if ($this->progresso !== null) {
                ($this->progresso)($indice + 1, $totale);
            }
        }

        return ['righe' => $righe, 'pagine' => $totale, 'intestazione' => $intestazione];
    }

    /**
     * Verifica che il PDF sia davvero una «Stampa clienti presenti» di Octorate.
     * Guarda solo la prima pagina: costa poco e blocca subito i file sbagliati.
     *
     * @return array{ok:bool,motivo:?string,pagine:int,intestazione:array}
     */
    public function riconosci(string $percorsoPdf): array
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

        $chunk = $this->chunkOrdinati($pagine[0]->getDataTm());
        $testo = implode(' ', array_map(static fn(array $c): string => $c['t'], $chunk));

        if (!str_contains($testo, 'Stampa clienti presenti')) {
            return [
                'ok' => false,
                'motivo' => 'Non è una stampa clienti',
                'pagine' => count($pagine),
                'intestazione' => [],
            ];
        }
        if ($this->bandeColonne($chunk) === null) {
            return [
                'ok' => false,
                'motivo' => 'Colonne della stampa non riconosciute',
                'pagine' => count($pagine),
                'intestazione' => [],
            ];
        }

        return [
            'ok' => true,
            'motivo' => null,
            'pagine' => count($pagine),
            'intestazione' => $this->leggiTestata($chunk),
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
        $x  = [];
        $y0 = null;

        foreach ($chunk as $c) {
            $t = trim($c['t']);
            foreach (self::ANCORE as $chiave => $etichetta) {
                if ($etichetta !== null && $t === $etichetta && !isset($x[$chiave])) {
                    $x[$chiave] = $c['x'];
                    if ($chiave === 'npren') {
                        $y0 = $c['y'];
                    }
                }
            }
        }

        // «Pax» e' stampato in verticale (P / a / x): lo si prende dalla «P» isolata
        // sulla riga di testata, con ripiego a meta' fra Cam. e Gruppo.
        if (isset($x['camera'], $x['gruppo']) && $y0 !== null) {
            foreach ($chunk as $c) {
                if (trim($c['t']) === 'P' && abs($c['y'] - $y0) < 1
                    && $c['x'] > $x['camera'] && $c['x'] < $x['gruppo']) {
                    $x['pax'] = $c['x'];
                    break;
                }
            }
            $x['pax'] ??= ($x['camera'] + $x['gruppo']) / 2;
        }

        $chiavi = array_keys(self::ANCORE);
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
        $sottoTestata = $colonne['y0'] - 3 * self::PASSO_RIGA - 4;

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
            } elseif (preg_match('~^\+?[\d][\d\s./+-]{4,}$~u', $t)) {
                $telefono = $telefono === '' ? $t : $telefono . ' ' . $t;
            } elseif ($cognome === '' && $indice === 0) {
                $cognome = $t;
            } elseif ($nome === '') {
                $nome = $t;
            } else {
                $nome .= ' ' . $t;
            }
        }

        return [
            'npren'             => $npren,
            'data'              => $sub($celle, 'npren', 1),
            'ora'               => $sub($celle, 'npren', 2),
            'cognome'           => $cognome,
            'nome'              => $nome,
            'telefono'          => $telefono,
            'email'             => $email,
            'arrivo'            => $sub($celle, 'arrivo', 0),
            'partenza'          => $sub($celle, 'arrivo', 1),
            'camera'            => $sub($celle, 'camera', 0),
            'camere_tot'        => $sub($celle, 'camera', 1),
            'pax'               => $sub($celle, 'pax', 0),
            'gruppo'            => $sub($celle, 'gruppo', 0),
            'agenzia_pagante'   => $sub($celle, 'gruppo', 1),
            'agenzia_prenotante'=> $sub($celle, 'gruppo', 2),
            'voucher'           => $sub($celle, 'gruppo', 3),
            'trattamento'       => $sub($celle, 'trattamento', 0),
            'convenzione'       => $sub($celle, 'trattamento', 1),
            'data_opzione'      => $sub($celle, 'trattamento', 2),
            'importo'           => $sub($celle, 'importo', 0, true),
            'tassa_sogg'        => $sub($celle, 'importo', 1, true),
            'sconto'            => $sub($celle, 'importo', 2, true),
            'supplementi'       => $tutte($celle, 'supplementi'),
            'commenti'          => $tutte($celle, 'commenti'),
            'caparre'           => $sub($celle, 'caparre', 0, true),
            'acconti'           => $sub($celle, 'caparre', 1, true),
            'pagina'            => (string) $numeroPagina,
        ];
    }
}
