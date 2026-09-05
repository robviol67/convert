<?php
declare(strict_types=1);

namespace Vblite\Convert\Conversioni\OctoScidoo;

/**
 * Trasforma le righe-ospite della stampa in prenotazioni, una per N°pren.
 *
 * Ogni incertezza produce un'anomalia: la riga esce comunque nel file, con il
 * valore migliore disponibile, e l'anomalia finisce nella schermata «Da rivedere».
 */
final class Raggruppatore
{
    /** Regole applicabili, con i valori di default. */
    public const REGOLE_DEFAULT = [
        'una_riga_per_prenotazione' => true,
        'ripulisci_commenti'        => true,
        'deduci_categoria_camera'   => false,
        'salta_annullate'           => true,
        'sorgente_camera'           => 'cam',   // cam | gruppo | vuoto
        // Spenta di default: nel file di esempio «Letto agg. Bambino» compare in
        // 522 prenotazioni su 584, comprese 223 con un solo ospite e diverse
        // prenotazioni aziendali da una persona. Sembra una voce tariffaria, non
        // un ospite. Chi ha il contesto puo' riaccenderla dalla schermata 2d.
        'bambini_da_supplementi'    => false,
        'periodo_dal'               => null,    // gg/mm/aaaa
        'periodo_al'                => null,
        // Correzioni fatte a mano in «Da rivedere», per N°pren. e colonna Scidoo:
        // ['4.275' => ['Adulti · Bambini' => '2·1']]
        'correzioni'                => [],
    ];

    /** Le colonne toccate da una segnalazione, quando non e' una sola. */
    private const COLONNE_COMPOSTE = [
        'Adulti · Bambini' => ['adulti', 'bambini'],
    ];

    /** Il trattamento Octorate determina insieme Retta e Servizio Iniziale. */
    private const TRATTAMENTI = [
        'Pernottamento Giornaliero' => ['retta' => 'Room Only',          'servizio' => 'Pernotto'],
        'Pernott. Prima Colazione'  => ['retta' => 'Bed & Breakfast',    'servizio' => 'Pernotto'],
        'Mezza Pensione'            => ['retta' => 'Mezza Pensione',     'servizio' => 'Cena'],
        'Pensione Completa'         => ['retta' => 'Pensione Completa',  'servizio' => 'Cena'],
    ];

    /** @var list<array<string,mixed>> */
    private array $anomalie = [];

    /** @param array<string,mixed> $regole */
    public function __construct(private array $regole = [])
    {
        $this->regole += self::REGOLE_DEFAULT;
    }

    /**
     * @param list<array<string,string>> $righe righe-ospite dal Parser
     * @return array{prenotazioni: list<array<string,mixed>>, anomalie: list<array<string,mixed>>, righe_lette:int}
     */
    public function raggruppa(array $righe): array
    {
        $this->anomalie = [];

        $gruppi = [];
        foreach ($righe as $riga) {
            $gruppi[$riga['npren']][] = $riga;
        }

        $prenotazioni = [];
        foreach ($gruppi as $npren => $ospiti) {
            $prenotazione = $this->prenotazione((string) $npren, $ospiti);
            if ($prenotazione === null) {
                continue; // fuori periodo o annullata
            }
            $prenotazioni[] = $prenotazione;
        }

        return [
            'prenotazioni' => $prenotazioni,
            'anomalie'     => $this->anomalie,
            'righe_lette'  => count($righe),
        ];
    }

