<?php
declare(strict_types=1);

namespace Vblite\Convert\Conversioni\Documenti\Scrittori;

use Vblite\Convert\Conversioni\Documenti\Blocco;
use Vblite\Convert\Conversioni\Documenti\Documento;
use Vblite\Convert\Conversioni\Documenti\Testo;

/**
 * Modello → EPUB 3.
 *
 * Un EPUB è uno zip con dentro XHTML più un paio di file che ne descrivono
 * l'ordine: content.opf dice cosa c'è e in che sequenza, nav.xhtml è l'indice
 * che il lettore mostra nel menu. Si scrive a mano come gli altri formati, e
 * per la stessa ragione: la memoria.
 *
 * Due vincoli che l'EPUB impone e che non si possono aggirare:
 *
 * 1. Il file «mimetype» deve essere il PRIMO dentro lo zip e NON compresso.
 *    È così che un lettore riconosce un EPUB senza aprirlo tutto; sbagliarlo
 *    produce un file che qualche lettore apre e altri rifiutano.
 * 2. Il contenuto è XHTML, non HTML: ogni tag va chiuso e ogni entità
 *    dichiarata. Un `<br>` invece di `<br/>` fa fallire l'intero capitolo.
 *
 * I capitoli si tagliano ai titoli: è l'unica divisione che un documento
 * dichiara davvero. L'indice si costruisce dagli stessi titoli, quindi è
 * sempre coerente con il testo — non è una tabella scritta a parte che può
 * andare fuori sincrono.
 */
final class ScrittoreEpub implements Scrittore
{
    /** Fino a che livello di titolo far comparire nell'indice. */
    private const INDICE_FINO_A = 3;

    private string $percorso = '';
    private int $resi = 0;

    private ?Documento $documento = null;

    /** @var array<string,mixed> titolo, autore, lingua, livello di taglio */
    private array $regole = [];

    /** @var resource|null il capitolo in corso di scrittura */
    private $f = null;

    private string $fileCorrente = '';
    private int $numeroCapitolo = 0;

    /** @var list<array{file:string,titolo:string,ancora:string,livello:int,id:string}> */
    private array $indice = [];

    /** @var list<array{file:string,percorso:string}> i capitoli chiusi */
    private array $capitoli = [];

    private int $ancore = 0;
    private bool $qualcosaScritto = false;

    /** @var string|null il tipo di lista aperta: «ul», «ol» o niente */
    private ?string $elencoAperto = null;

    public function configura(array $regole): void
    {
        $this->regole = $regole;
    }

    public static function estensione(): string
    {
        return 'epub';
    }

    public static function nome(): string
    {
        return 'EPUB';
    }

    public function apri(string $percorso, Documento $documento): void
    {
        $this->percorso        = $percorso;
        $this->documento       = $documento;
        $this->resi            = 0;
        $this->numeroCapitolo  = 0;
        $this->indice          = [];
        $this->capitoli        = [];
        $this->ancore          = 0;
        $this->qualcosaScritto = false;

        $this->apriCapitolo('');
    }

    public function blocco(Blocco $blocco): void
    {
        $soglia = (int) ($this->regole['epub_taglio'] ?? 1);

        // Un titolo abbastanza alto comincia un capitolo nuovo — ma non se il
        // capitolo in corso è ancora vuoto, altrimenti il primo titolo del
        // documento produrrebbe un capitolo bianco davanti a tutto.
        if ($blocco->tipo === Blocco::TITOLO && $blocco->livello <= $soglia && $this->qualcosaScritto) {
            $this->chiudiCapitolo();
            $this->apriCapitolo($blocco->nudo());
        }

        // Una lista aperta va chiusa appena arriva altro: l'XHTML non perdona
        // un <ul> lasciato aperto, e il capitolo intero smette di caricarsi.
        if ($blocco->tipo !== Blocco::ELENCO) {
            $this->chiudiElenco();
        }

        if ($blocco->tipo === Blocco::TITOLO && $blocco->livello <= self::INDICE_FINO_A) {
            $this->annotaNellIndice($blocco);
        } else {
            $this->scrivi($this->rendi($blocco));
        }

        $this->qualcosaScritto = true;
        $this->resi++;
    }

