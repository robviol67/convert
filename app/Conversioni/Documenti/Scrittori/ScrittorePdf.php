<?php
declare(strict_types=1);

namespace Vblite\Convert\Conversioni\Documenti\Scrittori;

use Vblite\Convert\Conversioni\Documenti\Blocco;
use Vblite\Convert\Conversioni\Documenti\Documento;
use Vblite\Convert\Conversioni\Documenti\Testo;

/**
 * Modello → PDF.
 *
 * Scritto a mano, come l'XLSX e il DOCX, e per lo stesso motivo: le librerie
 * PDF per PHP tengono in memoria l'intero documento e qui il tetto è ~20 MB.
 *
 * Fa quello che serve a un documento di testo — titoli, paragrafi giustificati
 * a sinistra, elenchi, citazioni rientrate, codice a spaziatura fissa, tabelle
 * semplici, immagini, numeri di pagina — e nulla di più. Niente colonne, niente
 * testo che scorre attorno alle figure, niente sillabazione. Usa i font
 * standard del PDF (Helvetica e Courier), che ogni lettore ha già: non serve
 * incorporare nulla e il file resta leggero.
 *
 * Limite dichiarato: i font standard sono codificati in WinAnsi, che copre le
 * lingue dell'Europa occidentale. Un testo in greco o cirillico perderebbe i
 * caratteri, e in quel caso lo si segnala invece di produrre righe di punti
 * interrogativi.
 */
final class ScrittorePdf implements Scrittore
{
    // Misure in punti tipografici: 72 per pollice. A4 = 595 × 842.
    private const PAGINA_L  = 595.28;
    private const PAGINA_H  = 841.89;
    private const MARGINE   = 56.7;      // 2 cm
    private const INTERLINEA = 1.45;

    private const CORPI = [1 => 20.0, 2 => 16.0, 3 => 14.0, 4 => 12.5, 5 => 11.5, 6 => 11.0];
    private const CORPO_TESTO = 11.0;

    private string $percorso = '';
    private int $resi = 0;

    /** @var list<string> il flusso di contenuto di ogni pagina */
    private array $pagine = [];

    private string $corrente = '';
    private float $y = 0.0;

    /** @var array<int,int> numerazione degli elenchi, per livello */
    private array $contatori = [];

    /** @var list<array{percorso:string,l:int,h:int,tipo:string}> */
    private array $immagini = [];

    private ?Documento $documento = null;
    private bool $fuoriWinAnsi = false;

    public static function estensione(): string
    {
        return 'pdf';
    }

    public static function nome(): string
    {
        return 'PDF';
    }

    /** Questo formato non ha impostazioni: le regole non lo riguardano. */
    public function configura(array $regole): void
    {
    }

    public function apri(string $percorso, Documento $documento): void
    {
        $this->percorso  = $percorso;
        $this->documento = $documento;
        $this->resi      = 0;
        $this->pagine    = [];
        $this->corrente  = '';
        $this->contatori = [];
        $this->immagini  = [];
        $this->fuoriWinAnsi = false;
        $this->nuovaPagina();
    }

    public function blocco(Blocco $blocco): void
    {
        if ($blocco->tipo === Blocco::ELENCO && $blocco->ordinato) {
            $this->contatori[$blocco->livello] = ($this->contatori[$blocco->livello] ?? 0) + 1;
            foreach (array_keys($this->contatori) as $livello) {
                if ($livello > $blocco->livello) {
                    unset($this->contatori[$livello]);
                }
            }
        } elseif ($blocco->tipo !== Blocco::ELENCO) {
            $this->contatori = [];
        }

        match ($blocco->tipo) {
            Blocco::TITOLO    => $this->titolo($blocco),
            Blocco::ELENCO    => $this->elenco($blocco, $this->contatori[$blocco->livello] ?? 1),
            Blocco::CITAZIONE => $this->citazione($blocco),
            Blocco::CODICE    => $this->codice($blocco),
            Blocco::TABELLA   => $this->tabella($blocco),
            Blocco::IMMAGINE  => $this->immagine($blocco),
            Blocco::RIGA      => $this->riga(),
            default           => $this->paragrafo($blocco),
        };

        $this->resi++;
    }