    /**
     * @param list<array<string,string>> $ospiti
     * @return array<string,mixed>|null
     */
    private function prenotazione(string $npren, array $ospiti): ?array
    {
        $capofila = $ospiti[0];
        $cliente  = trim($capofila['cognome'] . ' ' . $capofila['nome']);

        if (!$this->nelPeriodo($capofila['arrivo'])) {
            return null;
        }

        // «Salta le annullate»: la stampa di esempio non contiene prenotazioni
        // annullate e Octorate non dichiara come le marca (domanda aperta al
        // committente). Finche' non e' noto, la regola non scarta nulla: meglio
        // una riga in piu' da cancellare che una prenotazione persa in silenzio.
        if ($this->regole['salta_annullate'] && $this->annullata($ospiti)) {
            return null;
        }

        // --- Importi: stanno sulla riga capofila del gruppo, non si sommano ---
        $importo = Normalizza::importo($capofila['importo']);
        if ($importo === null) {
            // Nei gruppi multi-camera l'importo puo' stare su una riga successiva.
            foreach ($ospiti as $ospite) {
                $altro = Normalizza::importo($ospite['importo']);
                if ($altro !== null) {
                    $importo = $altro;
                    $this->segnala($npren, $cliente, 'Importo assente sulla riga capofila, ripreso da una riga del gruppo', 'Prezzo Retta', self::valuta($altro), 'informativa');
                    break;
                }
            }
            if ($importo === null && count($ospiti) > 1) {
                $this->segnala($npren, $cliente, 'Nessun importo nel gruppo', 'Prezzo Retta', '');
            }
        }

        $caparra = null;
        $acconto = null;
        foreach ($ospiti as $ospite) {
            $caparra ??= Normalizza::importo($ospite['caparre']);
            $acconto ??= Normalizza::importo($ospite['acconti']);
        }

        // --- Ospiti per fascia d'eta' ---
        [$adulti, $bambini, $neonati] = $this->fasceEta($npren, $cliente, $ospiti);

        // --- Stato e opzione ---
        $dataOpzione = '';
        foreach ($ospiti as $ospite) {
            if (trim($ospite['data_opzione']) !== '') {
                $dataOpzione = trim($ospite['data_opzione']);
                break;
            }
        }
        $stato = $dataOpzione !== '' ? 'Opzione' : 'Confermata con Pagamento';

        // --- Trattamento ---
        $trattamento = trim($capofila['trattamento']);
        $mappa = self::TRATTAMENTI[$trattamento] ?? null;
        if ($mappa === null && $trattamento !== '') {
            $mappa = ['retta' => $trattamento, 'servizio' => 'Pernotto'];
            $this->segnala($npren, $cliente, "Trattamento «{$trattamento}» non previsto dalla mappa: trascritto invariato", 'Retta', $trattamento);
        }

        // --- Commenti ---
        [$note, $noteOta, $categoria] = $this->note($npren, $cliente, $ospiti);

        // --- Contatti dell'intestatario ---
        $telefono = Normalizza::telefono($capofila['telefono']);
        if ($telefono['troncato']) {
            $this->segnala(
                $npren,
                $cliente,
                'Telefono troncato nella stampa: ' . trim($capofila['telefono']),
                $telefono['mobile'] !== null ? 'Cellulare Cliente' : 'Telefono Cliente',
                trim($capofila['telefono'])
            );
        }

        // --- Camera ---
        $camera = match ($this->regole['sorgente_camera']) {
            'gruppo' => trim($capofila['gruppo']),
            'vuoto'  => '',
            default  => trim($capofila['camera']),
        };
        $camere = array_values(array_unique(array_filter(array_map(
            static fn(array $o): string => trim($o['camera']),
            $ospiti
        ))));
        if (count($camere) > 1) {
            $camera = implode(', ', $camere);
            $this->segnala($npren, $cliente, 'Prenotazione su più camere: ' . $camera, 'Camera', $camera);
        }

        // --- Voci senza colonna nel tracciato ---
        $extra = [];
        foreach (['tassa_sogg' => 'Tassa sogg', 'sconto' => 'Sconto', 'convenzione' => 'Convenzione'] as $campo => $etichetta) {
            $valore = trim($capofila[$campo]);
            if ($valore !== '') {
                $extra[] = "{$etichetta}: {$valore}";
            }
        }
        if ($extra !== []) {
            $note = trim(implode(' · ', $extra) . ($note !== '' ? ' · ' . $note : ''));
            $this->segnala(
                $npren,
                $cliente,
                implode(' · ', $extra) . (count($extra) === 1 ? ' non ha' : ' non hanno') . ' una colonna nel tracciato',
                'Note',
                'Trascritta in Note',
                'informativa'
            );
        }

        $prenotazione = [
            'id'                => Normalizza::id($npren),
            'npren'             => $npren,
            'nome'              => $capofila['nome'],
            'cognome'           => $capofila['cognome'],
            'arrivo'            => Normalizza::dataSeriale($capofila['arrivo']),
            'partenza'          => Normalizza::dataSeriale($capofila['partenza']),
            'camera'            => $camera,
            'categoria_camera'  => $categoria ?? '',
            'agenzia'           => Normalizza::agenzia($capofila['agenzia_pagante'], $capofila['agenzia_prenotante']),
            'voucher'           => trim($capofila['voucher']),
            // Il file di esempio Scidoo porta la sola data: l'ora della stampa resta
            // disponibile nel PDF ma non entra nel tracciato.
            'data_inserimento'  => Normalizza::dataSeriale($capofila['data']),
            'stato'             => $stato,
            'scadenza_opzione'  => Normalizza::dataSeriale($dataOpzione),
            'data_annullamento' => null,
            'adulti'            => $adulti,
            'bambini'           => $bambini,
            'neonati'           => $neonati,
            'fasce_eta'         => '',
            'retta'             => $mappa['retta'] ?? '',
            'prezzo_retta'      => $importo,
            'prezzo_extra'      => null,
            'acconto'           => $acconto,
            'caparra'           => $caparra,
            'data_acconto'      => null,
            'data_caparra'      => null,
            'note'              => $note,
            'note_pulizie'      => '',
            'note_ota'          => $noteOta,
            'note_ristorante'   => '',
            'servizio_iniziale' => $mappa['servizio'] ?? '',
            'telefono'          => $telefono['fisso'] ?? '',
            'cellulare'         => $telefono['mobile'] ?? '',
            'email'             => trim($capofila['email']),
            '_ospiti'           => count($ospiti),
            '_pagina'           => (int) $capofila['pagina'],
        ];

        return $this->applicaCorrezioni($npren, $prenotazione);
    }

