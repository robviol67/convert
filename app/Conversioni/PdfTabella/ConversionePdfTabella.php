<?php
declare(strict_types=1);

namespace Vblite\Convert\Conversioni\PdfTabella;

use Vblite\Convert\Conversioni\Conversione;
use Vblite\Convert\Conversioni\Tabelle\ConversioneTabelle;
use Vblite\Convert\Supporto\FoglioXlsx;

/**
 * PDF tabellare → foglio di calcolo.
 *
 * È la più incerta delle conversioni, e la ragione è strutturale: **un PDF non
 * contiene tabelle**. Contiene pezzi di testo con delle coordinate, e quella
 * che a occhio è una tabella per il file è solo testo allineato.
 *
 * Le colonne si ricavano guardando dove non c'è mai niente su tutto il
 * documento (vedi LettoreGriglia). Funziona bene sulle stampe dei gestionali,
 * che allineano davvero; funziona meno dove le colonne sono strette e il testo
 * lungo, perché i corridoi vuoti si chiudono e due colonne diventano una.
 *
 * Per questo lo step 2 mostra il risultato del riconoscimento **prima** di
 * convertire, e la larghezza minima del corridoio è regolabile: è il solo
 * modo onesto di consegnare una conversione che indovina.
 */
final class ConversionePdfTabella implements Conversione
{
    public const MAX_BYTE = 30 * 1024 * 1024;

    /** Quante righe leggere per l'anteprima dello step 2. */
    private const RIGHE_ANTEPRIMA = 8;

    public static function chiave(): string
    {
        return 'pdf_tabella';
    }

    public function manifest(): array
    {
        return require __DIR__ . '/manifest.php';
    }

    public function verifica(string $percorsoIngresso): array
    {
        if (strtolower(pathinfo($percorsoIngresso, PATHINFO_EXTENSION)) !== 'pdf') {
            return ['ok' => false, 'motivo' => 'Serve un PDF.', 'pagine' => 0, 'intestazione' => []];
        }

        $byte = (int) @filesize($percorsoIngresso);
        if ($byte > self::MAX_BYTE) {
            return [
                'ok' => false,
                'motivo' => 'Il PDF pesa ' . round($byte / 1048576) . ' MB: il massimo è '
                    . (self::MAX_BYTE / 1048576) . ' MB.',
                'pagine' => 0,
                'intestazione' => [],
            ];
        }

        try {
            $griglia = new LettoreGriglia($percorsoIngresso);
            $griglia->analizza();
        } catch (\Throwable $e) {
            return ['ok' => false, 'motivo' => 'PDF illeggibile: ' . $e->getMessage(), 'pagine' => 0, 'intestazione' => []];
        }

        $righe = 0;
        $griglia->righe(static function () use (&$righe): void {
            $righe++;
        });

        if ($righe === 0) {
            return [
                'ok' => false,
                'motivo' => 'Nessun testo estraibile: il PDF sembra fatto di immagini, e senza '
                    . 'riconoscimento ottico non c\'è niente da leggere.',
                'pagine' => $griglia->pagine(),
                'intestazione' => [],
            ];
        }

        if ($griglia->colonne() < 2) {
            return [
                'ok' => false,
                'motivo' => 'Non ho riconosciuto nessuna colonna: il testo non è allineato in modo '
                    . 'tabellare, oppure le colonne sono troppo vicine.',
                'pagine' => $griglia->pagine(),
                'intestazione' => [],
            ];
        }

        return [
            'ok' => true,
            'motivo' => null,
            'pagine' => $griglia->pagine(),
            'intestazione' => ['colonne' => $griglia->colonne(), 'righe' => $righe],
        ];
    }

