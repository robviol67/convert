<?php
declare(strict_types=1);

namespace Vblite\Convert\Conversioni\Documenti;

use Vblite\Convert\Conversioni\Conversione;

/**
 * Documenti ↔ Markdown.
 *
 * Legge .docx, .pdf, .rtf, .txt e .md e riscrive in uno qualunque di quei
 * formati. Tutto passa dal modello intermedio: i formati costano N + M classi
 * invece di N × M conversioni, e aggiungerne uno non tocca gli altri.
 */
final class ConversioneDocumenti implements Conversione
{
    /** Oltre questa dimensione il documento non sta nella memoria dell'hosting. */
    public const MAX_BYTE = 15 * 1024 * 1024;

    /** Quanti blocchi mostrare nell'anteprima del risultato. */
    private const BLOCCHI_ANTEPRIMA = 40;

    public static function chiave(): string
    {
        return 'documenti_md';
    }

    public function manifest(): array
    {
        return require __DIR__ . '/manifest.php';
    }

    public function verifica(string $percorsoIngresso): array
    {
        $estensione = strtolower(pathinfo($percorsoIngresso, PATHINFO_EXTENSION));

        try {
            Formati::lettore($percorsoIngresso);
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'motivo' => $e->getMessage(), 'pagine' => 0, 'intestazione' => []];
        }

        $byte = (int) @filesize($percorsoIngresso);
        if ($byte > self::MAX_BYTE) {
            return [
                'ok' => false,
                'motivo' => 'Il documento pesa ' . round($byte / 1048576) . ' MB: il massimo è '
                    . (self::MAX_BYTE / 1048576) . ' MB.',
                'pagine' => 0,
                'intestazione' => [],
            ];
        }

        // Una lettura di prova: meglio scoprire adesso che il file è rotto.
        try {
            $documento = new Documento();
            $quanti    = 0;
            $documento->consuma(static function () use (&$quanti): void {
                $quanti++;
            });
            Formati::lettore($percorsoIngresso)->leggi($percorsoIngresso, sys_get_temp_dir() . '/vb-prova', $documento);
        } catch (\Throwable $e) {
            return ['ok' => false, 'motivo' => $e->getMessage(), 'pagine' => 0, 'intestazione' => []];
        }

        if ($quanti === 0) {
            return [
                'ok' => false,
                'motivo' => 'Il documento non contiene testo leggibile.',
                'pagine' => 0,
                'intestazione' => [],
            ];
        }

