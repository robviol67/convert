<?php
declare(strict_types=1);

namespace Vblite\Convert\Conversioni\OctoScidoo;

/**
 * Una delle stampe di Octorate che sappiamo leggere.
 *
 * Octorate stampa più elenchi, e due di questi portano gli stessi dati con
 * colonne diverse:
 *
 * - **Stampa clienti presenti**: una riga per ospite, tante righe per
 *   prenotazione. Gli ospiti si contano, e quanti siano bambini lo si può solo
 *   dedurre dai supplementi;
 * - **Stampa prenotazioni**: una riga per prenotazione. Adulti, bambini e
 *   neonati sono dichiarati in tre colonne loro, e ci sono in più telefono,
 *   e-mail, data d'inserimento e scadenza dell'opzione.
 *
 * Il metodo di lettura è lo stesso — le colonne si ricavano dalle coordinate
 * delle intestazioni, che si ripetono su ogni pagina — quindi quello che
 * cambia si dichiara qui e il Parser resta uno solo. In uscita il file è
 * sempre lo stesso: il tracciato d'importazione di Scidoo.
 */
final class Tracciato
{
    /**
     * @param string                $titolo   la stringa che identifica la stampa, a pagina 1
     * @param array<string,?string> $ancore   colonna → etichetta di testata (null: interpolata)
     * @param array<string,array{0:string,1:int|string,2?:string}> $campi
     *        campo → [colonna, sottoriga o 'tutte', 'num' per ricucire i numeri]
     * @param int  $righeTestata  quante righe impila la testata: sotto, cominciano i dati
     * @param bool $ospitiDichiarati adulti/bambini/neonati stanno in colonna, non si deducono
     */
    private function __construct(
        public readonly string $chiave,
        public readonly string $nome,
        public readonly string $titolo,
        public readonly array $ancore,
        public readonly array $campi,
        public readonly int $righeTestata,
        public readonly bool $ospitiDichiarati,
    ) {
    }

    /** @return list<self> in ordine di prova */
    public static function tutti(): array
    {
        return [self::clientiPresenti(), self::prenotazioni()];
    }

    /** Quale stampa è, guardando il testo della prima pagina. */
    public static function riconosci(string $testoPrimaPagina): ?self
    {
        foreach (self::tutti() as $tracciato) {
            if (str_contains($testoPrimaPagina, $tracciato->titolo)) {
                return $tracciato;
            }
        }

        return null;
    }

    /** I nomi delle stampe accettate, per i messaggi d'errore. */
    public static function nomi(): string
    {
        return implode(' e ', array_map(
            static fn(self $t): string => '«' . $t->titolo . '»',
            self::tutti()
        ));
    }

    /**
     * Le chiavi che il Raggruppatore legge sempre, vuote.
     *
     * Sopra ci si sovrappongono i campi del tracciato: quelli che una stampa
     * non ha restano vuoti invece di mancare, e il Raggruppatore non deve
     * sapere quale delle due sta leggendo.
     *
     * L'elenco è solo questo e non «tutti i campi possibili», che sarebbe
     * stato più comodo: una stampa di 201 pagine fa 1.174 righe, e sei chiavi
     * di troppo per riga sono mezzo megabyte su un tetto di venti.
     *
     * @return array<string,string>
     */
    public static function rigaVuota(): array
    {
        return array_fill_keys([
            'npren', 'data', 'ora', 'cognome', 'nome', 'telefono', 'email',
            'arrivo', 'partenza', 'camera', 'pax',
            'gruppo', 'agenzia_pagante', 'agenzia_prenotante', 'voucher',
            'trattamento', 'convenzione', 'data_opzione',
            'importo', 'tassa_sogg', 'sconto',
            'supplementi', 'commenti', 'caparre', 'acconti', 'pagina',
        ], '');
    }

