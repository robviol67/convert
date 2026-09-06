<?php
declare(strict_types=1);

namespace Vblite\Convert\Conversioni\Tabelle;

use Vblite\Convert\Conversioni\Conversione;
use Vblite\Convert\Supporto\FoglioXlsx;

/**
 * Tabella → tabella, con mappatura delle colonne.
 *
 * È la generalizzazione di Octo → Scidoo: là le regole erano scritte nel
 * codice perché il tracciato era uno solo, qui le sceglie l'utente. Ogni
 * colonna in uscita può venire da una colonna in ingresso, da un valore fisso
 * uguale per tutte le righe, o restare vuota.
 *
 * Il valore fisso è la parte che i convertitori non hanno quasi mai e che
 * serve sempre: aggiungere a tutte le righe un codice cliente, un tag di
 * campagna, la data di importazione.
 */
final class ConversioneTabelle implements Conversione
{
    /** Le righe non stanno in memoria, ma un file enorme resta poco maneggevole. */
    public const MAX_BYTE = 15 * 1024 * 1024;

    /** Sorgenti speciali di una colonna in uscita. */
    public const FISSO = '__fisso';
    public const VUOTO = '__vuoto';

    public static function chiave(): string
    {
        return 'tabelle';
    }

    public function manifest(): array
    {
        return require __DIR__ . '/manifest.php';
    }

    public function verifica(string $percorsoIngresso): array
    {
        if (!LettoreTabella::riconosciuto($percorsoIngresso)) {
            return [
                'ok' => false,
                'motivo' => 'Serve un CSV o un XLSX.',
                'pagine' => 0,
                'intestazione' => [],
            ];
        }

        $byte = (int) @filesize($percorsoIngresso);
        if ($byte > self::MAX_BYTE) {
            return [
                'ok' => false,
                'motivo' => 'Il file pesa ' . round($byte / 1048576) . ' MB: il massimo è '
                    . (self::MAX_BYTE / 1048576) . ' MB.',
                'pagine' => 0,
                'intestazione' => [],
            ];
        }

        try {
            $lettore      = new LettoreTabella($percorsoIngresso);
            $intestazioni = $lettore->intestazioni();
        } catch (\Throwable $e) {
            return ['ok' => false, 'motivo' => $e->getMessage(), 'pagine' => 0, 'intestazione' => []];
        }

        if ($intestazioni === []) {
            return [
                'ok' => false,
                'motivo' => 'La prima riga del file è vuota: servono le intestazioni delle colonne.',
                'pagine' => 0,
                'intestazione' => [],
            ];
        }

        return [
            'ok' => true,
            'motivo' => null,
            'pagine' => count($intestazioni),
            'intestazione' => ['colonne' => $intestazioni],
        ];
    }