    public function chiudi(): int
    {
        $this->chiudiCapitolo();
        $this->assembla();

        return $this->resi;
    }

    // ── capitoli ─────────────────────────────────────────────────────────────

    private function apriCapitolo(string $titolo): void
    {
        $this->numeroCapitolo++;
        $this->fileCorrente = sprintf('capitolo-%03d.xhtml', $this->numeroCapitolo);

        $percorso = (string) tempnam(sys_get_temp_dir(), 'epub');
        $f = fopen($percorso, 'w');
        if ($f === false) {
            throw new \RuntimeException('Non riesco a scrivere il capitolo.');
        }
        $this->f = $f;
        $this->capitoli[] = ['file' => $this->fileCorrente, 'percorso' => $percorso];

        $this->elencoAperto = null;
        $this->scrivi($this->apertura($titolo !== '' ? $titolo : 'Capitolo ' . $this->numeroCapitolo));
        $this->qualcosaScritto = false;
    }

    private function chiudiElenco(): void
    {
        if ($this->elencoAperto !== null) {
            $this->scrivi("</{$this->elencoAperto}>\n");
            $this->elencoAperto = null;
        }
    }

    private function chiudiCapitolo(): void
    {
        if ($this->f === null) {
            return;
        }
        $this->chiudiElenco();
        $this->scrivi("</body>\n</html>\n");
        fclose($this->f);
        $this->f = null;
    }

    private function scrivi(string $testo): void
    {
        if ($this->f !== null && $testo !== '') {
            fwrite($this->f, $testo);
        }
    }

    private function annotaNellIndice(Blocco $blocco): void
    {
        $this->ancore++;
        $ancora = 't' . $this->ancore;

        $this->indice[] = [
            'file'    => $this->fileCorrente,
            'titolo'  => $blocco->nudo(),
            'ancora'  => $ancora,
            'livello' => $blocco->livello,
            'id'      => 'nav' . $this->ancore,
        ];

        $this->scrivi(sprintf(
            "<h%1\$d id=\"%2\$s\">%3\$s</h%1\$d>\n",
            $blocco->livello,
            $ancora,
            $this->inLinea($blocco->testi)
        ));
    }

    // ── resa dei blocchi ─────────────────────────────────────────────────────

    private function rendi(Blocco $blocco): string
    {
        return match ($blocco->tipo) {
            Blocco::TITOLO    => sprintf("<h%1\$d>%2\$s</h%1\$d>\n", $blocco->livello, $this->inLinea($blocco->testi)),
            Blocco::ELENCO    => $this->voceElenco($blocco),
            Blocco::CITAZIONE => '<blockquote><p>' . $this->inLinea($blocco->testi) . "</p></blockquote>\n",
            Blocco::CODICE    => '<pre><code>' . $this->e($blocco->nudo()) . "</code></pre>\n",
            Blocco::TABELLA   => $this->tabella($blocco),
            Blocco::IMMAGINE  => $this->immagine($blocco),
            Blocco::RIGA      => "<hr/>\n",
            default           => '<p>' . $this->inLinea($blocco->testi) . "</p>\n",
        };
    }

    /**
     * Le voci di elenco.
     *
     * Il modello tiene ogni voce per sé, l'XHTML vuole una lista che le
     * racchiuda: si apre alla prima e si chiude quando arriva altro. Lo stato
     * sta qui perché è l'unico posto che vede la sequenza.
     */
    private function voceElenco(Blocco $blocco): string
    {
        $tag = $blocco->ordinato ? 'ol' : 'ul';

        $fuori = '';
        if ($this->elencoAperto !== $tag) {
            $fuori .= $this->elencoAperto !== null ? "</{$this->elencoAperto}>\n" : '';
            $fuori .= "<{$tag}>\n";
            $this->elencoAperto = $tag;
        }

        return $fuori . '<li' . ($blocco->livello > 0 ? ' class="rientro"' : '') . '>'
             . $this->inLinea($blocco->testi) . "</li>\n";
    }

