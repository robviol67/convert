<?php
declare(strict_types=1);

namespace Vblite\Convert\Conversioni\Documenti\Lettori;

use Vblite\Convert\Conversioni\Documenti\Documento;

/**
 * EPUB → modello.
 *
 * Un EPUB è uno zip di documenti XHTML più un file, content.opf, che dice
 * quali sono e in che ordine leggerli. L'ordine sta nello «spine» e non
 * nell'elenco dei file: seguirlo è l'unico modo di ricomporre il libro come
 * l'autore lo ha impaginato — i nomi dei file non dicono niente, e uno zip non
 * garantisce alcun ordine.
 *
 * Ogni capitolo si legge con il lettore HTML, che è già scritto: un capitolo
 * EPUB è XHTML, e un capitolo alla volta la memoria resta bassa anche su un
 * libro intero.
 */
final class LettoreEpub implements Lettore
{
    public static function estensioni(): array
    {
        return ['epub'];
    }

    public static function nome(): string
    {
        return 'EPUB';
    }

    public function leggi(string $percorso, string $cartellaMedia, ?Documento $documento = null): Documento
    {
        $documento ??= new Documento();

        $zip = new \ZipArchive();
        if ($zip->open($percorso) !== true) {
            throw new \RuntimeException('Il file non è un EPUB leggibile.');
        }

        $opf = $this->percorsoOpf($zip);
        if ($opf === null) {
            $zip->close();
            throw new \RuntimeException('Manca content.opf: non è un EPUB.');
        }

        $contenuto = (string) $zip->getFromName($opf);
        $base      = trim(dirname($opf), '.');
        $base      = $base === '' ? '' : $base . '/';

        $this->estraiImmagini($zip, $contenuto, $base, $cartellaMedia, $documento);

        $lettoreHtml = new LettoreHtml();
        $capitoli    = $this->capitoli($contenuto);

        if ($capitoli === []) {
            $zip->close();
            throw new \RuntimeException('L\'EPUB non dichiara nessun capitolo da leggere.');
        }

        foreach ($capitoli as $capitolo) {
            $xhtml = $zip->getFromName($base . $capitolo);
            if ($xhtml === false) {
                // Un capitolo dichiarato ma assente: lo si dice invece di
                // consegnare un libro con un buco in mezzo.
                $documento->perdita(
                    'Capitolo dichiarato ma assente nel file',
                    $capitolo . ': l\'EPUB lo elenca ma non lo contiene.'
                );
                continue;
            }

            $lettoreHtml->daStringa($this->soloCorpo($xhtml), $documento);
            unset($xhtml);
        }

        $zip->close();
        $documento->concludi();

        return $documento;
    }

    /** Il percorso del content.opf, dichiarato in META-INF/container.xml. */
    private function percorsoOpf(\ZipArchive $zip): ?string
    {
        $container = $zip->getFromName('META-INF/container.xml');
        if ($container !== false
            && preg_match('~<rootfile[^>]*full-path="([^"]+)"~i', $container, $m) === 1) {
            return $m[1];
        }

        // Qualche EPUB malfatto non ha il container: si cerca l'opf a mano.
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nome = (string) $zip->getNameIndex($i);
            if (str_ends_with(strtolower($nome), '.opf')) {
                return $nome;
            }
        }

        return null;
    }

    /**
     * I capitoli nell'ordine di lettura.
     *
     * Lo spine cita gli id del manifest, non i file: si costruisce prima la
     * mappa id → percorso e poi si segue l'ordine dichiarato.
     *
     * @return list<string>
     */
    private function capitoli(string $opf): array
    {
        $perId = [];
        if (preg_match_all('~<item\b([^>]*)/?>~i', $opf, $voci) > 0) {
            foreach ($voci[1] as $attributi) {
                if (preg_match('~\bid="([^"]+)"~i', $attributi, $id) === 1
                    && preg_match('~\bhref="([^"]+)"~i', $attributi, $href) === 1) {
                    $perId[$id[1]] = html_entity_decode($href[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
                }
            }
        }

        $capitoli = [];
        if (preg_match_all('~<itemref\b[^>]*\bidref="([^"]+)"~i', $opf, $riferimenti) > 0) {
            foreach ($riferimenti[1] as $id) {
                $file = $perId[$id] ?? null;
                if ($file !== null && preg_match('~\.x?html?$~i', $file) === 1) {
                    $capitoli[] = $file;
                }
            }
        }

        return $capitoli;
    }

    /**
     * Solo il corpo del capitolo.
     *
     * La testata porta titolo, fogli di stile e metadati: passarla al lettore
     * HTML farebbe comparire il titolo del file come se fosse testo del libro.
     */
    private function soloCorpo(string $xhtml): string
    {
        if (preg_match('~<body[^>]*>(.*)</body>~is', $xhtml, $m) === 1) {
            return $m[1];
        }

        return $xhtml;
    }

    private function estraiImmagini(
        \ZipArchive $zip,
        string $opf,
        string $base,
        string $cartellaMedia,
        Documento $documento
    ): void {
        if (preg_match_all('~<item\b[^>]*\bhref="([^"]+)"[^>]*\bmedia-type="image/[^"]+"~i', $opf, $trovate) === 0
            && preg_match_all('~<item\b[^>]*\bmedia-type="image/[^"]+"[^>]*\bhref="([^"]+)"~i', $opf, $trovate) === 0) {
            return;
        }

        if (!is_dir($cartellaMedia)) {
            @mkdir($cartellaMedia, 0770, true);
        }

        foreach (array_unique($trovate[1]) as $href) {
            $dentro = $zip->getFromName($base . $href);
            if ($dentro === false) {
                continue;
            }
            $nome         = $this->nomeSicuro(basename($href));
            $destinazione = $cartellaMedia . '/' . $nome;
            file_put_contents($destinazione, $dentro);
            // Il Markdown citerà media/nome; il lettore HTML cita l'href
            // originale, quindi si registrano entrambe le chiavi.
            $documento->registraImmagine('media/' . $nome, $destinazione);
            $documento->registraImmagine($href, $destinazione);
            unset($dentro);
        }
    }

    private function nomeSicuro(string $nome): string
    {
        return preg_replace('~[^A-Za-z0-9._-]~', '-', basename($nome)) ?? 'immagine';
    }
}