    public function chiudi(): int
    {
        $this->chiudiPagina();
        $this->assembla();

        if ($this->fuoriWinAnsi && $this->documento !== null) {
            $this->documento->perdita(
                'Caratteri fuori dall\'alfabeto occidentale',
                'I font standard del PDF non li contengono: sono stati sostituiti. '
                . 'Per quei testi conviene un altro formato in uscita.'
            );
        }

        return $this->resi;
    }

    // ── impaginazione ────────────────────────────────────────────────────────

    private function nuovaPagina(): void
    {
        $this->corrente = '';
        $this->y        = self::PAGINA_H - self::MARGINE;
    }

    private function chiudiPagina(): void
    {
        // Numero di pagina, in fondo e al centro.
        $numero = count($this->pagine) + 1;
        $this->corrente .= sprintf(
            "BT /F1 9 Tf 0.5 0.5 0.5 rg 1 0 0 1 %.2f %.2f Tm (%s) Tj ET\n",
            self::PAGINA_L / 2 - 6,
            self::MARGINE / 2,
            (string) $numero
        );
        $this->pagine[] = $this->corrente;
    }

    private function spazio(float $quanto): void
    {
        $this->y -= $quanto;
        if ($this->y < self::MARGINE + 20) {
            $this->chiudiPagina();
            $this->nuovaPagina();
        }
    }

    private function larghezzaUtile(float $rientro = 0.0): float
    {
        return self::PAGINA_L - 2 * self::MARGINE - $rientro;
    }

    // ── blocchi ──────────────────────────────────────────────────────────────

    private function titolo(Blocco $blocco): void
    {
        $corpo = self::CORPI[$blocco->livello] ?? self::CORPO_TESTO;
        $this->spazio($corpo * 0.9);
        $this->scriviTesti($blocco->testi, $corpo, 0.0, true, [0.12, 0.22, 0.39]);
        $this->spazio($corpo * 0.35);
    }

    private function paragrafo(Blocco $blocco): void
    {
        $this->scriviTesti($blocco->testi, self::CORPO_TESTO);
        $this->spazio(self::CORPO_TESTO * 0.55);
    }

    private function elenco(Blocco $blocco, int $numero): void
    {
        $rientro = 14.0 + $blocco->livello * 14.0;
        $segno   = $blocco->ordinato ? $numero . '.' : '•';

        // Il segno va sulla stessa riga della prima parola. Scriverlo prima di
        // impaginare il testo lo metteva una riga più in alto, perché la
        // scrittura del testo comincia spostando la quota verso il basso.
        $this->scriviTesti(
            $blocco->testi,
            self::CORPO_TESTO,
            $rientro,
            false,
            null,
            false,
            [$segno, self::MARGINE + $rientro - 14.0]
        );
        $this->spazio(self::CORPO_TESTO * 0.25);
    }

    private function citazione(Blocco $blocco): void
    {
        $rientro = 24.0;
        $yInizio = $this->y;
        $this->scriviTesti($blocco->testi, self::CORPO_TESTO, $rientro, false, [0.27, 0.27, 0.27], true);

        // La stanghetta a sinistra, alta quanto il testo appena scritto.
        if ($this->y < $yInizio) {
            $this->corrente .= sprintf(
                "0.73 0.73 0.73 rg %.2f %.2f %.2f %.2f re f\n",
                self::MARGINE + 8,
                $this->y + self::CORPO_TESTO * 0.4,
                1.5,
                $yInizio - $this->y
            );
        }
        $this->spazio(self::CORPO_TESTO * 0.6);
    }

    private function codice(Blocco $blocco): void
    {
        $corpo = 9.5;
        foreach (explode("\n", $blocco->nudo()) as $riga) {
            $this->spazio($corpo * 1.35);
            $this->testoGrezzo($riga, self::MARGINE + 8, $this->y, $corpo, 'F3', [0.15, 0.15, 0.15]);
        }
        $this->spazio($corpo * 0.9);
    }

    private function riga(): void
    {
        $this->spazio(8);
        $this->corrente .= sprintf(
            "0.73 0.73 0.73 rg %.2f %.2f %.2f 0.7 re f\n",
            self::MARGINE,
            $this->y,
            self::PAGINA_L - 2 * self::MARGINE
        );
        $this->spazio(10);
    }