    public function converti(string $percorsoIngresso, string $percorsoUscita, array $regole, ?callable $progresso = null): array
    {
        $avvisa = static function (string $passo, int $a, int $b) use ($progresso): void {
            if ($progresso !== null) {
                $progresso($passo, $a, $b);
            }
        };

        $griglia = new LettoreGriglia($percorsoIngresso, $this->corridoio($regole));
        $griglia->analizza(static function (int $pagina, int $totale) use ($avvisa): void {
            $avvisa('lettura', $pagina, $totale);
        });

        $saltaRipetute = !isset($regole['pdf_salta_ripetute']) || (bool) $regole['pdf_salta_ripetute'];
        $daPrimaRiga   = !empty($regole['pdf_intestazioni']);

        ['nomi' => $intestazioni, 'salta' => $saltaPrima] =
            $this->intestazioni($griglia, $daPrimaRiga, $saltaRipetute);
        $mappa   = $this->mappatura($regole, $intestazioni);
        $formato = ($regole['formato'] ?? 'xlsx') === 'csv' ? 'csv' : 'xlsx';

        $indicePerNome = array_flip($intestazioni);
        $anteprima     = [];
        $lette         = 0;
        $scritte       = 0;

        if ($formato === 'csv') {
            $f = fopen($percorsoUscita, 'w');
            if ($f === false) {
                throw new \RuntimeException("Non riesco a scrivere {$percorsoUscita}");
            }
            fwrite($f, "\u{FEFF}");
            fputcsv($f, array_column($mappa, 'nome'), ';', '"', '\\');
        } else {
            $foglio = new FoglioXlsx();
            $foglio->apri($percorsoUscita, array_map(
                static fn(array $c): array => [
                    'testata'   => $c['nome'],
                    'tipo'      => $c['tipo'],
                    'larghezza' => max(10, min(40, mb_strlen($c['nome']) + 4)),
                ],
                $mappa
            ));
        }

        $griglia->righe(
            function (int $pagina, array $celle) use (
                &$lette, &$scritte, &$anteprima, &$saltaPrima,
                $mappa, $indicePerNome, $intestazioni, $formato, &$f, &$foglio, $avvisa
            ): void {
                $lette++;
                if ($saltaPrima) {
                    $saltaPrima = false;   // era la riga delle intestazioni
                    return;
                }

                $uscita = [];
                foreach ($mappa as $colonna) {
                    $uscita[] = match ($colonna['da']) {
                        ConversioneTabelle::FISSO => $colonna['valore'],
                        ConversioneTabelle::VUOTO => '',
                        default => $celle[$indicePerNome[$colonna['da']] ?? -1] ?? '',
                    };
                }

                if (trim(implode('', $uscita)) === '') {
                    return;
                }

                if ($formato === 'csv') {
                    fputcsv($f, $uscita, ';', '"', '\\');
                } else {
                    $foglio->riga($uscita);
                }
                if (count($anteprima) < 5) {
                    $anteprima[] = $uscita;
                }
                $scritte++;

                if ($scritte % 500 === 0) {
                    $avvisa('scrittura', $scritte, 0);
                }
            },
            $saltaRipetute
        );

        if ($formato === 'csv') {
            fclose($f);
        } else {
            $foglio->chiudi();
        }
        $avvisa('scrittura', $scritte, $scritte);

        return [
            'righe_lette'   => $lette,
            'righe_scritte' => $scritte,
            'pagine'        => $griglia->pagine(),
            'intestazione'  => ['colonne' => count($mappa)],
            'anomalie'      => $this->anomalie($griglia, $scritte),
            'anteprima'     => ['testate' => array_column($mappa, 'nome'), 'righe' => $anteprima],
        ];
    }

    public function analizza(string $percorsoIngresso, array $regole = []): array
    {
        $griglia = new LettoreGriglia($percorsoIngresso, $this->corridoio($regole));
        $griglia->analizza();

        $saltaRipetute = !isset($regole['pdf_salta_ripetute']) || (bool) $regole['pdf_salta_ripetute'];
        $daPrimaRiga   = !empty($regole['pdf_intestazioni']);

        $prime = [];
        $righe = 0;
        $griglia->righe(
            static function (int $pagina, array $celle) use (&$prime, &$righe): void {
                $righe++;
                if (count($prime) < self::RIGHE_ANTEPRIMA) {
                    $prime[] = $celle;
                }
            },
            $saltaRipetute
        );

        ['nomi' => $intestazioni, 'salta' => $salta] =
            $this->intestazioni($griglia, $daPrimaRiga, $saltaRipetute, $prime);
        $assaggio = array_map(
            fn(array $celle): array => $this->inRiga($celle, count($intestazioni)),
            array_slice($prime, $salta ? 1 : 0, 3)
        );

        return [
            'sommario' => sprintf(
                '%s pagine · %s colonne riconosciute · %s righe',
                number_format($griglia->pagine(), 0, ',', '.'),
                number_format($griglia->colonne(), 0, ',', '.'),
                number_format($righe, 0, ',', '.')
            ),
            'pagine'       => $righe,
            'intestazione' => ['colonne' => $intestazioni],
            'righe_lette'  => $righe,
            'prenotazioni' => $righe,
            'anomalie'     => count($this->anomalie($griglia, $righe)),
            'anteprima'    => [],
            'colonne'      => $intestazioni,
            'assaggio'     => $assaggio,
        ];
    }

    /**
     * I nomi delle colonne, e se la prima riga di dati vada consumata per darli.
     *
     * Quando l'utente dice che le colonne hanno un'intestazione ci sono due
     * casi, e distinguerli conta:
     *
     * - la stampa ripete i nomi di colonna a ogni pagina — il caso normale. La
     *   riga è già stata tolta dai dati come testata, quindi i nomi si prendono
     *   da lì e **nessuna riga di dati va persa**;
     * - la tabella ha l'intestazione una volta sola, in cima. Allora è davvero
     *   la prima riga letta, e va consumata.
     *
     * Senza intestazione, nomi di comodo: non si prova a indovinare quale riga
     * sia la testata, perché su una stampa di gestionale spesso non c'è, e
     * indovinare male vorrebbe dire perdere una riga di dati senza dirlo.
     *
     * @param list<array<int,string>> $prime
     * @return array{nomi:list<string>,salta:bool}
     */
    private function intestazioni(
        LettoreGriglia $griglia,
        bool $daPrimaRiga,
        bool $saltaRipetute,
        array $prime = []
    ): array {
        $quante = $griglia->colonne();

        if ($daPrimaRiga) {
            $ripetuta = $saltaRipetute ? $griglia->intestazioneRipetuta() : null;
            if ($ripetuta !== null) {
                return ['nomi' => $this->nomiUnivoci($this->inRiga($ripetuta, $quante)), 'salta' => false];
            }

            if ($prime === []) {
                $griglia->righe(static function (int $pagina, array $celle) use (&$prime): void {
                    if ($prime === []) {
                        $prime[] = $celle;
                    }
                }, $saltaRipetute);
            }
            if ($prime !== []) {
                return ['nomi' => $this->nomiUnivoci($this->inRiga($prime[0], $quante)), 'salta' => true];
            }
        }

        $nomi = [];
        for ($i = 0; $i < $quante; $i++) {
            $nomi[] = 'Colonna ' . ($i + 1);
        }

        return ['nomi' => $nomi, 'salta' => false];
    }

