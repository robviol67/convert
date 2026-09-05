<?php
declare(strict_types=1);

namespace Vblite\Convert\Conversioni\Documenti\Lettori;

use Vblite\Convert\Conversioni\Documenti\Documento;

/** Un formato che sappiamo leggere. */
interface Lettore
{
    /** Estensioni riconosciute, minuscole e senza punto. */
    public static function estensioni(): array;

    /** Nome leggibile del formato, per l'interfaccia. */
    public static function nome(): string;

    /**
     * @param string    $cartellaMedia dove salvare le immagini estratte
     * @param Documento $documento     quello a cui aggiungere i blocchi; se ne
     *                                 ha già uno agganciato a uno scrittore, la
     *                                 conversione lavora in catena e non tiene
     *                                 il documento in memoria
     */
    public function leggi(string $percorso, string $cartellaMedia, ?Documento $documento = null): Documento;
}