    private function tabella(Blocco $blocco): void
    {
        $colonne = 0;
        foreach ($blocco->righe as $riga) {
            $colonne = max($colonne, count($riga));
        }
        if ($colonne === 0) {
            return;
        }

        $corpo   = 9.5;
        $passo   = $this->larghezzaUtile() / $colonne;
        $this->spazio(6);

        foreach ($blocco->righe as $indice => $riga) {
            // Quante righe di testo serviranno: la più alta detta l'altezza.
            $spezzate = [];
            $alte = 1;
            for ($c = 0; $c < $colonne; $c++) {
                $testo = Testo::nudo($riga[$c] ?? []);
                $spezzate[$c] = $this->spezza($testo, $passo - 8, $corpo, $indice === 0 ? 'F2' : 'F1');
                $alte = max($alte, count($spezzate[$c]));
            }

            $altezza = $alte * $corpo * 1.35 + 4;
            if ($this->y - $altezza < self::MARGINE + 20) {
                $this->chiudiPagina();
                $this->nuovaPagina();
            }

            $yRiga = $this->y;
            for ($c = 0; $c < $colonne; $c++) {
                $y = $yRiga - $corpo;
                foreach ($spezzate[$c] as $pezzo) {
                    $this->testoGrezzo(
                        $pezzo,
                        self::MARGINE + $c * $passo + 4,
                        $y,
                        $corpo,
                        $indice === 0 ? 'F2' : 'F1'
                    );
                    $y -= $corpo * 1.35;
                }
            }

            // Il filetto sotto la riga.
            $this->corrente .= sprintf(
                "0.8 0.8 0.8 rg %.2f %.2f %.2f 0.5 re f\n",
                self::MARGINE,
                $yRiga - $altezza + 2,
                $this->larghezzaUtile()
            );
            $this->y = $yRiga - $altezza;
        }
        $this->spazio(8);
    }

    private function immagine(Blocco $blocco): void
    {
        $percorso = $this->documento?->immagini()[$blocco->extra] ?? null;
        if ($percorso === null || !is_file($percorso)) {
            $this->scriviTesti(
                [new Testo('[immagine non disponibile: ' . ($blocco->alt !== '' ? $blocco->alt : basename($blocco->extra)) . ']', false, true)],
                self::CORPO_TESTO
            );
            $this->spazio(self::CORPO_TESTO * 0.55);

            return;
        }

        $misure = @getimagesize($percorso);
        // Nel PDF si incorporano direttamente solo JPEG: gli altri formati
        // andrebbero decodificati e ricompressi, che qui costa troppa memoria.
        if ($misure === false || $misure[2] !== IMAGETYPE_JPEG) {
            $this->scriviTesti(
                [new Testo('[immagine: ' . basename($percorso) . ' — formato non incorporabile nel PDF]', false, true)],
                self::CORPO_TESTO
            );
            $this->spazio(self::CORPO_TESTO * 0.55);
            $this->documento?->perdita(
                'Immagine non incorporata nel PDF',
                basename($percorso) . ': nel PDF si incorporano direttamente solo i JPEG.'
            );

            return;
        }

        $larghezza = min((float) $misure[0], $this->larghezzaUtile());
        $altezza   = $larghezza * $misure[1] / $misure[0];

        // Un'immagine più alta della pagina si riduce, non si taglia.
        $massima = self::PAGINA_H - 2 * self::MARGINE - 20;
        if ($altezza > $massima) {
            $larghezza *= $massima / $altezza;
            $altezza    = $massima;
        }

        if ($this->y - $altezza < self::MARGINE + 20) {
            $this->chiudiPagina();
            $this->nuovaPagina();
        }

        $this->immagini[] = ['percorso' => $percorso, 'l' => $misure[0], 'h' => $misure[1], 'tipo' => 'jpeg'];
        $nome = 'Im' . count($this->immagini);

        $this->y -= $altezza;
        $this->corrente .= sprintf(
            "q %.2f 0 0 %.2f %.2f %.2f cm /%s Do Q\n",
            $larghezza,
            $altezza,
            self::MARGINE + ($this->larghezzaUtile() - $larghezza) / 2,
            $this->y,
            $nome
        );
        $this->spazio(10);
    }

    // ── testo ────────────────────────────────────────────────────────────────

