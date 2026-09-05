<?php
declare(strict_types=1);

namespace Vblite\Convert;

/** Percorsi e limiti. Nessun segreto qui dentro: le password stanno in ambiente. */
final class Config
{
    public const MAX_BYTE   = 50 * 1024 * 1024;
    public const MAX_PAGINE = 500;

    /** Tentativi di accesso consentiti per email/IP in TENTATIVI_FINESTRA secondi. */
    public const TENTATIVI_MAX      = 8;
    public const TENTATIVI_FINESTRA = 900;

    public static function radice(): string
    {
        return dirname(__DIR__);
    }

    public static function percorsoDb(): string
    {
        return getenv('CONVERT_DB') ?: self::radice() . '/data/convert.db';
    }

    public static function cartellaIngresso(): string
    {
        return self::radice() . '/storage/in';
    }

    public static function cartellaUscita(): string
    {
        return self::radice() . '/storage/out';
    }

    /** URL base dell'applicazione, per i link assoluti (copia link). */
    public static function baseUrl(): string
    {
        $schema = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base   = Auth::percorsoBase();

        return "{$schema}://{$host}{$base}";
    }
}
