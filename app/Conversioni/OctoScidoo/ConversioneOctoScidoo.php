<?php
declare(strict_types=1);

namespace Vblite\Convert\Conversioni\OctoScidoo;

use Vblite\Convert\Conversioni\Conversione;
use Vblite\Convert\Conversioni\ConversioneAPassi;
use Vblite\Convert\Supporto\SpezzaPdf;

/**
 * Stampe Octorate → Scidoo «File Import Prenotazioni».
 *
 * Lavora a tappe: il PDF si legge a pezzi, le righe estratte vanno su disco, e
 * alla fine si raggruppano una prenotazione alla volta. È l'unico modo di
 * convertire una stampa di duemila pagine su un hosting che uccide i processi
 * sopra i venti megabyte. Anche converti() passa da qui, in un giro solo: il
 * codice è uno, e i test provano quello che gira sul server.
 */
final class ConversioneOctoScidoo implements Conversione, ConversioneAPassi
{
    /**
     * Pagine lette in una tappa.
     *
     * Misurato sulla stampa da 1.849 pagine: vedi tests/prova.php, che tiene
     * il picco di una tappa sotto la soglia.
     */
    public const PAGINE_PER_TAPPA = 60;

    /**
     * Oltre questo numero di pagine lo step 2 non legge tutto il file: legge
     * un campione, e i conteggi arrivano con la conversione.
     */
    public const PAGINE_ANALISI_COMPLETA = 300;

