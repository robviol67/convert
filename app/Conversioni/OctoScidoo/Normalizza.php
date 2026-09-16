<?php
declare(strict_types=1);

namespace Vblite\Convert\Conversioni\OctoScidoo;

/** Conversioni di formato fra il testo della stampa Octorate e i tipi del tracciato Scidoo. */
final class Normalizza
{
    /** Epoca dei seriali data di Excel. */
    private const EPOCA = '1899-12-30';

    /** «4.256» → 4256. Il punto e' separatore delle migliaia, non un decimale. */
    public static function id(string $npren): ?int
    {
        $cifre = preg_replace('~\D~', '', $npren);

        return $cifre === '' ? null : (int) $cifre;
    }

    /**
     * «16/01/2024» o «26/11/23» → seriale Excel.
     * L'anno a due cifre si completa sul secolo corrente (la stampa copre solo date recenti).
     */
    public static function dataSeriale(string $testo): ?float
    {
        $testo = trim($testo);
        if ($testo === '' || !preg_match('~^(\d{1,2})/(\d{1,2})/(\d{2}|\d{4})$~', $testo, $m)) {
            return null;
        }

        [$giorno, $mese, $anno] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        if ($anno < 100) {
            $anno += $anno >= 70 ? 1900 : 2000;
        }
        if (!checkdate($mese, $giorno, $anno)) {
            return null;
        }

        $data  = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $anno, $mese, $giorno), new \DateTimeZone('UTC'));
        $epoca = new \DateTimeImmutable(self::EPOCA, new \DateTimeZone('UTC'));

        return (float) $epoca->diff($data)->days;
    }

    /** Come dataSeriale ma con la parte oraria, per la Data Inserimento. */
    public static function dataOraSeriale(string $data, string $ora): ?float
    {
        $seriale = self::dataSeriale($data);
        if ($seriale === null || !preg_match('~^(\d{1,2}):(\d{2}):(\d{2})$~', trim($ora), $m)) {
            return $seriale;
        }

        return $seriale + ((int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3]) / 86400;
    }

    /** «1.490,20» → 1490.20 ; stringa vuota → null (mai 0, che in Scidoo e' un importo). */
    public static function importo(string $testo): ?float
    {
        $testo = trim($testo);
        if ($testo === '') {
            return null;
        }
        $pulito = str_replace([' ', '.', "\u{a0}"], '', $testo);
        $pulito = str_replace(',', '.', $pulito);
        if (!is_numeric($pulito)) {
            return null;
        }

        return (float) $pulito;
    }

    /**
     * Distingue fisso e mobile, anche quando la stampa ne incolla due.
     *
     * In Italia i mobili iniziano per 3; i prefissi internazionali si tengono
     * come sono e finiscono su Telefono salvo il +39 3…
     *
     * La colonna Telefono della stampa è stretta, e taglia. Ne escono tre casi:
     *
     * - un numero intero, o due incollati — «0341 +39 329 592 5969». Un «+»
     *   dopo l'inizio apre sempre un numero nuovo, e lì si separano;
     * - un numero tagliato ma riconoscibile — «349 674»: si importa com'è e si
     *   segnala, perché chi ha Octorate sotto mano può completarlo;
     * - un moncone — «0341», «06», «339»: sotto le cinque cifre non è un numero,
     *   è il prefisso rimasto dopo il taglio. Non si importa: scrivere «06» nel
     *   telefono di un cliente è peggio che lasciarlo vuoto. Resta in 'scartato'.
     *
     * @return array{fisso:?string,mobile:?string,troncato:bool,scartato:?string}
     */
    public static function telefono(string $testo): array
    {
        $testo = trim(preg_replace('~\s+~u', ' ', $testo) ?? '');
        if ($testo === '') {
            return ['fisso' => null, 'mobile' => null, 'troncato' => false, 'scartato' => null];
        }

        $fisso    = null;
        $mobile   = null;
        $troncato = false;
        $scartati = [];

        foreach (preg_split('~\s+(?=\+)|\s*/\s*~u', $testo) ?: [] as $pezzo) {
            $pezzo = trim($pezzo);
            $cifre = preg_replace('~\D~', '', $pezzo) ?? '';
            if ($cifre === '') {
                continue;
            }
            if (strlen($cifre) < 5) {
                $scartati[] = $pezzo;
                continue;
            }

            $nazionale = preg_replace('~^(?:\+|00)39~', '', str_replace(' ', '', $pezzo)) ?? '';
            $soloCifre = preg_replace('~\D~', '', $nazionale) ?? '';
            $eMobile   = str_starts_with($soloCifre, '3') && !str_starts_with($cifre, '00')
                || preg_match('~^(?:\+|00)39\s?3~', $pezzo) === 1;

            if ($eMobile) {
                $mobile ??= $pezzo;
            } else {
                $fisso ??= $pezzo;
            }

            // Un numero italiano completo ha almeno 9 cifre (fissi corti compresi).
            if (strlen($cifre) < 9) {
                $troncato = true;
            }
        }

        return [
            'fisso'    => $fisso,
            'mobile'   => $mobile,
            'troncato' => $troncato,
            'scartato' => $scartati === [] ? null : implode(' ', $scartati),
        ];
    }

    /**
     * Ragioni sociali e sigle OTA come le scrive Scidoo nel file di esempio
     * («Booking.com»), lasciando intatte le aziende dirette.
     */
    public static function agenzia(string $pagante, string $prenotante): string
    {
        $valore = trim($pagante) !== '' ? trim($pagante) : trim($prenotante);
        if ($valore === '') {
            return '';
        }

        $noti = [
            'BOOKING.COM'   => 'Booking.com',
            'QUICK BOOKING' => 'Quick Booking',
            'EXPEDIA'       => 'Expedia',
            'AIRBNB'        => 'Airbnb',
        ];
        $chiave = mb_strtoupper($valore);
        foreach ($noti as $pattern => $normalizzato) {
            if ($chiave === $pattern || str_starts_with($chiave, $pattern)) {
                return $normalizzato;
            }
        }

        return $valore;
    }

    /**
     * I commenti OTA arrivano con le entita' HTML escapate due volte, spezzati
     * su piu' righe di stampa. Si riuniscono, si de-escapano e si tolgono i tag.
     */
    public static function commento(string $grezzo): string
    {
        if (trim($grezzo) === '') {
            return '';
        }

        // Le righe sono spezzate a larghezza colonna: si ricuce senza inserire spazi
        // dove la riga precedente finiva gia' con uno.
        $righe  = array_map(static fn(string $r): string => rtrim($r), explode("\n", $grezzo));
        $testo  = '';
        foreach ($righe as $riga) {
            if ($testo === '') {
                $testo = $riga;
                continue;
            }
            $testo .= (str_ends_with($testo, ' ') || str_starts_with($riga, ' ')) ? '' : ' ';
            $testo .= ltrim($riga);
        }

        $testo = html_entity_decode($testo, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $testo = html_entity_decode($testo, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $testo = preg_replace('~<br\s*/?>~i', ' ', $testo) ?? $testo;
        $testo = strip_tags($testo);
        $testo = preg_replace('~\s+~u', ' ', $testo) ?? $testo;

        return trim($testo);
    }

    /**
     * Deduce la categoria camera dal blocco fra parentesi quadre dei commenti OTA
     * («[ Camera Matrimoniale - Single Use - customers: 1 … ]»). Deterministico:
     * si limita a leggere cio' che c'e' scritto, non inventa.
     */
    public static function categoriaCamera(string $commentoPulito): ?string
    {
        if (!preg_match('~\[\s*([^\]\-]{3,60}?)\s*(?:-|\])~u', $commentoPulito, $m)) {
            return null;
        }
        $candidato = trim($m[1]);
        if (!preg_match('~^(camera|suite|appartamento|doppia|singola|matrimoniale|tripla|quadrupla)~iu', $candidato)) {
            return null;
        }

        return $candidato;
    }
}