    /**
     * @param list<Testo>      $testi
     * @param list<float>|null $colore
     */
    private function scriviTesti(
        array $testi,
        float $corpo,
        float $rientro = 0.0,
        bool $grassettoTutto = false,
        ?array $colore = null,
        bool $corsivoTutto = false,
        ?array $marcatore = null
    ): void {
        // Si impagina parola per parola tenendo il tratto di provenienza, così
        // grassetto e corsivo restano al loro posto anche a metà riga. Serve
        // anche sapere quali parole erano attaccate nell'originale: fra
        // «**grassetto**» e la virgola che segue non va messo uno spazio solo
        // perché appartengono a tratti diversi.
        $parole = [];
        $codaBianca = true;
        foreach ($testi as $testo) {
            $pezzi = preg_split('~(\s+)~u', $testo->testo, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($pezzi as $indice => $parola) {
                $parole[] = [
                    't' => $parola,
                    'x' => $testo,
                    'attaccata' => $indice === 0 && !$codaBianca
                        && preg_match('~^\s~u', $testo->testo) !== 1,
                ];
            }
            if ($pezzi !== []) {
                $codaBianca = preg_match('~\s$~u', $testo->testo) === 1;
            }
        }
        if ($parole === []) {
            return;
        }

        $larghezza = $this->larghezzaUtile($rientro);
        $riga      = [];
        $usata     = 0.0;

        foreach ($parole as $parola) {
            $font  = $this->font($parola['x'], $grassettoTutto, $corsivoTutto);
            $largo = $this->largo($parola['t'], $corpo, $font)
                   + ($parola['attaccata'] ? 0.0 : $this->largo(' ', $corpo, $font));

            if ($usata + $largo > $larghezza && $riga !== []) {
                $this->rendiRiga($riga, $corpo, $rientro, $grassettoTutto, $colore, $corsivoTutto, $marcatore);
                $marcatore = null;      // solo sulla prima riga
                $riga  = [];
                $usata = 0.0;
            }
            $riga[]  = $parola;
            $usata  += $largo;
        }
        if ($riga !== []) {
            $this->rendiRiga($riga, $corpo, $rientro, $grassettoTutto, $colore, $corsivoTutto, $marcatore);
        }
    }

    /**
     * @param list<array{t:string,x:Testo}> $riga
     * @param list<float>|null              $colore
     */
    private function rendiRiga(array $riga, float $corpo, float $rientro, bool $grassettoTutto, ?array $colore, bool $corsivoTutto, ?array $marcatore = null): void
    {
        $this->spazio($corpo * self::INTERLINEA);

        if ($marcatore !== null) {
            $this->testoGrezzo((string) $marcatore[0], (float) $marcatore[1], $this->y, $corpo, 'F1');
        }

        // Si raggruppano le parole contigue che condividono il font: una sola
        // Tj per gruppo. Posizionare ogni parola per conto suo sommava gli
        // errori di stima lungo la riga — le parole finivano attaccate — e
        // rendeva il testo inestraibile, perché fra una Tj e l'altra non c'è
        // spazio che un lettore possa vedere.
        $x = self::MARGINE + $rientro;
        $gruppo = '';
        $font = null;
        $link = null;
        $spazioDopo = false;

        $svuota = function () use (&$gruppo, &$font, &$x, &$link, &$spazioDopo, $corpo, $colore): void {
            if ($gruppo === '') {
                return;
            }
            // Lo spazio finale si disegna, non si salta: come semplice
            // avanzamento della penna sarebbe invisibile a chi estrae il testo,
            // e le parole tornerebbero fuori attaccate.
            $conCoda = $gruppo . ($spazioDopo ? ' ' : '');
            $this->testoGrezzo($conCoda, $x, $this->y, $corpo, (string) $font, $colore, $link);
            $x += $this->largo($conCoda, $corpo, (string) $font);
            $gruppo = '';
        };

        foreach ($riga as $indice => $parola) {
            $suo = $this->font($parola['x'], $grassettoTutto, $corsivoTutto);
            $cambio = $font !== null && ($suo !== $font || $parola['x']->collegamento !== $link);

            if ($cambio) {
                // Lo spazio prima del gruppo nuovo lo mette il gruppo vecchio,
                // ma solo se le due parole erano separate nell'originale.
                $spazioDopo = !$parola['attaccata'];
                $svuota();
            }

            $font = $suo;
            $link = $parola['x']->collegamento;
            $gruppo .= ($gruppo === '' || $parola['attaccata'] ? '' : ' ') . $parola['t'];
        }
        $spazioDopo = false;
        $svuota();
    }

    private function font(Testo $testo, bool $grassettoTutto, bool $corsivoTutto): string
    {
        if ($testo->codice) {
            return 'F3';
        }
        $grassetto = $testo->grassetto || $grassettoTutto;
        $corsivo   = $testo->corsivo || $corsivoTutto;

        return match (true) {
            $grassetto && $corsivo => 'F4',
            $grassetto             => 'F2',
            $corsivo               => 'F5',
            default                => 'F1',
        };
    }

    /** @param list<float>|null $colore */
    private function testoGrezzo(string $testo, float $x, float $y, float $corpo, string $font, ?array $colore = null, ?string $collegamento = null): void
    {
        if (trim($testo) === '') {
            return;
        }
        $rgb = $colore ?? ($collegamento !== null && $collegamento !== '' ? [0.0, 0.35, 0.6] : [0.0, 0.0, 0.0]);

        $this->corrente .= sprintf(
            "BT /%s %.1f Tf %.3f %.3f %.3f rg 1 0 0 1 %.2f %.2f Tm (%s) Tj ET\n",
            $font,
            $corpo,
            $rgb[0],
            $rgb[1],
            $rgb[2],
            $x,
            $y,
            $this->fuga($testo)
        );
    }

    /**
     * Larghezza di una stringa nel font scelto.
     *
     * Sono le metriche vere dei font standard del PDF, in millesimi di em.
     * Stimarle «a occhio» per classi di carattere sembrava sufficiente e non
     * lo era: le parole finivano attaccate, perché ogni errore si sommava a
     * quello prima lungo la riga.
     */
    private function largo(string $testo, float $corpo, string $font): float
    {
        if ($font === 'F3') {
            return mb_strlen($testo) * $corpo * 0.6;      // Courier è a passo fisso
        }

        $tabella  = ($font === 'F2' || $font === 'F4') ? self::larghezzeNere() : self::larghezzeTonde();
        $totale   = 0;
        $convertito = @mb_convert_encoding($testo, 'Windows-1252', 'UTF-8');
        $lunghezza  = strlen((string) $convertito);

        for ($i = 0; $i < $lunghezza; $i++) {
            $codice  = ord($convertito[$i]);
            $totale += $tabella[$codice] ?? 556;
        }

        return $totale * $corpo / 1000;
    }

    /** @return array<int,int> larghezze di Helvetica, per codice di carattere */
    private static function larghezzeTonde(): array
    {
        static $tabella = null;
        if ($tabella !== null) {
            return $tabella;
        }

        $tabella = array_fill(0, 256, 556);
        $misure = [
            32 => 278, 33 => 278, 34 => 355, 35 => 556, 36 => 556, 37 => 889, 38 => 667,
            39 => 191, 40 => 333, 41 => 333, 42 => 389, 43 => 584, 44 => 278, 45 => 333,
            46 => 278, 47 => 278, 58 => 278, 59 => 278, 60 => 584, 61 => 584, 62 => 584,
            63 => 556, 64 => 1015,
            65 => 667, 66 => 667, 67 => 722, 68 => 722, 69 => 667, 70 => 611, 71 => 778,
            72 => 722, 73 => 278, 74 => 500, 75 => 667, 76 => 556, 77 => 833, 78 => 722,
            79 => 778, 80 => 667, 81 => 778, 82 => 722, 83 => 667, 84 => 611, 85 => 722,
            86 => 667, 87 => 944, 88 => 667, 89 => 667, 90 => 611,
            91 => 278, 92 => 278, 93 => 278, 94 => 469, 95 => 556, 96 => 333,
            97 => 556, 98 => 556, 99 => 500, 100 => 556, 101 => 556, 102 => 278, 103 => 556,
            104 => 556, 105 => 222, 106 => 222, 107 => 500, 108 => 222, 109 => 833, 110 => 556,
            111 => 556, 112 => 556, 113 => 556, 114 => 333, 115 => 500, 116 => 278, 117 => 556,
            118 => 500, 119 => 722, 120 => 500, 121 => 500, 122 => 500,
            123 => 334, 124 => 260, 125 => 334, 126 => 584,
        ];
        foreach ($misure as $codice => $larghezza) {
            $tabella[$codice] = $larghezza;
        }
        // Le lettere accentate misurano come le loro basi.
        foreach (range(192, 255) as $codice) {
            $tabella[$codice] = $codice < 224 ? 667 : 556;
        }
        foreach (range(48, 57) as $codice) {
            $tabella[$codice] = 556;
        }

        return $tabella;
    }

    /** @return array<int,int> larghezze di Helvetica-Bold */
    private static function larghezzeNere(): array
    {
        static $tabella = null;
        if ($tabella !== null) {
            return $tabella;
        }

        $tabella = array_fill(0, 256, 611);
        $misure = [
            32 => 278, 33 => 333, 34 => 474, 35 => 556, 36 => 556, 37 => 889, 38 => 722,
            39 => 238, 40 => 333, 41 => 333, 42 => 389, 43 => 584, 44 => 278, 45 => 333,
            46 => 278, 47 => 278, 58 => 333, 59 => 333, 60 => 584, 61 => 584, 62 => 584,
            63 => 611, 64 => 975,
            65 => 722, 66 => 722, 67 => 722, 68 => 722, 69 => 667, 70 => 611, 71 => 778,
            72 => 722, 73 => 278, 74 => 556, 75 => 722, 76 => 611, 77 => 833, 78 => 722,
            79 => 778, 80 => 667, 81 => 778, 82 => 722, 83 => 667, 84 => 611, 85 => 722,
            86 => 667, 87 => 944, 88 => 667, 89 => 667, 90 => 611,
            91 => 333, 92 => 278, 93 => 333, 94 => 584, 95 => 556, 96 => 333,
            97 => 556, 98 => 611, 99 => 556, 100 => 611, 101 => 556, 102 => 333, 103 => 611,
            104 => 611, 105 => 278, 106 => 278, 107 => 556, 108 => 278, 109 => 889, 110 => 611,
            111 => 611, 112 => 611, 113 => 611, 114 => 389, 115 => 556, 116 => 333, 117 => 611,
            118 => 556, 119 => 778, 120 => 556, 121 => 556, 122 => 500,
            123 => 389, 124 => 280, 125 => 389, 126 => 584,
        ];
        foreach ($misure as $codice => $larghezza) {
            $tabella[$codice] = $larghezza;
        }
        foreach (range(192, 255) as $codice) {
            $tabella[$codice] = $codice < 224 ? 722 : 611;
        }
        foreach (range(48, 57) as $codice) {
            $tabella[$codice] = 556;
        }

        return $tabella;
    }

    /** @return list<string> */
    private function spezza(string $testo, float $larghezza, float $corpo, string $font): array
    {
        $righe = [];
        $riga  = '';
        foreach (preg_split('~\s+~u', trim($testo), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $parola) {
            $prova = $riga === '' ? $parola : $riga . ' ' . $parola;
            if ($this->largo($prova, $corpo, $font) > $larghezza && $riga !== '') {
                $righe[] = $riga;
                $riga    = $parola;
                continue;
            }
            $riga = $prova;
        }
        if ($riga !== '') {
            $righe[] = $riga;
        }

        return $righe === [] ? [''] : $righe;
    }

    /** Testo protetto per un flusso PDF, in codifica WinAnsi. */
    private function fuga(string $testo): string
    {
        $convertito = @mb_convert_encoding($testo, 'Windows-1252', 'UTF-8');
        if ($convertito === false) {
            $convertito = $testo;
        }
        // Il «?» compare dove la conversione non ce l'ha fatta.
        if (str_contains($convertito, '?') && !str_contains($testo, '?')) {
            $this->fuoriWinAnsi = true;
        }

        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ''], $convertito);
    }

