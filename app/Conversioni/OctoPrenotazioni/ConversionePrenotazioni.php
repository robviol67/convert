<?php
declare(strict_types=1);

namespace Vblite\Convert\Conversioni\OctoPrenotazioni;

use Vblite\Convert\Conversioni\OctoScidoo\ConversioneOctoScidoo;

/**
 * Octorate «Stampa prenotazioni» → Scidoo «File Import Prenotazioni».
 *
 * È la stampa delle prenotazioni che il check-in non l'hanno ancora fatto: una
 * riga per prenotazione, con telefono, e-mail, data d'inserimento e scadenza
 * dell'opzione che l'altra stampa non dà.
 *
 * Il motore è lo stesso della tipologia dei clienti presenti — stesso lettore,
 * stesso raggruppamento, stesso tracciato in uscita: quello che cambia sono le
 * colonne della stampa, e le dichiara il Tracciato. Qui restano la scheda e il
 * vincolo che conta: questa tipologia accetta solo la sua stampa, e se le
 * arriva l'altra dice dove portarla.
 */
final class ConversionePrenotazioni extends ConversioneOctoScidoo
{
    public static function chiave(): string
    {
        return 'octo_prenotazioni';
    }

    public function manifest(): array
    {
        return require __DIR__ . '/manifest.php';
    }

    public function tracciatoAccettato(): string
    {
        return 'prenotazioni';
    }
}
