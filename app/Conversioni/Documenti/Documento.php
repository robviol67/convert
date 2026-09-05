<?php
declare(strict_types=1);

namespace Vblite\Convert\Conversioni\Documenti;

/**
 * Il documento in transito fra un lettore e uno scrittore.
 *
 * Fa da perno: ogni formato in ingresso produce blocchi qui, ogni formato in
 * uscita li consuma. Così i formati costano N + M classi invece di N × M
 * conversioni.
 *
 * Può accumulare i blocchi (comodo per le prove e per i documenti brevi) oppure
 * passarli a uno scrittore man mano (obbligatorio per quelli lunghi: un report
 * di duecento pagine fa 23.000 blocchi e da solo supera il tetto di memoria
 * dell'hosting).
 */
final class Documento
{
    /** @var list<Blocco> pieno solo quando non c'è un consumatore */
    private array $blocchi = [];

    /** @var (callable(Blocco):void)|null */
    private $consumatore = null;

    /**
     * L'ultimo blocco arrivato, tenuto in sospeso di un giro.
     *
     * Serve a ricucire i blocchi di codice: Word e RTF non hanno un «blocco di
     * codice», solo paragrafi con carattere fisso, e arrivano una riga alla
     * volta. Con un giro di ritardo si possono ancora unire anche mentre si
     * scrive in catena, dove un blocco già emesso non si può più ritirare.
     */
    private ?Blocco $sospeso = null;

    private int $quanti = 0;
    private int $parole = 0;

    /** @var array<string,int> */
    private array $conteggi = [];

    /** @var array<string,string> nome usato nel documento → percorso sul disco */
    private array $immagini = [];

    /** @var list<array{motivo:string,dettaglio:string}> quello che non è passato intero */
    private array $perdite = [];

    public string $titolo = '';

    /** @param (callable(Blocco):void)|null $consumatore */
    public function consuma(?callable $consumatore): void
    {
        $this->consumatore = $consumatore;
    }

    public function aggiungi(Blocco $blocco): void
    {
        // I blocchi vuoti sono rumore del formato d'origine, non contenuto:
        // Word e RTF ne producono a decine per la spaziatura.
        if ($blocco->vuoto() && $blocco->tipo !== Blocco::RIGA) {
            return;
        }

        if ($this->sospeso !== null
            && $this->sospeso->tipo === Blocco::CODICE
            && $blocco->tipo === Blocco::CODICE
            && $this->sospeso->extra === $blocco->extra) {
            $this->sospeso = Blocco::codice(
                $this->sospeso->nudo() . "\n" . $blocco->nudo(),
                $this->sospeso->extra
            );

            return;
        }

        $this->emetti($this->sospeso);
        $this->sospeso = $blocco;
    }

    /** Da chiamare a lettura finita: manda fuori l'ultimo blocco sospeso. */
    public function concludi(): void
    {
        $this->emetti($this->sospeso);
        $this->sospeso = null;
    }

    private function emetti(?Blocco $blocco): void
    {
        if ($blocco === null) {
            return;
        }

        $this->quanti++;
        $this->conteggi[$blocco->tipo] = ($this->conteggi[$blocco->tipo] ?? 0) + 1;
        $this->parole += $this->paroleDi($blocco);

        if ($this->consumatore !== null) {
            ($this->consumatore)($blocco);

            return;
        }
        $this->blocchi[] = $blocco;
    }

    /** @return list<Blocco> vuoto se i blocchi sono stati passati a uno scrittore */
    public function blocchi(): array
    {
        return $this->blocchi;
    }

    public function quanti(): int
    {
        return $this->quanti;
    }

    public function parole(): int
    {
        return $this->parole;
    }

    /** @return array<string,int> conteggio dei blocchi per tipo, per il riepilogo */
    public function riepilogo(): array
    {
        $conta = $this->conteggi;
        arsort($conta);

        return $conta;
    }

    public function registraImmagine(string $nome, string $percorso): void
    {
        $this->immagini[$nome] = $percorso;
    }

    /** @return array<string,string> */
    public function immagini(): array
    {
        return $this->immagini;
    }

    /**
     * Quello che il formato d'origine aveva e che non è passato intero.
     * Non si tace: finisce in «Da rivedere», come per le prenotazioni.
     */
    public function perdita(string $motivo, string $dettaglio = ''): void
    {
        $this->perdite[] = ['motivo' => $motivo, 'dettaglio' => $dettaglio];
    }

    /** @return list<array{motivo:string,dettaglio:string}> */
    public function perdite(): array
    {
        return $this->perdite;
    }

    private function paroleDi(Blocco $blocco): int
    {
        $testo = $blocco->tipo === Blocco::TABELLA
            ? $this->testoTabella($blocco)
            : $blocco->nudo();

        return str_word_count($testo, 0, 'àèéìòùÀÈÉÌÒÙabcdefghijklmnopqrstuvwxyz0123456789');
    }

    private function testoTabella(Blocco $blocco): string
    {
        $pezzi = [];
        foreach ($blocco->righe as $riga) {
            foreach ($riga as $cella) {
                $pezzi[] = Testo::nudo($cella);
            }
        }

        return implode(' ', $pezzi);
    }
}