    private function tabella(Blocco $blocco): string
    {
        if ($blocco->righe === []) {
            return '';
        }

        $xhtml = "<table>\n<thead>\n<tr>";
        foreach ($blocco->righe[0] as $cella) {
            $xhtml .= '<th>' . $this->inLinea($cella) . '</th>';
        }
        $xhtml .= "</tr>\n</thead>\n<tbody>\n";

        foreach (array_slice($blocco->righe, 1) as $riga) {
            $xhtml .= '<tr>';
            foreach ($riga as $cella) {
                $xhtml .= '<td>' . $this->inLinea($cella) . '</td>';
            }
            $xhtml .= "</tr>\n";
        }

        return $xhtml . "</tbody>\n</table>\n";
    }

    private function immagine(Blocco $blocco): string
    {
        $percorso = $this->documento?->immagini()[$blocco->extra] ?? null;
        if ($percorso === null || !is_file($percorso)) {
            return '<p class="mancante">[immagine non disponibile: '
                 . $this->e($blocco->alt !== '' ? $blocco->alt : basename($blocco->extra)) . "]</p>\n";
        }

        return '<div class="figura"><img src="../immagini/' . $this->e(basename($percorso))
             . '" alt="' . $this->e($blocco->alt) . "\"/></div>\n";
    }

    /** @param list<Testo> $testi */
    private function inLinea(array $testi): string
    {
        $xhtml = '';
        foreach ($testi as $testo) {
            $pezzo = $this->e($testo->testo);

            if ($testo->codice) {
                $pezzo = '<code>' . $pezzo . '</code>';
            }
            if ($testo->grassetto) {
                $pezzo = '<strong>' . $pezzo . '</strong>';
            }
            if ($testo->corsivo) {
                $pezzo = '<em>' . $pezzo . '</em>';
            }
            if ($testo->collegamento !== null && $testo->collegamento !== '') {
                $pezzo = '<a href="' . $this->e($testo->collegamento) . '">' . $pezzo . '</a>';
            }
            $xhtml .= $pezzo;
        }

        return $xhtml;
    }

    /** In XHTML valgono solo cinque entità: tutto il resto va numerico o protetto. */
    private function e(string $testo): string
    {
        $pulito = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $testo) ?? $testo;

        return htmlspecialchars($pulito, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function apertura(string $titolo): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops" xml:lang="'
            . $this->e($this->lingua()) . '" lang="' . $this->e($this->lingua()) . "\">\n"
            . "<head>\n<title>" . $this->e($titolo) . "</title>\n"
            . '<link rel="stylesheet" type="text/css" href="../stili/stile.css"/>' . "\n"
            . "</head>\n<body>\n";
    }

    // ── assemblaggio ─────────────────────────────────────────────────────────