    /**
     * Una riga sparsa diventa una riga piena: le colonne senza testo restano
     * vuote invece di sparire, altrimenti i valori slitterebbero.
     *
     * @param array<int,string> $celle
     * @return list<string>
     */
    private function inRiga(array $celle, int $quante): array
    {
        $riga = [];
        for ($i = 0; $i < $quante; $i++) {
            $riga[] = $celle[$i] ?? '';
        }

        return $riga;
    }

    /** @return list<array<string,mixed>> */
    private function anomalie(LettoreGriglia $griglia, int $righe): array
    {
        $anomalie = [];

        if ($griglia->colonne() < 3) {
            $anomalie[] = [
                'chiave'          => 'colonne',
                'cliente'         => '',
                'motivo'          => 'Riconosciute solo ' . $griglia->colonne() . ' colonne: se il PDF ne ha '
                    . 'di più, prova a stringere la larghezza minima del corridoio nello step 2.',
                'colonna'         => 'Colonne',
                'valore_proposto' => '',
                'gravita'         => 'correggi',
            ];
        }

        if ($griglia->quanteRipetute() > 0) {
            $anomalie[] = [
                'chiave'          => 'testate',
                'cliente'         => '',
                'motivo'          => $griglia->quanteRipetute() . ' righe si ripetevano su quasi tutte le pagine '
                    . '(testate e piè di pagina): sono state tolte dai dati.',
                'colonna'         => 'Righe',
                'valore_proposto' => '',
                'gravita'         => 'informativa',
            ];
        }

        $anomalie[] = [
            'chiave'          => 'metodo',
            'cliente'         => '',
            'motivo'          => 'Le colonne sono state dedotte dall\'allineamento del testo: un PDF non le '
                . 'dichiara. Controlla l\'anteprima prima di fidarti del risultato.',
            'colonna'         => 'Colonne',
            'valore_proposto' => '',
            'gravita'         => 'informativa',
        ];

        return $anomalie;
    }

    /** @param array<string,mixed> $regole */
    private function corridoio(array $regole): float
    {
        $scelto = (float) ($regole['pdf_corridoio'] ?? 6);

        return $scelto >= 2 && $scelto <= 40 ? $scelto : 6.0;
    }

    /**
     * @param array<string,mixed> $regole
     * @param list<string>        $intestazioni
     * @return list<array{nome:string,da:string,valore:string,tipo:string}>
     */
    private function mappatura(array $regole, array $intestazioni): array
    {
        $grezza = $regole['colonne'] ?? [];
        if (!is_array($grezza) || $grezza === []) {
            return array_map(
                static fn(string $nome): array => ['nome' => $nome, 'da' => $nome, 'valore' => '', 'tipo' => 'testo'],
                $intestazioni
            );
        }

        $mappa = [];
        foreach ($grezza as $colonna) {
            if (!is_array($colonna) || trim((string) ($colonna['nome'] ?? '')) === '') {
                continue;
            }
            $mappa[] = [
                'nome'   => mb_substr(trim((string) $colonna['nome']), 0, 120),
                'da'     => (string) ($colonna['da'] ?? ConversioneTabelle::VUOTO),
                'valore' => mb_substr((string) ($colonna['valore'] ?? ''), 0, 200),
                'tipo'   => in_array($colonna['tipo'] ?? '', ['testo', 'numero', 'intero', 'data', 'valuta'], true)
                    ? (string) $colonna['tipo']
                    : 'testo',
            ];
        }

        return $mappa !== [] ? $mappa : $this->mappatura([], $intestazioni);
    }

    /**
     * @param list<string> $riga
     * @return list<string>
     */
    private function nomiUnivoci(array $riga): array
    {
        $nomi  = [];
        $visti = [];
        foreach ($riga as $i => $grezzo) {
            $nome = trim($grezzo);
            if ($nome === '') {
                $nome = 'Colonna ' . ($i + 1);
            }
            if (isset($visti[$nome])) {
                $visti[$nome]++;
                $nome .= ' (' . $visti[$nome] . ')';
            } else {
                $visti[$nome] = 1;
            }
            $nomi[] = $nome;
        }

        return $nomi;
    }
}
