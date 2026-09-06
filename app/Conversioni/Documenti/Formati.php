<?php
declare(strict_types=1);

namespace Vblite\Convert\Conversioni\Documenti;

use Vblite\Convert\Conversioni\Documenti\Lettori\Lettore;
use Vblite\Convert\Conversioni\Documenti\Lettori\LettoreDocx;
use Vblite\Convert\Conversioni\Documenti\Lettori\LettoreEpub;
use Vblite\Convert\Conversioni\Documenti\Lettori\LettoreHtml;
use Vblite\Convert\Conversioni\Documenti\Lettori\LettoreMarkdown;
use Vblite\Convert\Conversioni\Documenti\Lettori\LettorePdf;
use Vblite\Convert\Conversioni\Documenti\Lettori\LettoreRtf;
use Vblite\Convert\Conversioni\Documenti\Lettori\LettoreTxt;
use Vblite\Convert\Conversioni\Documenti\Scrittori\Scrittore;
use Vblite\Convert\Conversioni\Documenti\Scrittori\ScrittoreDocx;
use Vblite\Convert\Conversioni\Documenti\Scrittori\ScrittoreEpub;
use Vblite\Convert\Conversioni\Documenti\Scrittori\ScrittoreMarkdown;
use Vblite\Convert\Conversioni\Documenti\Scrittori\ScrittorePdf;
use Vblite\Convert\Conversioni\Documenti\Scrittori\ScrittoreRtf;
use Vblite\Convert\Conversioni\Documenti\Scrittori\ScrittoreTxt;

/**
 * Registro dei formati.
 *
 * Aggiungere un formato è una classe in più qui dentro: non essendoci
 * conversioni dirette fra un formato e l'altro — tutto passa dal modello — il
 * costo è lineare, non quadratico.
 */
final class Formati
{
    /** @var list<class-string<Lettore>> */
    private const LETTORI = [
        LettoreMarkdown::class,
        LettoreDocx::class,
        LettoreEpub::class,
        LettoreHtml::class,
        LettorePdf::class,
        LettoreRtf::class,
        LettoreTxt::class,
    ];

    /** @var list<class-string<Scrittore>> */
    private const SCRITTORI = [
        ScrittoreMarkdown::class,
        ScrittoreDocx::class,
        ScrittoreEpub::class,
        ScrittorePdf::class,
        ScrittoreRtf::class,
        ScrittoreTxt::class,
    ];

    /** I formati che Word non produce più ma che la gente ha ancora. */
    private const NON_SUPPORTATI = [
        'doc'  => 'Word 97-2003 (.doc). Aprilo in Word e salvalo come .docx, poi ricaricalo.',
        'pages' => 'Pages di Apple. Esportalo in Word (.docx) o PDF, poi ricaricalo.',
        'odt'  => 'OpenDocument (.odt). Salvalo come .docx, poi ricaricalo.',
    ];

    public static function lettore(string $percorso): Lettore
    {
        $estensione = strtolower(pathinfo($percorso, PATHINFO_EXTENSION));

        foreach (self::LETTORI as $classe) {
            if (in_array($estensione, $classe::estensioni(), true)) {
                return new $classe();
            }
        }

        if (isset(self::NON_SUPPORTATI[$estensione])) {
            throw new \RuntimeException(self::NON_SUPPORTATI[$estensione]);
        }

        throw new \RuntimeException("Non so leggere i file .{$estensione}.");
    }

    /** @param array<string,mixed> $regole le impostazioni scelte nello step 2 */
    public static function scrittore(string $formato, array $regole = []): Scrittore
    {
        foreach (self::SCRITTORI as $classe) {
            if ($classe::estensione() === strtolower($formato)) {
                $scrittore = new $classe();
                $scrittore->configura($regole);

                return $scrittore;
            }
        }

        throw new \RuntimeException("Non so scrivere in formato .{$formato}.");
    }

    /** @return list<string> tutte le estensioni accettate in ingresso */
    public static function estensioniInIngresso(): array
    {
        $tutte = [];
        foreach (self::LETTORI as $classe) {
            foreach ($classe::estensioni() as $estensione) {
                $tutte[] = $estensione;
            }
        }
        sort($tutte);

        return $tutte;
    }

    /** @return array<string,string> estensione → nome leggibile */
    public static function formatiInUscita(): array
    {
        $mappa = [];
        foreach (self::SCRITTORI as $classe) {
            $mappa[$classe::estensione()] = $classe::nome();
        }

        return $mappa;
    }

    /** @return array<string,string> estensione → nome leggibile */
    public static function formatiInIngresso(): array
    {
        $mappa = [];
        foreach (self::LETTORI as $classe) {
            foreach ($classe::estensioni() as $estensione) {
                $mappa[$estensione] = $classe::nome();
            }
        }

        return $mappa;
    }
}