    /**
     * Sovrascrive i campi corretti a mano.
     *
     * La correzione non ritocca il foglio gia' prodotto: rientra nella
     * conversione, che viene rifatta. Cosi' il file resta il risultato di un
     * unico passaggio deterministico, e non di una serie di ritocchi
     * sovrapposti di cui nessuno tiene il conto.
     *
     * @param array<string,mixed> $prenotazione
     * @return array<string,mixed>
     */
    private function applicaCorrezioni(string $npren, array $prenotazione): array
    {
        $perQuesta = $this->regole['correzioni'][$npren] ?? null;
        if (!is_array($perQuesta)) {
            return $prenotazione;
        }

        $colonne = require __DIR__ . '/colonne.php';

        foreach ($perQuesta as $colonna => $valore) {
            $valore = trim((string) $valore);
            if ($valore === '') {
                continue;
            }

            if (isset(self::COLONNE_COMPOSTE[$colonna])) {
                $pezzi = preg_split('~[·,;/\s]+~u', $valore) ?: [];
                foreach (self::COLONNE_COMPOSTE[$colonna] as $i => $chiave) {
                    if (isset($pezzi[$i]) && $pezzi[$i] !== '') {
                        $prenotazione[$chiave] = (int) $pezzi[$i];
                    }
                }
                continue;
            }

            foreach ($colonne as $definizione) {
                if (rtrim($definizione['testata']) !== rtrim($colonna)) {
                    continue;
                }
                $prenotazione[$definizione['chiave']] = match ($definizione['tipo']) {
                    'intero' => (int) $valore,
                    'valuta' => (float) str_replace(',', '.', str_replace('.', '', $valore)),
                    'data'   => Normalizza::dataSeriale($valore),
                    default  => $valore,
                };
                break;
            }
        }

        return $prenotazione;
    }

    /**
     * Adulti, bambini e neonati. Il solo indizio nella stampa e' il supplemento
     * «Letto agg. Bambino»; il totale ospiti e' il numero di righe del gruppo,
     * che coincide con la somma dei Pax dichiarati per camera.
     *
     * @param list<array<string,string>> $ospiti
     * @return array{0:int,1:int,2:int}
     */
    private function fasceEta(string $npren, string $cliente, array $ospiti): array
    {
        $totale = count($ospiti);

        $paxDichiarati = 0;
        foreach ($ospiti as $ospite) {
            $paxDichiarati += (int) $ospite['pax'];
        }
        if ($paxDichiarati > 0 && $paxDichiarati !== $totale) {
            $this->segnala($npren, $cliente, "Pax dichiarati ({$paxDichiarati}) diversi dalle righe ospite ({$totale})", 'Adulti · Bambini', (string) $totale);
        }

        if (!$this->regole['bambini_da_supplementi']) {
            return [$totale, 0, 0];
        }

        $lettiBambino = 0;
        foreach ($ospiti as $ospite) {
            $lettiBambino += preg_match_all('~Letto agg\.\s*Bambino~iu', $ospite['supplementi']);
        }
        if ($lettiBambino === 0) {
            return [$totale, 0, 0];
        }

        if ($lettiBambino >= $totale) {
            // Il supplemento non puo' descrivere tutti gli ospiti elencati.
            $this->segnala(
                $npren,
                $cliente,
                sprintf(
                    '%s con %d «Letto agg. Bambino»: il conteggio non torna',
                    self::plurale($totale, 'ospite', 'ospiti'),
                    $lettiBambino
                ),
                'Adulti · Bambini',
                "{$totale}·0"
            );

            return [$totale, 0, 0];
        }

        $bambini = $lettiBambino;
        $adulti  = $totale - $bambini;
        $this->segnala(
            $npren,
            $cliente,
            sprintf(
                '%d ospiti + «Letto agg. Bambino»: %s e %s, oppure %d adulti?',
                $totale,
                self::plurale($adulti, 'adulto', 'adulti'),
                self::plurale($bambini, 'bambino', 'bambini'),
                $totale
            ),
            'Adulti · Bambini',
            "{$adulti}·{$bambini}"
        );

        return [$adulti, $bambini, 0];
    }

