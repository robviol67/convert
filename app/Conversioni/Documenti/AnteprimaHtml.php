<?php
declare(strict_types=1);

namespace Vblite\Convert\Conversioni\Documenti;

/**
 * I primi blocchi del documento convertito, resi in HTML per la schermata
 * «Pronto».
 *
 * Si rende il modello, non il file prodotto: il modello è esattamente quello
 * che ogni scrittore mette su carta, quindi l'anteprima vale per tutti i
 * formati in uscita — anche per quelli binari, che nel browser non si
 * potrebbero mostrare.
 *
 * Tutto viene protetto: il testo arriva da un file caricato da qualcuno, e in
 * una pagina dell'applicazione non deve poter diventare marcatura.
 */
final class AnteprimaHtml
{
    /** @param list<Blocco> $blocchi */
    public static function rendi(array $blocchi): string
    {
        $html = '';
        $elencoAperto = null;

        foreach ($blocchi as $blocco) {
            // Gli elenchi sono blocchi singoli nel modello ma una lista sola in
            // HTML: si apre alla prima voce e si chiude quando finiscono.
            $eElenco = $blocco->tipo === Blocco::ELENCO;
            $tag     = $blocco->ordinato ? 'ol' : 'ul';

            if ($eElenco && $elencoAperto !== $tag) {
                $html .= $elencoAperto !== null ? "</{$elencoAperto}>" : '';
                $html .= "<{$tag}>";
                $elencoAperto = $tag;
            } elseif (!$eElenco && $elencoAperto !== null) {
                $html .= "</{$elencoAperto}>";
                $elencoAperto = null;
            }

            $html .= self::blocco($blocco);
        }

        return $html . ($elencoAperto !== null ? "</{$elencoAperto}>" : '');
    }

    private static function blocco(Blocco $blocco): string
    {
        return match ($blocco->tipo) {
            Blocco::TITOLO => sprintf(
                '<h%1$d class="ap-t%1$d">%2$s</h%1$d>',
                $blocco->livello,
                self::inLinea($blocco->testi)
            ),
            Blocco::ELENCO => '<li' . ($blocco->livello > 0 ? ' class="ap-rientro"' : '') . '>'
                              . self::inLinea($blocco->testi) . '</li>',
            Blocco::CITAZIONE => '<blockquote>' . self::inLinea($blocco->testi) . '</blockquote>',
            Blocco::CODICE    => '<pre>' . self::e($blocco->nudo()) . '</pre>',
            Blocco::TABELLA   => self::tabella($blocco),
            Blocco::IMMAGINE  => '<p class="ap-immagine">' . self::e(
                $blocco->alt !== '' ? $blocco->alt : basename($blocco->extra)
            ) . '</p>',
            Blocco::RIGA      => '<hr>',
            default           => '<p>' . self::inLinea($blocco->testi) . '</p>',
        };
    }

    private static function tabella(Blocco $blocco): string
    {
        if ($blocco->righe === []) {
            return '';
        }

        $html = '<table class="table"><thead><tr>';
        foreach ($blocco->righe[0] as $cella) {
            $html .= '<th>' . self::inLinea($cella) . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach (array_slice($blocco->righe, 1) as $riga) {
            $html .= '<tr>';
            foreach ($riga as $cella) {
                $html .= '<td>' . self::inLinea($cella) . '</td>';
            }
            $html .= '</tr>';
        }

        return $html . '</tbody></table>';
    }

    /** @param list<Testo> $testi */
    private static function inLinea(array $testi): string
    {
        $html = '';
        foreach ($testi as $testo) {
            $pezzo = self::e($testo->testo);

            if ($testo->codice) {
                $pezzo = '<code>' . $pezzo . '</code>';
            }
            if ($testo->grassetto) {
                $pezzo = '<strong>' . $pezzo . '</strong>';
            }
            if ($testo->corsivo) {
                $pezzo = '<em>' . $pezzo . '</em>';
            }
            // Il collegamento si mostra ma non si segue: l'indirizzo viene da un
            // file altrui, e un'anteprima non deve invitare a cliccarlo.
            if ($testo->collegamento !== null && $testo->collegamento !== '') {
                $pezzo = '<span class="ap-link" title="' . self::e($testo->collegamento) . '">' . $pezzo . '</span>';
            }
            $html .= $pezzo;
        }

        return $html;
    }

    private static function e(string $testo): string
    {
        return htmlspecialchars($testo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
