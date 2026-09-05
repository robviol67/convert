<?php
declare(strict_types=1);

namespace Vblite\Convert\Conversioni\Documenti;

/**
 * Un pezzo di testo con i suoi tratti.
 *
 * I formati non concordano su quasi niente, ma su questo sì: dentro un
 * paragrafo il testo cambia aspetto a tratti. Qui un tratto è un pezzo con
 * gli stessi attributi, e ogni lettore lo produce, ogni scrittore lo rende
 * come sa.
 */
final class Testo
{
    public function __construct(
        public readonly string $testo,
        public readonly bool $grassetto = false,
        public readonly bool $corsivo = false,
        public readonly bool $codice = false,
        public readonly ?string $collegamento = null,
    ) {
    }

    /** Stessi tratti? Serve a ricucire i tratti spezzati inutilmente. */
    public function stessiTratti(self $altro): bool
    {
        return $this->grassetto === $altro->grassetto
            && $this->corsivo === $altro->corsivo
            && $this->codice === $altro->codice
            && $this->collegamento === $altro->collegamento;
    }

    public function con(string $testo): self
    {
        return new self($testo, $this->grassetto, $this->corsivo, $this->codice, $this->collegamento);
    }

    /**
     * I lettori producono spesso un tratto per ogni frammento del formato
     * d'origine: Word ne apre uno nuovo anche solo per un controllo ortografico.
     * Unirli tiene il modello leggero e il Markdown pulito.
     *
     * @param list<self> $tratti
     * @return list<self>
     */
    public static function unisci(array $tratti): array
    {
        $uniti = [];
        foreach ($tratti as $tratto) {
            if ($tratto->testo === '') {
                continue;
            }
            $ultimo = $uniti === [] ? null : $uniti[count($uniti) - 1];
            if ($ultimo !== null && $ultimo->stessiTratti($tratto)) {
                $uniti[count($uniti) - 1] = $ultimo->con($ultimo->testo . $tratto->testo);
                continue;
            }
            $uniti[] = $tratto;
        }

        return $uniti;
    }

    /** @param list<self> $tratti */
    public static function nudo(array $tratti): string
    {
        return implode('', array_map(static fn(self $t): string => $t->testo, $tratti));
    }
}
