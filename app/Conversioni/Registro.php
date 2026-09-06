<?php
declare(strict_types=1);

namespace Vblite\Convert\Conversioni;

use Vblite\Convert\Conversioni\Documenti\ConversioneDocumenti;
use Vblite\Convert\Conversioni\OctoScidoo\ConversioneOctoScidoo;
use Vblite\Convert\Conversioni\Tabelle\ConversioneTabelle;

/**
 * Registro delle tipologie di conversione.
 * La home e lo step 1 leggono da qui: aggiungere una tipologia = aggiungere una
 * riga in self::CLASSI, senza toccare nessuna schermata.
 */
final class Registro
{
    /** @var list<class-string<Conversione>> */
    private const CLASSI = [
        ConversioneOctoScidoo::class,
        ConversioneDocumenti::class,
        ConversioneTabelle::class,
    ];

    /**
     * Segnaposto mostrati nella home come tessere «In arrivo».
     * Sono dichiarativi: appena la tipologia esiste, la riga si toglie da qui.
     *
     * @var list<array{titolo:string,sottotitolo:string}>
     */
    private const IN_ARRIVO = [];

    /** @return list<Conversione> */
    public static function tutte(): array
    {
        return array_map(static fn(string $classe): Conversione => new $classe(), self::CLASSI);
    }

    public static function trova(string $chiave): ?Conversione
    {
        foreach (self::CLASSI as $classe) {
            if ($classe::chiave() === $chiave) {
                return new $classe();
            }
        }

        return null;
    }

    /** @return list<array{titolo:string,sottotitolo:string}> */
    public static function inArrivo(): array
    {
        return self::IN_ARRIVO;
    }
}