        return [
            'ok' => true,
            'motivo' => null,
            'pagine' => $quanti,
            'intestazione' => ['formato' => Formati::formatiInIngresso()[$estensione] ?? $estensione],
        ];
    }

    public function converti(string $percorsoIngresso, string $percorsoUscita, array $regole, ?callable $progresso = null): array
    {
        $formato = (string) ($regole['formato'] ?? 'md');
        $lettore = Formati::lettore($percorsoIngresso);

        $avvisa = static function (string $passo, int $a, int $b) use ($progresso): void {
            if ($progresso !== null) {
                $progresso($passo, $a, $b);
            }
        };

        $avvisa('lettura', 0, 1);

        // Le immagini stanno accanto al file prodotto, in media/.
        $cartellaMedia = dirname($percorsoUscita) . '/' . pathinfo($percorsoUscita, PATHINFO_FILENAME) . '-media';

        $documento = new Documento();
        $scrittore = Formati::scrittore($formato);

        // Il documento si scrive su un file d'appoggio: se poi porta immagini
        // va messo dentro uno zip, e comprimere sopra il file che si sta
        // leggendo lo distruggerebbe.
        $percorsoDoc = (string) tempnam(sys_get_temp_dir(), 'doc') . '.' . $formato;

        $scrittore->apri($percorsoDoc, $documento);

        // Lettore e scrittore lavorano in catena: la memoria non dipende dalla
        // lunghezza del documento, che è l'unico modo di stare nel tetto.
        $blocchi  = 0;
        $primi    = [];
        $documento->consuma(static function ($blocco) use ($scrittore, &$blocchi, &$primi, $avvisa): void {
            $scrittore->blocco($blocco);

            // I primi blocchi si tengono da parte per l'anteprima: sono pochi,
            // e mostrarli è l'unico modo di far vedere il risultato di un
            // formato binario senza riaprirlo.
            if (count($primi) < self::BLOCCHI_ANTEPRIMA) {
                $primi[] = $blocco;
            }

            $blocchi++;
            if ($blocchi % 200 === 0) {
                $avvisa('scrittura', $blocchi, 0);
            }
        });

        $lettore->leggi($percorsoIngresso, $cartellaMedia, $documento);
        $resi = $scrittore->chiudi();

        $avvisa('scrittura', $resi, $resi);

        // Se ci sono immagini e il formato non le incorpora, il risultato è una
        // cartella: si consegna come zip, altrimenti l'utente scarica un .md
        // con dei rimandi a file che non ha.
        $immagini = $documento->immagini();
        $conZip   = $immagini !== [] && in_array($formato, ['md', 'txt'], true);

        if ($conZip) {
            // Dentro lo zip il documento tiene il nome di quello caricato: chi
            // lo apre deve ritrovare il suo file, non un riferimento interno.
            $nomeDentro = pathinfo((string) ($regole['nome_originale'] ?? 'documento'), PATHINFO_FILENAME)
                . '.' . $formato;
            $this->impacchetta($percorsoUscita, $percorsoDoc, $nomeDentro, $immagini);
        } else {
            @unlink($percorsoUscita);
            rename($percorsoDoc, $percorsoUscita);
        }
        $this->pulisci($cartellaMedia);

        return [
            'estensione'    => $conZip ? 'zip' : $formato,
            'righe_lette'   => $documento->quanti(),
            'righe_scritte' => $resi,
            'pagine'        => $documento->quanti(),
            'intestazione'  => ['parole' => $documento->parole()],
            'anomalie'      => $this->anomalie($documento, $formato),
            'anteprima'     => $this->anteprima($documento, $formato, $conZip)
                + [
                    'html'    => AnteprimaHtml::rendi($primi),
                    'parziale' => $documento->quanti() > count($primi),
                ],
        ];
    }

    /**
     * Analisi per lo step 2: cosa c'è dentro, prima di scegliere il formato.
     *
     * @param array<string,mixed> $regole
     * @return array<string,mixed>
     */
    public function analizza(string $percorsoIngresso, array $regole = []): array
    {
        $documento = new Documento();
        Formati::lettore($percorsoIngresso)
            ->leggi($percorsoIngresso, sys_get_temp_dir() . '/vb-analisi', $documento);

        $pezzi = [Formati::formatiInIngresso()[strtolower(pathinfo($percorsoIngresso, PATHINFO_EXTENSION))] ?? 'documento'];
        $pezzi[] = number_format($documento->quanti(), 0, ',', '.') . ' blocchi';
        $pezzi[] = number_format($documento->parole(), 0, ',', '.') . ' parole';
        if ($documento->immagini() !== []) {
            $pezzi[] = count($documento->immagini()) . ' immagini';
        }

        return [
            'sommario'     => implode(' · ', $pezzi),
            'pagine'       => $documento->quanti(),
            'intestazione' => [
                'formato' => Formati::formatiInIngresso()[strtolower(pathinfo($percorsoIngresso, PATHINFO_EXTENSION))] ?? '',
                'parole'  => $documento->parole(),
            ],
            'righe_lette'  => $documento->quanti(),
            'prenotazioni' => $documento->quanti(),
            'anomalie'     => count($documento->perdite()),
            'riepilogo'    => $documento->riepilogo(),
            'immagini'     => count($documento->immagini()),
            'anteprima'    => [],
        ];
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
                // Sono avvisi su cosa non è passato intero, non valori da correggere.
                'gravita'         => 'informativa',
            ];
        }

        return $anomalie;
    }

    /** @return array{testate:list<string>,righe:list<list<string>>} */
    private function anteprima(Documento $documento, string $formato, bool $conZip): array
    {
        $righe = [];
        foreach ($documento->riepilogo() as $tipo => $quanti) {
            $righe[] = [$this->nomeTipo($tipo), (string) $quanti];
        }
        $righe[] = ['Parole', number_format($documento->parole(), 0, ',', '.')];
        if ($documento->immagini() !== []) {
            $righe[] = ['Immagini', (string) count($documento->immagini()) . ($conZip ? ' (nello zip)' : '')];
        }

        return ['testate' => ['Contenuto', 'Quanti'], 'righe' => array_slice($righe, 0, 8)];
    }

    private function nomeTipo(string $tipo): string
    {
        return match ($tipo) {
            Blocco::TITOLO    => 'Titoli',
            Blocco::PARAGRAFO => 'Paragrafi',
            Blocco::ELENCO    => 'Voci di elenco',
            Blocco::CITAZIONE => 'Citazioni',
            Blocco::CODICE    => 'Blocchi di codice',
            Blocco::TABELLA   => 'Tabelle',
            Blocco::IMMAGINE  => 'Immagini',
            Blocco::RIGA      => 'Righe orizzontali',
            default           => ucfirst($tipo),
        };
    }

    /** @param array<string,string> $immagini */
    private function impacchetta(string $zipDestinazione, string $documento, string $nomeDentro, array $immagini): void
    {
        $zip = new \ZipArchive();
        @unlink($zipDestinazione);
        if ($zip->open($zipDestinazione, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return;
        }

        $zip->addFile($documento, $nomeDentro);
        foreach ($immagini as $nome => $percorso) {
            if (is_file($percorso)) {
                $zip->addFile($percorso, $nome);
            }
        }
        $zip->close();
        @unlink($documento);
    }

    private function pulisci(string $cartella): void
    {
        if (!is_dir($cartella)) {
            return;
        }
        foreach (glob($cartella . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($cartella);
    }
}
