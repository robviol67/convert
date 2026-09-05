<?php
declare(strict_types=1);

namespace Vblite\Convert\Conversioni;

/**
 * Una tipologia di conversione. Aggiungerne una significa aggiungere una classe
 * che implementa questa interfaccia piu' il suo manifest: nessuna schermata cambia.
 */
interface Conversione
{
    /** Identificativo stabile, usato in URL e nella colonna jobs.tipologia. */
    public static function chiave(): string;

    /** @return array<string,mixed> il manifest che alimenta la tessera (2b) e la scheda (2c) */
    public function manifest(): array;

    /**
     * Controllo rapido del file caricato, prima di accettarlo.
     *
     * @return array{ok:bool,motivo:?string,pagine:int,intestazione:array}
     */
    public function verifica(string $percorsoIngresso): array;

    /**
     * Esegue la conversione.
     *
     * @param array<string,mixed> $regole
     * @param callable(string,int,int):void|null $progresso passo, corrente, totale
     * @return array{
     *   righe_lette:int, righe_scritte:int, pagine:int,
     *   anomalie: list<array<string,mixed>>, anteprima: list<array<string,mixed>>
     * }
     */
    public function converti(string $percorsoIngresso, string $percorsoUscita, array $regole, ?callable $progresso = null): array;

    /**
     * Legge il file senza produrre niente, per mostrare nello step 2 cosa c'è
     * dentro prima di scegliere le regole.
     *
     * @param array<string,mixed> $regole
     * @return array<string,mixed>
     */
    public function analizza(string $percorsoIngresso, array $regole = []): array;
}
