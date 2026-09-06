<?php
declare(strict_types=1);

namespace Vblite\Convert\Conversioni\Documenti\Scrittori;

use Vblite\Convert\Conversioni\Documenti\Blocco;
use Vblite\Convert\Conversioni\Documenti\Documento;

/**
 * Un formato che sappiamo scrivere.
 *
 * Si scrive a catena — apri, un blocco alla volta, chiudi — invece di ricevere
 * il documento intero: così la memoria non dipende dalla lunghezza del testo,
 * che è l'unico modo di stare nel tetto dell'hosting.
 */
interface Scrittore
{
    /** Estensione del file prodotto, minuscola e senza punto. */
    public static function estensione(): string;

    public static function nome(): string;

    /**
     * Le regole scelte nello step 2, per gli scrittori che ne hanno bisogno
     * (titolo e autore di un EPUB, per esempio). Chi non le usa non fa niente.
     *
     * @param array<string,mixed> $regole
     */
    public function configura(array $regole): void;

    /** Apre il file e scrive quello che precede il contenuto. */
    public function apri(string $percorso, Documento $documento): void;

    public function blocco(Blocco $blocco): void;

    /** Chiude il file e torna il numero di blocchi resi. */
    public function chiudi(): int;
}