    /** Quante pagine legge il campione dello step 2. */
    private const PAGINE_CAMPIONE = 30;

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
        $cartella = self::cartellaTemporanea();
        try {
            $stato = $this->prepara($percorsoIngresso, $cartella, $regole);
            while (empty($stato['fatto'])) {
                $stato = $this->avanza($percorsoIngresso, $cartella, $stato, $regole, $percorsoUscita);
                if ($progresso !== null) {
                    $punto = $this->avanzamento($stato);
                    $progresso($punto['passo'], $punto['corrente'], $punto['totale']);
                }
            }

            $risultato = $stato['risultato'];
            $risultato['anomalie'] = self::leggiAnomalie((string) $risultato['anomalie_file']);
            unset($risultato['anomalie_file']);

            return $risultato;
        } finally {
            self::pulisci($cartella);
        }
    }

    public function prepara(string $percorsoIngresso, string $cartellaLavoro, array $regole): array
    {
        $spezzabile = SpezzaPdf::apri($percorsoIngresso);
        $pagine     = $spezzabile?->pagine();
        $spezzabile?->chiudi();

        return [
            'fatto'        => false,
            'fase'         => 'lettura',
            'pagina'       => 1,                    // la prossima da leggere
            'pagine'       => $pagine ?? 0,         // 0 finché un PDF intero non è stato letto
            'a_pezzi'      => $pagine !== null,
            'tracciato'    => null,
            'intestazione' => [],
            'byte'         => 0,                    // l'archivio, alla fine dell'ultima tappa conclusa
            'tentativi'    => 0,
        ];
    }

    public function avanza(
        string $percorsoIngresso,
        string $cartellaLavoro,
        array $stato,
        array $regole,
        string $percorsoUscita,
    ): array {
        return match ($stato['fase']) {
            'lettura'   => $this->tappaLettura($percorsoIngresso, $cartellaLavoro, $stato, null),
            'scrittura' => $this->tappaScrittura($cartellaLavoro, $stato, $regole, $percorsoUscita),
            default     => $stato,
        };
    }

    public function avanzamento(array $stato): array
    {
        return match ($stato['fase']) {
            'lettura'   => [
                'passo'    => 'lettura',
                'corrente' => min((int) $stato['pagina'] - 1, (int) $stato['pagine']),
                'totale'   => (int) $stato['pagine'],
            ],
            // Raggruppare e scrivere stanno in una tappa sola: mentre aspetta
            // di cominciarla, il lavoro è al raggruppamento.
            'scrittura' => ['passo' => 'raggruppamento', 'corrente' => 0, 'totale' => 0],
            default     => ['passo' => 'scrittura', 'corrente' => 1, 'totale' => 1],
        };
    }

    /**
     * Una tappa di lettura: un pezzo di pagine, righe in coda all'archivio.
     *
     * @param array<string,mixed> $stato
     * @return array<string,mixed>
     */
    private function tappaLettura(string $percorsoIngresso, string $cartella, array $stato, ?int $finoAPagina): array
    {
        $archivio = new ArchivioRighe($cartella . '/righe.txt');
        // Una tappa uccisa a metà ha scritto righe che vanno rilette da capo.
        $archivio->tronca((int) $stato['byte']);

        $parser = new Parser();
        if ($stato['tracciato'] !== null) {
            $tracciato = Tracciato::perChiave((string) $stato['tracciato']);
            if ($tracciato !== null) {
                $parser->usa($tracciato);
            }
        }
        $aggiungi = static function (array $riga) use ($archivio): void {
            $archivio->aggiungi($riga);
        };

        try {
            if (!$stato['a_pezzi']) {
                // Un PDF che non si sa spezzare si legge intero, in una tappa.
                // Arriva qui solo se è piccolo: verifica() rifiuta gli altri.
                $esito = $parser->scorri($percorsoIngresso, 1, $aggiungi);
                $stato['pagine']       = $esito['pagine'];
                $stato['pagina']       = $esito['pagine'] + 1;
                $stato['intestazione'] = $esito['intestazione'];
            } else {
                // Se le tappe di prima sono morte a metà, pezzi più piccoli:
                // metà alla seconda prova, un quarto alla terza.
                $quante = max(5, intdiv(self::PAGINE_PER_TAPPA, 2 ** max(0, (int) $stato['tentativi'] - 1)));
                if ($finoAPagina !== null) {
                    $quante = min($quante, $finoAPagina - (int) $stato['pagina'] + 1);
                }

                $pezzo = $cartella . '/pezzo.pdf';
                $spezzabile = SpezzaPdf::apri($percorsoIngresso)
                    ?? throw new \RuntimeException('Il PDF non si riesce più ad aprire a pezzi.');
                try {
                    $lette = $spezzabile->estrai((int) $stato['pagina'], $quante, $pezzo);
                } finally {
                    $spezzabile->chiudi();
                }

                try {
                    $esito = $parser->scorri($pezzo, (int) $stato['pagina'], $aggiungi);
                } finally {
                    @unlink($pezzo);
                }
                if ((int) $stato['pagina'] === 1) {
                    $stato['intestazione'] = $esito['intestazione'];
                }
                $stato['pagina'] += $lette;
            }
        } finally {
            $archivio->chiudiScrittura();
        }

        // Il documento letto ha riferimenti circolari (pagine ↔ documento):
        // senza una raccolta esplicita resta in memoria fino alla prossima.
        gc_collect_cycles();

        $stato['tracciato'] = $parser->tracciato()?->chiave ?? $stato['tracciato'];
        $stato['byte']      = $archivio->lunghezza();
        if ($stato['pagina'] > $stato['pagine']) {
            $stato['fase'] = 'scrittura';
        }

        return $stato;
    }

    /**
     * L'ultima tappa: raggruppa da disco e scrive, una prenotazione alla volta.
     *
     * @param array<string,mixed> $stato
     * @param array<string,mixed> $regole
     * @return array<string,mixed>
     */
    private function tappaScrittura(string $cartella, array $stato, array $regole, string $percorsoUscita): array
    {
        $formato   = ($regole['formato'] ?? 'xlsx') === 'csv' ? 'csv' : 'xlsx';
        $scrittore = new ScrittoreScidoo();
        $scrittore->apri($percorsoUscita, $formato);

        // Le anomalie di una stampa lunga sono migliaia: anche loro su disco.
        $fileAnomalie = $cartella . '/anomalie.txt';
        $anomalie = fopen($fileAnomalie, 'wb');
        if ($anomalie === false) {
            throw new \RuntimeException("Non riesco a scrivere {$fileAnomalie}");
        }

        $prime = [];
        try {
            $lette = (new Raggruppatore($regole))->raggruppaArchivio(
                new ArchivioRighe($cartella . '/righe.txt'),
                static function (array $prenotazione) use ($scrittore, &$prime): void {
                    $scrittore->aggiungi($prenotazione);
                    if (count($prime) < 4) {
                        $prime[] = $prenotazione;
                    }
                },
                static function (array $anomalia) use ($anomalie): void {
                    fwrite($anomalie, json_encode($anomalia, JSON_UNESCAPED_UNICODE) . "\n");
                }
            );
        } finally {
            fclose($anomalie);
        }
        $scritte = $scrittore->chiudi();

        $stato['fase']      = 'fatto';
        $stato['fatto']     = true;
        $stato['risultato'] = [
            'righe_lette'   => $lette,
            'righe_scritte' => $scritte,
            'pagine'        => (int) $stato['pagine'],
            'intestazione'  => $stato['intestazione'],
            'anomalie_file' => $fileAnomalie,
            'anteprima'     => $this->anteprima($prime),
        ];

        return $stato;
    }

    /**
     * Le anomalie scritte su disco, lette una riga alla volta.
     *
     * @param callable(array<string,mixed>):void|null $consuma senza, le restituisce tutte
     * @return list<array<string,mixed>>
     */
    public static function leggiAnomalie(string $percorso, ?callable $consuma = null): array
    {
        $tutte = [];
        $f = @fopen($percorso, 'rb');
        if ($f === false) {
            return $tutte;
        }
        while (($linea = fgets($f)) !== false) {
            $anomalia = json_decode($linea, true);
            if (!is_array($anomalia)) {
                continue;
            }
            if ($consuma !== null) {
                $consuma($anomalia);
            } else {
                $tutte[] = $anomalia;
            }
        }
        fclose($f);

        return $tutte;
    }

    private static function cartellaTemporanea(): string
    {
        $cartella = sys_get_temp_dir() . '/octo-' . bin2hex(random_bytes(6));
        mkdir($cartella, 0770, true);

        return $cartella;
    }

    /** Toglie una cartella di lavoro: contiene solo file, mai sottocartelle. */
    public static function pulisci(string $cartella): void
    {
        if (!is_dir($cartella)) {
            return;
        }
        foreach (glob($cartella . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($cartella);
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
     * Legge a pezzi e raggruppa da disco, come la conversione: la memoria non
     * dipende dalla lunghezza della stampa. Il tempo sì, e lo step 2 è una
     * pagina che si aspetta ferma: oltre PAGINE_ANALISI_COMPLETA si legge solo
     * l'inizio, e si dice che i conteggi arriveranno con la conversione invece
     * di mostrarne di inventati.
     *
     * @param array<string,mixed> $regole
     * @return array<string,mixed>
     */
    public function analizza(string $percorsoIngresso, array $regole = []): array
    {
        \Vblite\Convert\Errori::passo('motore · analisi, preparo');
        $cartella = self::cartellaTemporanea();
        try {
            $stato   = $this->prepara($percorsoIngresso, $cartella, $regole);
            $campione = $stato['a_pezzi'] && $stato['pagine'] > self::PAGINE_ANALISI_COMPLETA;
            $fino    = $campione ? self::PAGINE_CAMPIONE : null;

            while ($stato['fase'] === 'lettura' && ($fino === null || $stato['pagina'] <= $fino)) {
                $stato = $this->tappaLettura($percorsoIngresso, $cartella, $stato, $fino);
                \Vblite\Convert\Errori::passo('motore · analisi, pagina', ['n' => $stato['pagina'] - 1, 'su' => $stato['pagine']]);
            }

            $prenotazioni = 0;
            $daCorreggere = 0;
            $prime        = [];
            $righe = (new Raggruppatore($regole))->raggruppaArchivio(
                new ArchivioRighe($cartella . '/righe.txt'),
                static function (array $prenotazione) use (&$prenotazioni, &$prime): void {
                    $prenotazioni++;
                    if (count($prime) < 2) {
                        $prime[] = $prenotazione;
                    }
                },
                static function (array $anomalia) use (&$daCorreggere): void {
                    // «Da rivedere» conta solo cio' che chiede una decisione umana.
                    $daCorreggere += ($anomalia['gravita'] ?? 'correggi') === 'correggi' ? 1 : 0;
                }
            );
            \Vblite\Convert\Errori::passo('motore · analisi finita', ['righe' => $righe]);
        } finally {
            self::pulisci($cartella);
        }

        $intestazione = $stato['intestazione'];
        $periodo = ($intestazione['dal'] ?? null) !== null
            ? $intestazione['dal'] . ' – ' . $intestazione['al']
            : 'periodo non dichiarato';

        if ($campione) {
            return [
                'sommario' => sprintf(
                    '%s pagine · %s · testo nativo · stampa lunga: righe e prenotazioni si contano durante la conversione',
                    number_format((int) $stato['pagine'], 0, ',', '.'),
                    $periodo
                ),
                'pagine'       => (int) $stato['pagine'],
                'intestazione' => $intestazione,
                'righe_lette'  => null,
                'prenotazioni' => null,
                'anomalie'     => null,
                'anteprima'    => $prime,
                'campione'     => true,
            ];
        }

        return [
            'sommario' => sprintf(
                '%s pagine · %s righe cliente · %s prenotazioni · %s · testo nativo',
                number_format((int) $stato['pagine'], 0, ',', '.'),
                number_format($righe, 0, ',', '.'),
                number_format($prenotazioni, 0, ',', '.'),
                $periodo
            ),
            'pagine'        => (int) $stato['pagine'],
            'intestazione'  => $intestazione,
            'righe_lette'   => $righe,
            'prenotazioni'  => $prenotazioni,
            'anomalie'      => $daCorreggere,
            'anteprima'     => $prime,
        ];
    }
}