    public function converti(string $percorsoIngresso, string $percorsoUscita, array $regole, ?callable $progresso = null): array
    {
        $avvisa = static function (string $passo, int $a, int $b) use ($progresso): void {
            if ($progresso !== null) {
                $progresso($passo, $a, $b);
            }
        };

        $lettore      = new LettoreTabella($percorsoIngresso);
        $intestazioni = $lettore->intestazioni();
        $mappa        = $this->mappatura($regole, $intestazioni);
        $formato      = ($regole['formato'] ?? 'xlsx') === 'csv' ? 'csv' : 'xlsx';

        $avvisa('lettura', 0, 1);

        $indicePerNome = array_flip($intestazioni);
        $saltaVuote    = !empty($regole['salta_righe_vuote']);

        $anomalie  = [];
        $anteprima = [];
        $lette     = 0;
        $scritte   = 0;

        $scrivi = function (array $riga) use (&$anteprima): void {
            if (count($anteprima) < 5) {
                $anteprima[] = $riga;
            }
        };

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

        foreach ($lettore->dati() as $riga) {
            $lette++;

            $uscita = [];
            foreach ($mappa as $colonna) {
                $uscita[] = match ($colonna['da']) {
                    self::FISSO => $colonna['valore'],
                    self::VUOTO => '',
                    default     => $riga[$indicePerNome[$colonna['da']] ?? -1] ?? '',
                };
            }

            if ($saltaVuote && trim(implode('', $uscita)) === '') {
                continue;
            }

            if ($formato === 'csv') {
                fputcsv($f, $uscita, ';', '"', '\\');
            } else {
                $foglio->riga($uscita);
            }
            $scrivi($uscita);
            $scritte++;

            if ($scritte % 500 === 0) {
                $avvisa('scrittura', $scritte, 0);
            }
        }

        if ($formato === 'csv') {
            fclose($f);
        } else {
            $foglio->chiudi();
        }
        $avvisa('scrittura', $scritte, $scritte);

        // Una colonna che punta a un nome che nel file non c'è più: si dice.
        foreach ($mappa as $colonna) {
            if (!in_array($colonna['da'], [self::FISSO, self::VUOTO], true)
                && !isset($indicePerNome[$colonna['da']])) {
                $anomalie[] = [
                    'chiave'          => $colonna['nome'],
                    'cliente'         => '',
                    'motivo'          => 'La colonna d\'origine «' . $colonna['da'] . '» non esiste in questo file: la colonna esce vuota.',
                    'colonna'         => $colonna['nome'],
                    'valore_proposto' => '',
                    'gravita'         => 'correggi',
                ];
            }
        }

        return [
            'righe_lette'   => $lette,
            'righe_scritte' => $scritte,
            'pagine'        => count($mappa),
            'intestazione'  => ['colonne' => count($mappa)],
            'anomalie'      => $anomalie,
            'anteprima'     => [
                'testate' => array_column($mappa, 'nome'),
                'righe'   => $anteprima,
            ],
        ];
    }

    public function analizza(string $percorsoIngresso, array $regole = []): array
    {
        $lettore      = new LettoreTabella($percorsoIngresso);
        $intestazioni = $lettore->intestazioni();

        $righe = 0;
        foreach ($lettore->dati() as $ignorata) {
            $righe++;
        }

        return [
            'sommario' => sprintf(
                '%s · %s colonne · %s righe',
                strtoupper(pathinfo($percorsoIngresso, PATHINFO_EXTENSION)),
                number_format(count($intestazioni), 0, ',', '.'),
                number_format($righe, 0, ',', '.')
            ),
            'pagine'       => $righe,
            'intestazione' => ['colonne' => $intestazioni],
            'righe_lette'  => $righe,
            'prenotazioni' => $righe,
            'anomalie'     => 0,
            'anteprima'    => [],
            // Quello che serve alla schermata di mappatura.
            'colonne'      => $intestazioni,
            'assaggio'     => $lettore->assaggio(3),
        ];
    }

    /**
     * La mappatura scelta, ripulita.
     *
     * Se non c'è — primo giro, o preset di un altro file — si parte
     * dall'identità: ogni colonna in ingresso diventa una colonna in uscita
     * con lo stesso nome. È il punto di partenza che quasi sempre va già bene.
     *
     * @param array<string,mixed> $regole
     * @param list<string>        $intestazioni
     * @return list<array{nome:string,da:string,valore:string,tipo:string}>
     */
    private function mappatura(array $regole, array $intestazioni): array
    {
        $grezza = $regole['colonne'] ?? [];
        if (!is_array($grezza) || $grezza === []) {
            return array_map(
                static fn(string $nome): array => [
                    'nome' => $nome, 'da' => $nome, 'valore' => '', 'tipo' => 'testo',
                ],
                $intestazioni
            );
        }

        $mappa = [];
        foreach ($grezza as $colonna) {
            if (!is_array($colonna)) {
                continue;
            }
            $nome = trim((string) ($colonna['nome'] ?? ''));
            if ($nome === '') {
                continue;
            }
            $mappa[] = [
                'nome'   => mb_substr($nome, 0, 120),
                'da'     => (string) ($colonna['da'] ?? self::VUOTO),
                'valore' => mb_substr((string) ($colonna['valore'] ?? ''), 0, 200),
                'tipo'   => in_array($colonna['tipo'] ?? '', ['testo', 'numero', 'intero', 'data', 'valuta'], true)
                    ? (string) $colonna['tipo']
                    : 'testo',
            ];
        }

        return $mappa !== [] ? $mappa : $this->mappatura([], $intestazioni);
    }
}