    /**
     * La stampa storica: una riga per ospite.
     *
     * «Pax» è stampato in verticale (P / a / x), quindi non si può cercare come
     * etichetta: la sua banda viene interpolata fra Cam. e Gruppo.
     */
    private static function clientiPresenti(): self
    {
        return new self(
            'clienti_presenti',
            'Stampa clienti presenti',
            'Stampa clienti presenti',
            [
                'npren'       => 'N°pren.',
                'cognome'     => 'Cognome',
                'arrivo'      => 'Arrivo',
                'camera'      => 'Cam.',
                'pax'         => null,
                'gruppo'      => 'Gruppo',
                'trattamento' => 'Trattamento',
                'importo'     => 'Importo',
                'supplementi' => 'Supplementi',
                'commenti'    => 'Commenti',
                'caparre'     => 'Caparre',
            ],
            [
                'data'               => ['npren', 1],
                'ora'                => ['npren', 2],
                'arrivo'             => ['arrivo', 0],
                'partenza'           => ['arrivo', 1],
                'camera'             => ['camera', 0],
                'camere_tot'         => ['camera', 1],
                'pax'                => ['pax', 0],
                'gruppo'             => ['gruppo', 0],
                'agenzia_pagante'    => ['gruppo', 1],
                'agenzia_prenotante' => ['gruppo', 2],
                'voucher'            => ['gruppo', 3],
                'trattamento'        => ['trattamento', 0],
                'convenzione'        => ['trattamento', 1],
                'data_opzione'       => ['trattamento', 2],
                'importo'            => ['importo', 0, 'num'],
                'tassa_sogg'         => ['importo', 1, 'num'],
                'sconto'             => ['importo', 2, 'num'],
                'supplementi'        => ['supplementi', 'tutte'],
                'commenti'           => ['commenti', 'tutte'],
                'caparre'            => ['caparre', 0, 'num'],
                'acconti'            => ['caparre', 1, 'num'],
            ],
            4,
            false,
        );
    }

    /**
     * La stampa delle prenotazioni: una riga per prenotazione.
     *
     * L'intestazione del numero arriva spezzata in tre pezzi — «N», «°»,
     * «pren.» — quindi l'ancora è l'ultimo, che è anche quello che dà la
     * posizione giusta della colonna.
     */
    private static function prenotazioni(): self
    {
        return new self(
            'prenotazioni',
            'Stampa prenotazioni',
            'Stampa prenotazioni',
            [
                'npren'       => 'pren.',
                'cognome'     => 'Cognome',
                'arrivo'      => 'Arrivo',
                'camera'      => 'Cam.',
                'ospiti'      => 'A',
                'gruppo'      => 'Gruppo',
                'trattamento' => 'Trattamento',
                'importo'     => 'Importo',
                'supplementi' => 'Supplementi',
                'commenti'    => 'Note interne',
                'caparre'     => 'Caparre',
            ],
            [
                'data'               => ['npren', 1],
                'ora'                => ['npren', 2],
                'arrivo'             => ['arrivo', 0],
                'partenza'           => ['arrivo', 1],
                'camera'             => ['camera', 0],
                'adulti'             => ['ospiti', 0],
                'bambini'            => ['ospiti', 1],
                'neonati'            => ['ospiti', 2],
                'gruppo'             => ['gruppo', 0],
                'agenzia_pagante'    => ['gruppo', 1],
                'agenzia_prenotante' => ['gruppo', 2],
                'voucher'            => ['gruppo', 3],
                'trattamento'        => ['trattamento', 0],
                'convenzione'        => ['trattamento', 1],
                'data_opzione'       => ['trattamento', 2],
                'tipo_camera'        => ['trattamento', 3],
                'predisposizione'    => ['trattamento', 4],
                'importo'            => ['importo', 0, 'num'],
                'sconto_perc'        => ['importo', 1, 'num'],
                'sconto'             => ['importo', 2, 'num'],
                'supplementi'        => ['supplementi', 'tutte'],
                'commenti'           => ['commenti', 'tutte'],
                'caparre'            => ['caparre', 0, 'num'],
                'acconti'            => ['caparre', 1, 'num'],
            ],
            5,
            true,
        );
    }
}