    // ── assemblaggio del file ────────────────────────────────────────────────

    /**
     * Mette insieme il PDF.
     *
     * Un PDF è una sequenza di oggetti numerati più una tavola che dice a che
     * byte comincia ognuno. La tavola va scritta con gli scostamenti esatti:
     * sbagliarli di un byte rende il file illeggibile.
     */
    private function assembla(): void
    {
        $f = fopen($this->percorso, 'w');
        if ($f === false) {
            throw new \RuntimeException("Non riesco a scrivere {$this->percorso}");
        }

        $oggetti     = [];
        $scostamenti = [];
        $posizione   = 0;

        $scrivi = static function (string $testo) use ($f, &$posizione): void {
            fwrite($f, $testo);
            $posizione += strlen($testo);
        };

        $scrivi("%PDF-1.4\n%\xE2\xE3\xCF\xD3\n");

        $quantePagine = count($this->pagine);
        $primaPagina  = 3;                                  // 1 = catalogo, 2 = albero pagine
        $primoFlusso  = $primaPagina + $quantePagine;
        $primoFont    = $primoFlusso + $quantePagine;
        $primaImmagine = $primoFont + 5;

        // 1 · catalogo
        $scostamenti[1] = $posizione;
        $scrivi("1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n");

        // 2 · albero delle pagine
        $figli = [];
        for ($i = 0; $i < $quantePagine; $i++) {
            $figli[] = ($primaPagina + $i) . ' 0 R';
        }
        $scostamenti[2] = $posizione;
        $scrivi("2 0 obj\n<< /Type /Pages /Count {$quantePagine} /Kids [" . implode(' ', $figli) . "] >>\nendobj\n");

        // risorse comuni a tutte le pagine
        $font = '/F1 ' . $primoFont . ' 0 R /F2 ' . ($primoFont + 1) . ' 0 R /F3 ' . ($primoFont + 2)
              . ' 0 R /F4 ' . ($primoFont + 3) . ' 0 R /F5 ' . ($primoFont + 4) . ' 0 R';
        $xobject = '';
        foreach ($this->immagini as $indice => $immagine) {
            $xobject .= '/Im' . ($indice + 1) . ' ' . ($primaImmagine + $indice) . ' 0 R ';
        }
        $risorse = '<< /Font << ' . $font . ' >>'
                 . ($xobject !== '' ? ' /XObject << ' . trim($xobject) . ' >>' : '')
                 . ' /ProcSet [/PDF /Text /ImageC] >>';

        // 3.. · le pagine
        for ($i = 0; $i < $quantePagine; $i++) {
            $numero = $primaPagina + $i;
            $scostamenti[$numero] = $posizione;
            $scrivi("{$numero} 0 obj\n<< /Type /Page /Parent 2 0 R "
                . sprintf('/MediaBox [0 0 %.2f %.2f] ', self::PAGINA_L, self::PAGINA_H)
                . "/Resources {$risorse} /Contents " . ($primoFlusso + $i) . " 0 R >>\nendobj\n");
        }

        // .. · i flussi di contenuto
        for ($i = 0; $i < $quantePagine; $i++) {
            $numero  = $primoFlusso + $i;
            $flusso  = $this->pagine[$i];
            $scostamenti[$numero] = $posizione;
            $scrivi("{$numero} 0 obj\n<< /Length " . strlen($flusso) . " >>\nstream\n{$flusso}endstream\nendobj\n");
        }

        // .. · i font standard: non si incorporano, ogni lettore li ha
        $nomi = ['Helvetica', 'Helvetica-Bold', 'Courier', 'Helvetica-BoldOblique', 'Helvetica-Oblique'];
        foreach ($nomi as $indice => $nome) {
            $numero = $primoFont + $indice;
            $scostamenti[$numero] = $posizione;
            $scrivi("{$numero} 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /{$nome} /Encoding /WinAnsiEncoding >>\nendobj\n");
        }

        // .. · le immagini, incorporate come JPEG senza ricomprimerle
        foreach ($this->immagini as $indice => $immagine) {
            $numero = $primaImmagine + $indice;
            $dati   = (string) file_get_contents($immagine['percorso']);
            $scostamenti[$numero] = $posizione;
            $scrivi("{$numero} 0 obj\n<< /Type /XObject /Subtype /Image"
                . " /Width {$immagine['l']} /Height {$immagine['h']}"
                . " /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode"
                . ' /Length ' . strlen($dati) . " >>\nstream\n");
            $scrivi($dati . "\nendstream\nendobj\n");
            unset($dati);
        }

        // la tavola degli scostamenti
        $quanti = $primaImmagine + count($this->immagini);
        $inizioTavola = $posizione;
        $scrivi("xref\n0 {$quanti}\n0000000000 65535 f \n");
        for ($n = 1; $n < $quanti; $n++) {
            $scrivi(sprintf("%010d 00000 n \n", $scostamenti[$n] ?? 0));
        }
        $scrivi("trailer\n<< /Size {$quanti} /Root 1 0 R >>\nstartxref\n{$inizioTavola}\n%%EOF\n");

        fclose($f);
    }
}
