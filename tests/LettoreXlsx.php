<?php
declare(strict_types=1);

namespace Vblite\Convert\Test;

/**
 * Lettore XLSX minimo, per le sole verifiche.
 *
 * Serve a controllare quello che scriviamo senza dipendere da PhpSpreadsheet:
 * un validatore che usasse la stessa libreria dello scrittore proverebbe poco,
 * e in esercizio quella libreria non c'e' piu'. Qui si apre lo zip e si legge
 * l'XML, che e' esattamente il formato che Excel si aspetta.
 */
final class LettoreXlsx
{
    /** @var array<string,array{v:string,t:string,s:int}> per coordinata */
    private array $celle = [];

    /** @var array<int,string> codici di formato, per indice di stile */
    private array $formati = [];

    public function __construct(string $percorso)
    {
        $zip = new \ZipArchive();
        if ($zip->open($percorso) !== true) {
            throw new \RuntimeException("Non riesco ad aprire {$percorso}");
        }

        $condivise = $this->stringheCondivise($zip);
        $this->formati = $this->formatiPerStile($zip);

        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if ($xml === false) {
            throw new \RuntimeException('Manca xl/worksheets/sheet1.xml');
        }

        $foglio = new \SimpleXMLElement($xml);
        foreach ($foglio->sheetData->row as $riga) {
            foreach ($riga->c as $cella) {
                $coord = (string) $cella['r'];
                $tipo  = (string) ($cella['t'] ?? '');
                $stile = (int) ($cella['s'] ?? 0);

                $valore = match ($tipo) {
                    'inlineStr' => (string) $cella->is->t,
                    's'         => $condivise[(int) $cella->v] ?? '',
                    default     => (string) $cella->v,
                };

                $this->celle[$coord] = ['v' => $valore, 't' => $tipo, 's' => $stile];
            }
        }
    }

    public function valore(string $coord): ?string
    {
        return $this->celle[$coord]['v'] ?? null;
    }

    public function formato(string $coord): string
    {
        return $this->formati[$this->celle[$coord]['s'] ?? 0] ?? '';
    }

    /** true se la cella e' un numero, false se e' testo. */
    public function eNumero(string $coord): bool
    {
        $cella = $this->celle[$coord] ?? null;

        return $cella !== null && $cella['t'] === '' && is_numeric($cella['v']);
    }

    public function ultimaRiga(): int
    {
        $ultima = 0;
        foreach (array_keys($this->celle) as $coord) {
            $ultima = max($ultima, (int) preg_replace('~\D~', '', $coord));
        }

        return $ultima;
    }

    /** @return list<string> */
    private function stringheCondivise(\ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        $lista = [];
        foreach ((new \SimpleXMLElement($xml))->si as $voce) {
            $lista[] = (string) $voce->t;
        }

        return $lista;
    }

    /** @return array<int,string> */
    private function formatiPerStile(\ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/styles.xml');
        if ($xml === false) {
            return [];
        }
        $stili = new \SimpleXMLElement($xml);

        // I formati incorporati che ci interessano, piu' quelli dichiarati.
        $codici = [0 => 'General', 1 => '0', 14 => 'mm-dd-yy'];
        foreach ($stili->numFmts->numFmt ?? [] as $formato) {
            $codici[(int) $formato['numFmtId']] = (string) $formato['formatCode'];
        }

        $perStile = [];
        $i = 0;
        foreach ($stili->cellXfs->xf as $xf) {
            $perStile[$i++] = $codici[(int) $xf['numFmtId']] ?? '';
        }

        return $perStile;
    }
}
