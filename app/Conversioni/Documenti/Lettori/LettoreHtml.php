<?php
declare(strict_types=1);

namespace Vblite\Convert\Conversioni\Documenti\Lettori;

use Vblite\Convert\Conversioni\Documenti\Blocco;
use Vblite\Convert\Conversioni\Documenti\Documento;
use Vblite\Convert\Conversioni\Documenti\Testo;

/**
 * HTML e XHTML → modello.
 *
 * Si legge con un automa sui tag invece che con DOMDocument, per la ragione di
 * sempre: un albero DOM di una pagina da qualche megabyte ne occupa dieci
 * volte tanti, e qui il tetto è ~20 MB. L'automa tiene una pila degli elementi
 * aperti e accumula il testo, quindi la memoria dipende dalla profondità
 * dell'annidamento, non dalla lunghezza della pagina.
 *
 * Non è un motore di rendering e non prova a esserlo: riconosce gli elementi
 * che il modello sa rappresentare e butta il resto. Un <div> non ha un
 * equivalente in un documento, e fingere che ne abbia uno produrrebbe righe
 * vuote a caso.
 */
final class LettoreHtml implements Lettore
{
    /** Elementi il cui contenuto non è testo del documento. */
    private const DA_SALTARE = ['script', 'style', 'head', 'noscript', 'svg', 'template', 'iframe'];

    /** Elementi che chiudono un paragrafo e ne cominciano un altro. */
    private const BLOCCHI = [
        'p', 'div', 'section', 'article', 'header', 'footer', 'main', 'aside',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'blockquote', 'pre',
        'tr', 'td', 'th', 'hr', 'br', 'figcaption', 'dt', 'dd',
    ];

    public static function estensioni(): array
    {
        return ['html', 'htm', 'xhtml'];
    }

    public static function nome(): string
    {
        return 'HTML';
    }

    public function leggi(string $percorso, string $cartellaMedia, ?Documento $documento = null): Documento
    {
        $documento ??= new Documento();

        $grezzo = file_get_contents($percorso);
        if ($grezzo === false) {
            throw new \RuntimeException("Non riesco a leggere {$percorso}");
        }

        $this->analizza($this->inUtf8($grezzo), $documento);
        unset($grezzo);

        $documento->concludi();

        return $documento;
    }

    /** Legge un frammento già in memoria: serve ai capitoli di un EPUB. */
    public function daStringa(string $html, Documento $documento): void
    {
        $this->analizza($html, $documento);
    }

