<?php
declare(strict_types=1);

namespace Vblite\Convert\Conversioni\Trascrizioni;

use Vblite\Convert\Conversioni\Conversione;
use Vblite\Convert\Conversioni\Documenti\AnteprimaHtml;
use Vblite\Convert\Conversioni\Documenti\Blocco;
use Vblite\Convert\Conversioni\Documenti\Documento;
use Vblite\Convert\Conversioni\Documenti\Formati;

/**
 * Sottotitoli e trascrizioni → documento.
 *
 * Il pezzo che manca a chi ha una trascrizione non è procurarsela: YouTube la
 * mostra a chi guarda, e Studio la consegna in <code>.srt</code> a chi possiede
 * il video. Il pezzo che manca è quello dopo — trasformare un elenco di battute
 * spezzate in un testo che si legga.
 *
 * Si appoggia al modello e agli scrittori dei documenti: da qui esce un
 * Markdown, un Word o un PDF esattamente come dalle altre conversioni. La
 * differenza sta tutta nel lettore, che di ricucire le battute se ne intende.
 */
final class ConversioneTrascrizione implements Conversione
{
    /** Una trascrizione è testo: se pesa più di così, non è una trascrizione. */
    public const MAX_BYTE = 5 * 1024 * 1024;

    private const BLOCCHI_ANTEPRIMA = 40;

    public static function chiave(): string
    {
        return 'trascrizioni';
    }

    public function manifest(): array
    {
        return require __DIR__ . '/manifest.php';
    }

    public function verifica(string $percorsoIngresso): array
    {
        $estensione = strtolower(pathinfo($percorsoIngresso, PATHINFO_EXTENSION));
        if (!in_array($estensione, LettoreTrascrizione::estensioni(), true)) {
            return [
                'ok' => false,
                'motivo' => 'Serve un file di sottotitoli (.srt, .vtt, .sbv) o il testo della '
                    . 'trascrizione in un .txt.',
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
            $documento = new Documento();
            $quanti    = 0;
            $documento->consuma(static function () use (&$quanti): void {
                $quanti++;
            });
            (new LettoreTrascrizione())->leggi($percorsoIngresso, '', $documento);
        } catch (\Throwable $e) {
            return ['ok' => false, 'motivo' => $e->getMessage(), 'pagine' => 0, 'intestazione' => []];
        }

        if ($quanti === 0) {
            return [
                'ok' => false,
                'motivo' => 'Non ho trovato niente da leggere: il file è vuoto, oppure non contiene '
                    . 'né battute di sottotitoli né testo.',
                'pagine' => 0,
                'intestazione' => [],
            ];
        }

        return [
            'ok' => true,
            'motivo' => null,
            'pagine' => $quanti,
            'intestazione' => ['formato' => strtoupper($estensione)],
        ];
    }

    public function converti(string $percorsoIngresso, string $percorsoUscita, array $regole, ?callable $progresso = null): array
    {
        $formato = (string) ($regole['formato'] ?? 'md');
        $avvisa  = static function (string $passo, int $a, int $b) use ($progresso): void {
            if ($progresso !== null) {
                $progresso($passo, $a, $b);
            }
        };

        $avvisa('lettura', 0, 1);

        $lettore = new LettoreTrascrizione();
        $lettore->configura($regole);

        $documento = new Documento();
        $scrittore = Formati::scrittore($formato, $regole);
        $scrittore->apri($percorsoUscita, $documento);

        // Lettore e scrittore in catena, come nelle altre conversioni: la
        // memoria non dipende da quanto è lunga la trascrizione.
        $blocchi = 0;
        $primi   = [];
        $documento->consuma(static function (Blocco $blocco) use ($scrittore, &$blocchi, &$primi, $avvisa): void {
            $scrittore->blocco($blocco);
            if (count($primi) < self::BLOCCHI_ANTEPRIMA) {
                $primi[] = $blocco;
            }
            $blocchi++;
            if ($blocchi % 200 === 0) {
                $avvisa('scrittura', $blocchi, 0);
            }
        });

        $lettore->leggi($percorsoIngresso, '', $documento);
        $resi = $scrittore->chiudi();
        $avvisa('scrittura', $resi, $resi);

        return [
            'estensione'    => $formato,
            'righe_lette'   => $documento->quanti(),
            'righe_scritte' => $resi,
            'pagine'        => $documento->quanti(),
            'intestazione'  => ['parole' => $documento->parole()],
            'anomalie'      => $this->anomalie($documento, $formato),
            'anteprima'     => [
                'testate'  => ['Contenuto', 'Quanti'],
                'righe'    => [
                    ['Paragrafi', number_format($documento->quanti(), 0, ',', '.')],
                    ['Parole',    number_format($documento->parole(), 0, ',', '.')],
                ],
                'html'     => AnteprimaHtml::rendi($primi),
                'parziale' => $documento->quanti() > count($primi),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $regole
     * @return array<string,mixed>
     */
    public function analizza(string $percorsoIngresso, array $regole = []): array
    {
        $lettore = new LettoreTrascrizione();
        $lettore->configura($regole);

        $documento = new Documento();
        $lettore->leggi($percorsoIngresso, '', $documento);

        $pezzi = [strtoupper(pathinfo($percorsoIngresso, PATHINFO_EXTENSION))];
        $pezzi[] = number_format($documento->quanti(), 0, ',', '.') . ' paragrafi';
        $pezzi[] = number_format($documento->parole(), 0, ',', '.') . ' parole';
        $pezzi[] = self::durata($documento->parole());

        return [
            'sommario'     => implode(' · ', $pezzi),
            'pagine'       => $documento->quanti(),
            'intestazione' => ['formato' => strtoupper(pathinfo($percorsoIngresso, PATHINFO_EXTENSION)),
                               'parole'  => $documento->parole()],
            'righe_lette'  => $documento->quanti(),
            'prenotazioni' => $documento->quanti(),
            'anomalie'     => count($documento->perdite()),
            'riepilogo'    => $documento->riepilogo(),
            'anteprima'    => [],
        ];
    }

    /** Quanto ci vuole a leggerlo: è il dato che serve a chi ha in mano un testo. */
    private static function durata(int $parole): string
    {
        $minuti = (int) max(1, round($parole / 200));

        return $minuti < 60
            ? $minuti . ' min di lettura'
            : intdiv($minuti, 60) . ' h ' . ($minuti % 60) . ' min di lettura';
    }

    /** @return list<array<string,mixed>> */
    private function anomalie(Documento $documento, string $formato): array
    {
        $anomalie = [];
        foreach ($documento->perdite() as $perdita) {
            $anomalie[] = [
                'chiave'          => '—',
                'cliente'         => '',
                'motivo'          => $perdita['motivo'] . ($perdita['dettaglio'] !== '' ? ' — ' . $perdita['dettaglio'] : ''),
                'colonna'         => strtoupper($formato),
                'valore_proposto' => '',
                'gravita'         => 'informativa',
            ];
        }

        $anomalie[] = [
            'chiave'          => '—',
            'cliente'         => '',
            'motivo'          => 'I paragrafi non stanno nel file di partenza: sono stati ricostruiti '
                . 'dai punti fermi e dalla lunghezza. Dove i sottotitoli sono automatici la punteggiatura '
                . 'non c\'è, e il taglio è solo di lunghezza.',
            'colonna'         => strtoupper($formato),
            'valore_proposto' => '',
            'gravita'         => 'informativa',
        ];

        return $anomalie;
    }
}