    private function assembla(): void
    {
        $zip = new \ZipArchive();
        @unlink($this->percorso);
        if ($zip->open($this->percorso, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Non riesco a creare {$this->percorso}");
        }

        // Primo e non compresso: è la firma che rende riconoscibile un EPUB.
        $zip->addFromString('mimetype', 'application/epub+zip');
        $zip->setCompressionName('mimetype', \ZipArchive::CM_STORE);

        $zip->addFromString('META-INF/container.xml', $this->container());
        $zip->addFromString('OEBPS/stili/stile.css', $this->stile());
        $zip->addFromString('OEBPS/testo/copertina.xhtml', $this->copertina());

        foreach ($this->capitoli as $capitolo) {
            if (is_file($capitolo['percorso'])) {
                $zip->addFile($capitolo['percorso'], 'OEBPS/testo/' . $capitolo['file']);
            }
        }

        $immagini = $this->documento?->immagini() ?? [];
        foreach ($immagini as $percorso) {
            if (is_file($percorso)) {
                $zip->addFile($percorso, 'OEBPS/immagini/' . basename($percorso));
            }
        }

        $zip->addFromString('OEBPS/nav.xhtml', $this->nav());
        $zip->addFromString('OEBPS/toc.ncx', $this->ncx());
        $zip->addFromString('OEBPS/content.opf', $this->opf($immagini));
        $zip->close();

        foreach ($this->capitoli as $capitolo) {
            @unlink($capitolo['percorso']);
        }
    }

    private function container(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container">' . "\n"
            . '<rootfiles><rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/></rootfiles>' . "\n"
            . '</container>';
    }

    /** @param array<string,string> $immagini */
    private function opf(array $immagini): string
    {
        $identificativo = 'urn:uuid:' . $this->uuid();
        $modificato     = gmdate('Y-m-d\TH:i:s\Z');

        $manifest = '<item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>'
            . '<item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/>'
            . '<item id="stile" href="stili/stile.css" media-type="text/css"/>'
            . '<item id="copertina" href="testo/copertina.xhtml" media-type="application/xhtml+xml"/>';

        $spine = '<itemref idref="copertina"/>';

        foreach ($this->capitoli as $indice => $capitolo) {
            $id = 'cap' . ($indice + 1);
            $manifest .= '<item id="' . $id . '" href="testo/' . $capitolo['file'] . '" media-type="application/xhtml+xml"/>';
            $spine    .= '<itemref idref="' . $id . '"/>';
        }

        $copertinaImmagine = $this->immagineCopertina($immagini);
        $numero = 0;
        foreach ($immagini as $percorso) {
            $numero++;
            $nome = basename($percorso);
            $manifest .= '<item id="img' . $numero . '" href="immagini/' . $this->e($nome) . '" media-type="'
                . $this->tipoImmagine($nome) . '"'
                . ($percorso === $copertinaImmagine ? ' properties="cover-image"' : '') . '/>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="pub-id">' . "\n"
            . '<metadata xmlns:dc="http://purl.org/dc/elements/1.1/">'
            . '<dc:identifier id="pub-id">' . $identificativo . '</dc:identifier>'
            . '<dc:title>' . $this->e($this->titolo()) . '</dc:title>'
            . '<dc:language>' . $this->e($this->lingua()) . '</dc:language>'
            . ($this->autore() !== '' ? '<dc:creator>' . $this->e($this->autore()) . '</dc:creator>' : '')
            . '<meta property="dcterms:modified">' . $modificato . '</meta>'
            . '</metadata>' . "\n"
            . '<manifest>' . $manifest . '</manifest>' . "\n"
            . '<spine toc="ncx">' . $spine . '</spine>' . "\n"
            . '</package>';
    }

    /** L'indice che il lettore mostra nel menu: EPUB 3. */
    private function nav(): string
    {
        $voci = '';
        foreach ($this->indice as $voce) {
            $voci .= '<li><a href="testo/' . $voce['file'] . '#' . $voce['ancora'] . '">'
                   . $this->e($voce['titolo']) . '</a></li>' . "\n";
        }
        if ($voci === '') {
            $voci = '<li><a href="testo/' . ($this->capitoli[0]['file'] ?? 'capitolo-001.xhtml') . '">'
                  . $this->e($this->titolo()) . '</a></li>' . "\n";
        }

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops" xml:lang="'
            . $this->e($this->lingua()) . "\">\n"
            . "<head><title>Indice</title></head>\n<body>\n"
            . '<nav epub:type="toc" id="toc"><h1>Indice</h1>' . "\n<ol>\n" . $voci . "</ol>\n</nav>\n"
            . "</body>\n</html>";
    }

    /** Lo stesso indice in formato EPUB 2: i lettori vecchi leggono solo questo. */
    private function ncx(): string
    {
        $punti = '';
        $numero = 0;
        foreach ($this->indice as $voce) {
            $numero++;
            $punti .= '<navPoint id="' . $voce['id'] . '" playOrder="' . $numero . '">'
                . '<navLabel><text>' . $this->e($voce['titolo']) . '</text></navLabel>'
                . '<content src="testo/' . $voce['file'] . '#' . $voce['ancora'] . '"/>'
                . '</navPoint>' . "\n";
        }
        if ($punti === '') {
            $punti = '<navPoint id="nav1" playOrder="1"><navLabel><text>' . $this->e($this->titolo())
                . '</text></navLabel><content src="testo/' . ($this->capitoli[0]['file'] ?? 'capitolo-001.xhtml') . '"/></navPoint>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1">' . "\n"
            . '<head><meta name="dtb:uid" content="urn:uuid:' . $this->uuid() . '"/></head>' . "\n"
            . '<docTitle><text>' . $this->e($this->titolo()) . '</text></docTitle>' . "\n"
            . '<navMap>' . "\n" . $punti . '</navMap>' . "\n</ncx>";
    }

    /**
     * La copertina.
     *
     * Se il documento porta un'immagine la si usa; altrimenti se ne fa una
     * tipografica, con titolo e autore. Meglio una copertina sobria che
     * nessuna: molti lettori mostrano un rettangolo grigio senza.
     */
    private function copertina(): string
    {
        $immagini = $this->documento?->immagini() ?? [];
        $immagine = $this->immagineCopertina($immagini);

        $corpo = $immagine !== null
            ? '<div class="copertina-immagine"><img src="../immagini/' . $this->e(basename($immagine))
              . '" alt="' . $this->e($this->titolo()) . '"/></div>'
            : '<div class="copertina"><h1>' . $this->e($this->titolo()) . '</h1>'
              . ($this->autore() !== '' ? '<p class="autore">' . $this->e($this->autore()) . '</p>' : '')
              . '</div>';

        return $this->apertura($this->titolo()) . $corpo . "\n</body>\n</html>\n";
    }

    /** @param array<string,string> $immagini */
    private function immagineCopertina(array $immagini): ?string
    {
        if (empty($this->regole['epub_copertina']) || $immagini === []) {
            return null;
        }

        return (string) reset($immagini);
    }

    private function stile(): string
    {
        return <<<'CSS'
            body { font-family: Georgia, "Times New Roman", serif; line-height: 1.55; margin: 0 5%; }
            h1, h2, h3, h4, h5, h6 { font-family: Helvetica, Arial, sans-serif; line-height: 1.2; margin: 1.4em 0 .5em; }
            h1 { font-size: 1.7em; }
            h2 { font-size: 1.35em; }
            h3 { font-size: 1.15em; }
            p { margin: 0 0 .7em; text-align: justify; }
            blockquote { margin: 1em 1.5em; font-style: italic; color: #444; border-left: 3px solid #ccc; padding-left: .8em; }
            pre { font-family: "Courier New", monospace; font-size: .85em; background: #f4f4f4; padding: .6em; white-space: pre-wrap; }
            code { font-family: "Courier New", monospace; font-size: .9em; }
            table { width: 100%; border-collapse: collapse; margin: 1em 0; font-size: .9em; }
            th, td { border: 1px solid #bbb; padding: .3em .5em; text-align: left; }
            th { background: #f0f0f0; }
            hr { border: none; border-top: 1px solid #ccc; margin: 1.5em 0; }
            li.rientro { margin-left: 1.2em; }
            .figura { text-align: center; margin: 1em 0; }
            .figura img { max-width: 100%; }
            .mancante { color: #888; font-style: italic; }
            .copertina { text-align: center; margin-top: 30%; }
            .copertina h1 { font-size: 2.2em; border-bottom: 2px solid #333; padding-bottom: .4em; display: inline-block; }
            .copertina .autore { font-size: 1.1em; color: #555; margin-top: 1.5em; }
            .copertina-immagine { text-align: center; margin: 0; padding: 0; }
            .copertina-immagine img { max-width: 100%; max-height: 100%; }
            CSS;
    }

    private function titolo(): string
    {
        $dichiarato = trim((string) ($this->regole['epub_titolo'] ?? ''));
        if ($dichiarato !== '') {
            return $dichiarato;
        }
        $daFile = pathinfo((string) ($this->regole['nome_originale'] ?? ''), PATHINFO_FILENAME);

        return $daFile !== '' ? $daFile : 'Documento';
    }

    private function autore(): string
    {
        return trim((string) ($this->regole['epub_autore'] ?? ''));
    }

    private function lingua(): string
    {
        $lingua = trim((string) ($this->regole['epub_lingua'] ?? 'it'));

        return preg_match('~^[a-z]{2}(-[A-Za-z]{2,4})?$~', $lingua) === 1 ? $lingua : 'it';
    }

    private function uuid(): string
    {
        static $uuid = null;
        if ($uuid !== null) {
            return $uuid;
        }
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));

        return $uuid;
    }

    private function tipoImmagine(string $nome): string
    {
        return match (strtolower(pathinfo($nome, PATHINFO_EXTENSION))) {
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'svg'  => 'image/svg+xml',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }
}
