<?php
declare(strict_types=1);

namespace Vblite\Convert\Conversioni\Documenti;

/**
 * Un blocco di documento: il minimo comune denominatore fra Markdown, Word,
 * RTF, PDF e testo semplice.
 *
 * Non è un modello completo di documento — non lo sarebbe mai, i formati
 * divergono troppo. È l'insieme delle cose che sopravvivono al passaggio da
 * uno all'altro senza inventare niente: titoli, paragrafi, elenchi, citazioni,
 * codice, tabelle, immagini, righe orizzontali.
 */
final class Blocco
{
    public const TITOLO    = 'titolo';
    public const PARAGRAFO = 'paragrafo';
    public const ELENCO    = 'elenco';
    public const CITAZIONE = 'citazione';
    public const CODICE    = 'codice';
    public const TABELLA   = 'tabella';
    public const IMMAGINE  = 'immagine';
    public const RIGA      = 'riga';

    /**
     * @param list<Testo>              $testi   contenuto in linea (non per tabella e immagine)
     * @param int                      $livello titolo: 1-6; elenco: profondità di rientro, da 0
     * @param bool                     $ordinato elenco numerato invece che puntato
     * @param list<list<list<Testo>>>  $righe   tabella: righe → celle → tratti
     * @param string                   $extra   codice: linguaggio; immagine: percorso relativo
     * @param string                   $alt     immagine: testo alternativo
     */
    public function __construct(
        public readonly string $tipo,
        public readonly array $testi = [],
        public readonly int $livello = 0,
        public readonly bool $ordinato = false,
        public readonly array $righe = [],
        public readonly string $extra = '',
        public readonly string $alt = '',
    ) {
    }

    /** @param list<Testo> $testi */
    public static function titolo(int $livello, array $testi): self
    {
        return new self(self::TITOLO, Testo::unisci($testi), max(1, min(6, $livello)));
    }

    /** @param list<Testo> $testi */
    public static function paragrafo(array $testi): self
    {
        return new self(self::PARAGRAFO, Testo::unisci($testi));
    }

    /** @param list<Testo> $testi */
    public static function elenco(array $testi, bool $ordinato = false, int $livello = 0): self
    {
        return new self(self::ELENCO, Testo::unisci($testi), max(0, $livello), $ordinato);
    }

    /** @param list<Testo> $testi */
    public static function citazione(array $testi): self
    {
        return new self(self::CITAZIONE, Testo::unisci($testi));
    }

    public static function codice(string $testo, string $linguaggio = ''): self
    {
        return new self(self::CODICE, [new Testo($testo)], 0, false, [], $linguaggio);
    }

    /** @param list<list<list<Testo>>> $righe */
    public static function tabella(array $righe): self
    {
        return new self(self::TABELLA, [], 0, false, $righe);
    }

    public static function immagine(string $percorso, string $alt = ''): self
    {
        return new self(self::IMMAGINE, [], 0, false, [], $percorso, $alt);
    }

    public static function riga(): self
    {
        return new self(self::RIGA);
    }

    public function vuoto(): bool
    {
        return match ($this->tipo) {
            self::RIGA, self::IMMAGINE => false,
            self::TABELLA => $this->righe === [],
            default       => trim(Testo::nudo($this->testi)) === '',
        };
    }

    public function nudo(): string
    {
        return Testo::nudo($this->testi);
    }
}