    /**
     * Note e Note Ota. Il commento OTA e' ripetuto su ogni ospite del gruppo:
     * si tiene una sola volta, sull'intestatario.
     *
     * @param list<array<string,string>> $ospiti
     * @return array{0:string,1:string,2:?string}
     */
    private function note(string $npren, string $cliente, array $ospiti): array
    {
        $grezzo = '';
        foreach ($ospiti as $ospite) {
            if (trim($ospite['commenti']) !== '') {
                $grezzo = $ospite['commenti'];
                break;
            }
        }
        if ($grezzo === '') {
            return ['', '', null];
        }

        $pulito = $this->regole['ripulisci_commenti']
            ? Normalizza::commento($grezzo)
            : trim($grezzo);

        $duplicati = 0;
        foreach ($ospiti as $ospite) {
            if (trim($ospite['commenti']) !== '') {
                $duplicati++;
            }
        }
        if ($duplicati > 1) {
            $this->segnala(
                $npren,
                $cliente,
                $duplicati === 2
                    ? 'Commento OTA condiviso con l\'altro ospite del gruppo: tenuto solo sull\'intestatario'
                    : sprintf(
                        'Commento OTA condiviso con gli altri %d ospiti del gruppo: tenuto solo sull\'intestatario',
                        $duplicati - 1
                    ),
                'Note Ota',
                'Tenuto solo sull\'intestatario',
                'informativa'
            );
        }

        $categoria = null;
        if ($this->regole['deduci_categoria_camera']) {
            $categoria = Normalizza::categoriaCamera($pulito);
            if ($categoria !== null) {
                $this->segnala($npren, $cliente, "Categoria camera dedotta dai commenti: «{$categoria}»", 'Categoria Camera', $categoria, 'informativa');
            }
        }

        // I commenti dei portali portano sempre una firma riconoscibile; gli altri
        // sono annotazioni della struttura e vanno in Note, non in Note Ota.
        $ota = preg_match('~res\.id|booking\.com|expedia|genius|rate plan|customers:|guests:~iu', $pulito) === 1;

        return $ota ? ['', $pulito, $categoria] : [$pulito, '', $categoria];
    }

    /**
     * Riconosce una prenotazione annullata. Da completare quando si sapra' come
     * Octorate le marca nella stampa: oggi nessun file di esempio ne contiene.
     *
     * @param list<array<string,string>> $ospiti
     */
    private function annullata(array $ospiti): bool
    {
        foreach ($ospiti as $ospite) {
            if (preg_match('~\bannullat|\bcancellat|\bstornat~iu', $ospite['trattamento'] . ' ' . $ospite['convenzione']) === 1) {
                return true;
            }
        }

        return false;
    }

    private function nelPeriodo(string $arrivo): bool
    {
        $dal = $this->regole['periodo_dal'] ?? null;
        $al  = $this->regole['periodo_al'] ?? null;
        if ($dal === null && $al === null) {
            return true;
        }

        $seriale = Normalizza::dataSeriale($arrivo);
        if ($seriale === null) {
            return true; // senza data non si esclude: la riga esce e si segnala altrove
        }
        if ($dal !== null && ($s = Normalizza::dataSeriale($dal)) !== null && $seriale < $s) {
            return false;
        }
        if ($al !== null && ($s = Normalizza::dataSeriale($al)) !== null && $seriale > $s) {
            return false;
        }

        return true;
    }

    /**
     * @param string $gravita «correggi» quando serve una decisione umana,
     *                        «informativa» quando dichiara solo cosa e' stato fatto.
     */
    private function segnala(
        string $npren,
        string $cliente,
        string $motivo,
        string $colonna,
        string $valoreProposto,
        string $gravita = 'correggi'
    ): void {
        $this->anomalie[] = [
            'chiave'          => $npren,
            'cliente'         => $cliente,
            'motivo'          => $motivo,
            'colonna'         => $colonna,
            'valore_proposto' => $valoreProposto,
            'gravita'         => $gravita,
        ];
    }

    private static function valuta(float $v): string
    {
        return number_format($v, 2, ',', '.');
    }

    private static function plurale(int $n, string $singolare, string $plurale): string
    {
        return $n . ' ' . ($n === 1 ? $singolare : $plurale);
    }
}