    /**
     * L'automa.
     *
     * Scorre il sorgente carattere per carattere distinguendo testo e tag, e
     * tiene una pila di stati per grassetto, corsivo, codice e collegamenti —
     * che possono annidarsi in qualunque modo.
     */
    private function analizza(string $html, Documento $documento): void
    {
        $lunghezza = strlen($html);

        $tratti   = [];        // i tratti del blocco in corso
        $corrente = '';        // il testo del tratto in corso
        $stato    = ['b' => false, 'i' => false, 'code' => false, 'link' => null];
        $pila     = [];        // stati da ripristinare alla chiusura dei tag

        $tipo      = Blocco::PARAGRAFO;
        $livello   = 0;
        $ordinato  = false;
        $rientro   = 0;
        $liste     = [];       // pila delle liste aperte: 'ul' o 'ol'
        $inPre     = false;
        $saltaFino = null;     // elemento di cui si sta buttando il contenuto

        // Le tabelle si accumulano a parte: il modello le vuole intere.
        $tabella   = null;
        $riga      = null;
        $cella     = null;

        $chiudiTratto = static function () use (&$corrente, &$tratti, &$stato, &$inPre): void {
            if ($corrente === '') {
                return;
            }
            // Le entità si sciolgono qui, sul testo già separato dai tag. Farlo
            // prima sul sorgente intero trasformerebbe «&lt;» in un vero segno
            // di minore, e l'automa lo prenderebbe per l'inizio di un tag.
            $testo = html_entity_decode($corrente, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (!$inPre) {
                $testo = (string) preg_replace('~\s+~u', ' ', $testo);
            }
            if (trim($testo) !== '' || str_contains($testo, ' ')) {
                $tratti[] = new Testo($testo, $stato['b'], $stato['i'], $stato['code'], $stato['link']);
            }
            $corrente = '';
        };

        $chiudiBlocco = function () use (
            &$tratti, &$tipo, &$livello, &$ordinato, &$rientro, &$cella, $documento, $chiudiTratto
        ): void {
            $chiudiTratto();
            if ($tratti === []) {
                $tipo = Blocco::PARAGRAFO;

                return;
            }

            // Dentro una cella il testo non è un blocco: appartiene alla tabella.
            if ($cella !== null) {
                foreach ($tratti as $tratto) {
                    $cella[] = $tratto;
                }
                $tratti = [];

                return;
            }

            $documento->aggiungi(match ($tipo) {
                Blocco::TITOLO    => Blocco::titolo($livello, $tratti),
                Blocco::ELENCO    => Blocco::elenco($tratti, $ordinato, $rientro),
                Blocco::CITAZIONE => Blocco::citazione($tratti),
                Blocco::CODICE    => Blocco::codice(Testo::nudo($tratti)),
                default           => Blocco::paragrafo($tratti),
            });
            $tratti = [];
            $tipo   = Blocco::PARAGRAFO;
        };

        for ($i = 0; $i < $lunghezza; $i++) {
            if ($html[$i] !== '<') {
                if ($saltaFino === null) {
                    $corrente .= $html[$i];
                }
                continue;
            }

            // Commento
            if (substr($html, $i, 4) === '<!--') {
                $fine = strpos($html, '-->', $i);
                $i = $fine === false ? $lunghezza : $fine + 2;
                continue;
            }
            // Dichiarazione o istruzione
            if (($html[$i + 1] ?? '') === '!' || ($html[$i + 1] ?? '') === '?') {
                $fine = strpos($html, '>', $i);
                $i = $fine === false ? $lunghezza : $fine;
                continue;
            }

            if (preg_match('~<(/?)([a-zA-Z][a-zA-Z0-9:-]*)([^>]*)>~A', $html, $m, 0, $i) !== 1) {
                $corrente .= '<';
                continue;
            }

            $chiusura   = $m[1] === '/';
            $nome       = strtolower($m[2]);
            $attributi  = $m[3];
            $autoChiuso = str_ends_with(rtrim($attributi), '/');
            $i         += strlen($m[0]) - 1;

            // Contenuto da buttare
            if ($saltaFino !== null) {
                if ($chiusura && $nome === $saltaFino) {
                    $saltaFino = null;
                }
                continue;
            }
            if (!$chiusura && in_array($nome, self::DA_SALTARE, true)) {
                $saltaFino = $nome;
                continue;
            }

            // Qualunque tag interrompe il tratto in corso: <strong> apre un
            // pezzo con altri attributi, e il testo prima e dopo non può
            // finire nello stesso tratto.
            $chiudiTratto();

            // I blocchi chiudono anche il blocco in corso
            if (in_array($nome, self::BLOCCHI, true) || in_array($nome, ['ul', 'ol', 'table', 'thead', 'tbody'], true)) {
                $chiudiBlocco();
            }

            if ($chiusura) {
                $stato = array_pop($pila) ?? $stato;

                switch ($nome) {
                    case 'pre':  $inPre = false; break;
                    case 'ul':
                    case 'ol':
                        array_pop($liste);
                        $rientro = max(0, count($liste) - 1);
                        break;
                    case 'td':
                    case 'th':
                        if ($riga !== null && $cella !== null) {
                            $riga[] = Testo::unisci($cella);
                        }
                        $cella = null;
                        break;
                    case 'tr':
                        if ($tabella !== null && $riga !== null && $riga !== []) {
                            $tabella[] = $riga;
                        }
                        $riga = null;
                        break;
                    case 'table':
                        if ($tabella !== null && $tabella !== []) {
                            $documento->aggiungi(Blocco::tabella($tabella));
                        }
                        $tabella = null;
                        break;
                }
                continue;
            }

            // Apertura
            $pila[] = $stato;

            switch ($nome) {
                case 'h1': case 'h2': case 'h3': case 'h4': case 'h5': case 'h6':
                    $tipo    = Blocco::TITOLO;
                    $livello = (int) substr($nome, 1);
                    break;

                case 'b': case 'strong':
                    $stato['b'] = true;
                    break;

                case 'i': case 'em': case 'cite': case 'var':
                    $stato['i'] = true;
                    break;

                case 'code': case 'kbd': case 'samp': case 'tt':
                    $stato['code'] = true;
                    break;

                case 'pre':
                    $inPre = true;
                    $tipo  = Blocco::CODICE;
                    break;

                case 'blockquote':
                    $tipo = Blocco::CITAZIONE;
                    break;

                case 'a':
                    if (preg_match('~href\s*=\s*["\']?([^"\'\s>]+)~i', $attributi, $href) === 1) {
                        $stato['link'] = html_entity_decode($href[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    }
                    break;

                case 'ul': case 'ol':
                    $liste[] = $nome;
                    $rientro = max(0, count($liste) - 1);
                    break;

                case 'li':
                    $tipo     = Blocco::ELENCO;
                    $ordinato = (end($liste) ?: 'ul') === 'ol';
                    break;

                case 'hr':
                    $documento->aggiungi(Blocco::riga());
                    break;

                case 'br':
                    $corrente .= ' ';
                    break;

                case 'table':
                    $tabella = [];
                    break;

                case 'tr':
                    $riga = [];
                    break;

                case 'td': case 'th':
                    $cella = [];
                    break;

                case 'img':
                    // L'immagine è un blocco a sé: quello che la precede va
                    // chiuso prima, altrimenti esce dopo di lei.
                    $chiudiBlocco();
                    if (preg_match('~src\s*=\s*["\']?([^"\'\s>]+)~i', $attributi, $src) === 1) {
                        $alt = preg_match('~alt\s*=\s*["\']([^"\']*)~i', $attributi, $a) === 1 ? $a[1] : '';
                        $documento->aggiungi(Blocco::immagine(
                            html_entity_decode($src[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                            html_entity_decode($alt, ENT_QUOTES | ENT_HTML5, 'UTF-8')
                        ));
                    }
                    break;
            }

            // Un tag che si chiude da solo non lascia niente sulla pila.
            if ($autoChiuso || in_array($nome, ['br', 'hr', 'img', 'meta', 'link', 'input'], true)) {
                array_pop($pila);
            }
        }

        $chiudiBlocco();
    }

    /** Le pagine dichiarano la codifica in vari modi, e a volte mentono. */
    private function inUtf8(string $html): string
    {
        if (preg_match('~charset\s*=\s*["\']?\s*([a-zA-Z0-9-]+)~i', substr($html, 0, 2048), $m) === 1) {
            $dichiarata = strtoupper($m[1]);
            if ($dichiarata !== 'UTF-8' && $dichiarata !== 'UTF8') {
                $convertito = @mb_convert_encoding($html, 'UTF-8', $dichiarata);
                if ($convertito !== false) {
                    return $convertito;
                }
            }
        }
        if (!mb_check_encoding($html, 'UTF-8')) {
            return (string) mb_convert_encoding($html, 'UTF-8', 'Windows-1252');
        }

        return $html;
    }
}
