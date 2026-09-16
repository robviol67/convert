<?php
declare(strict_types=1);

namespace Vblite\Convert\Conversioni;

/**
 * Una conversione che sa lavorare a tappe.
 *
 * Sull'hosting non si possono lanciare processi in sfondo, e ogni richiesta ha
 * un tetto di memoria di una ventina di megabyte: una stampa di duemila pagine
 * non si converte in una volta sola. A tappe sì. Ogni richiesta della pagina di
 * avanzamento fa un pezzo di lavoro, e fra una richiesta e l'altra lo stato
 * sta su disco, in una cartella di lavoro del job.
 *
 * Lo stato è un vettore semplice, che si salva in JSON così com'è. Il Job non
 * sa cosa contenga, tranne tre chiavi:
 *
 * - 'fatto'      true quando il lavoro è concluso;
 * - 'risultato'  a lavoro concluso, quello che darebbe converti() — con le
 *                anomalie in un file ('anomalie_file') invece che in memoria;
 * - 'tentativi'  quante volte di fila il passo corrente è stato cominciato
 *                senza finire. Lo tiene il Job; la conversione lo legge per
 *                fare passi più piccoli quando quelli normali non passano.
 */
interface ConversioneAPassi
{
    /**
     * @param array<string,mixed> $regole
     * @return array<string,mixed> lo stato iniziale
     */
    public function prepara(string $percorsoIngresso, string $cartellaLavoro, array $regole): array;

    /**
     * Un pezzo di lavoro.
     *
     * @param array<string,mixed> $stato
     * @param array<string,mixed> $regole
     * @return array<string,mixed> lo stato aggiornato
     */
    public function avanza(
        string $percorsoIngresso,
        string $cartellaLavoro,
        array $stato,
        array $regole,
        string $percorsoUscita,
    ): array;

    /**
     * A che punto è il lavoro, per la barra della pagina di avanzamento.
     *
     * @param array<string,mixed> $stato
     * @return array{passo:string,corrente:int,totale:int}
     */
    public function avanzamento(array $stato): array;
}
