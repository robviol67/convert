<?php
declare(strict_types=1);
/**
 * Suite di verifica, senza dipendenze: `php tests/prova.php`.
 *
 * Le attese non sono inventate: vengono dai due file di esempio del committente
 * (201 pagine, 1.174 righe, 584 prenotazioni) e dalle righe 2 e 3 del tracciato
 * Scidoo, che fanno da test di regressione.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/LettoreXlsx.php';

use Vblite\Convert\Conversioni\OctoScidoo\ConversioneOctoScidoo;
use Vblite\Convert\Conversioni\OctoScidoo\Normalizza;
use Vblite\Convert\Conversioni\OctoScidoo\Parser;
use Vblite\Convert\Conversioni\OctoScidoo\Raggruppatore;
use Vblite\Convert\Conversioni\OctoScidoo\ScrittoreScidoo;
use Vblite\Convert\Conversioni\Registro;

$esempi   = __DIR__ . '/../../handoff_octo_scidoo/esempi';
$pdf      = $esempi . '/prenotazioni 2024 2025.pdf';
$tracciato = $esempi . '/File Import Prenotazioni.xlsx';

$passati = 0;
$falliti = [];

function verifica(string $nome, mixed $atteso, mixed $ottenuto): void
{
    global $passati, $falliti;
    if ($atteso === $ottenuto) {
        $passati++;
        return;
    }
    $falliti[] = sprintf("  %s\n     atteso:   %s\n     ottenuto: %s", $nome, var_export($atteso, true), var_export($ottenuto, true));
}

function vero(string $nome, bool $condizione): void
{
    verifica($nome, true, $condizione);
}

/** Indice 0 → «B»: la colonna A del tracciato resta libera. */
function lettera(int $indice): string
{
    $n = $indice + 2;
    $l = '';
    while ($n > 0) {
        $resto = ($n - 1) % 26;
        $l = chr(65 + $resto) . $l;
        $n = (int) (($n - $resto) / 26);
    }

    return $l;
}

// ── Normalizzatori ───────────────────────────────────────────────────────
verifica('id toglie il punto delle migliaia', 4256, Normalizza::id('4.256'));
verifica('id da numero corto', 145, Normalizza::id('145'));

// Epoca 1899-12-30, come nel file di esempio Scidoo. Attenzione: il documento di
// consegna afferma «45658 = 15/01/2025», ma nel file vero E2 vale 45658 e cade il
// 01/01/2025; il seriale giusto per il 15/01/2025 e' 45672.
verifica('data seriale con l\'epoca di Excel', 45658.0, Normalizza::dataSeriale('01/01/2025'));
verifica('data seriale, secondo riscontro', 45672.0, Normalizza::dataSeriale('15/01/2025'));
verifica('data con anno a 2 cifre', Normalizza::dataSeriale('26/11/2023'), Normalizza::dataSeriale('26/11/23'));
verifica('data non valida', null, Normalizza::dataSeriale('31/02/2024'));
verifica('data vuota', null, Normalizza::dataSeriale(''));

verifica('importo con migliaia', 1490.20, Normalizza::importo('1.490,20'));
verifica('importo semplice', 416.0, Normalizza::importo('416,00'));
verifica('importo vuoto resta vuoto, non zero', null, Normalizza::importo(''));

$tel = Normalizza::telefono('+39 335 842 8355');
verifica('cellulare riconosciuto', '+39 335 842 8355', $tel['mobile']);
verifica('cellulare non finisce su fisso', null, $tel['fisso']);
vero('cellulare completo non e\' troncato', !$tel['troncato']);

$corto = Normalizza::telefono('349 674');
vero('telefono troncato segnalato', $corto['troncato']);

verifica('agenzia OTA normalizzata', 'Booking.com', Normalizza::agenzia('BOOKING.COM', ''));
verifica('agenzia dal prenotante se manca il pagante', 'Quick Booking', Normalizza::agenzia('', 'QUICK BOOKING'));
verifica('ragione sociale diretta invariata', 'AMG SRL', Normalizza::agenzia('AMG SRL', ''));

$commento = Normalizza::commento("&lt;b&gt;Warning: \nfor some days a \ndifferent rate plan is \napplied&lt;/b&gt;");
verifica('commento OTA de-escapato e ricucito', 'Warning: for some days a different rate plan is applied', $commento);

verifica(
    'categoria camera dedotta dai commenti',
    'Camera Matrimoniale',
    Normalizza::categoriaCamera('19: 00 [ Camera Matrimoniale - Single Use - customers: 1 ]')
);
verifica('categoria camera assente', null, Normalizza::categoriaCamera('nessuna parentesi qui'));

// ── Password ─────────────────────────────────────────────────────────────
// Argon2 esiste solo se PHP e' compilato con libargon2. Dove non c'e', la
// costante PASSWORD_ARGON2ID non e' definita e nominarla e' un errore fatale:
// e' cosi' che la prima installazione in produzione e' andata in 500.
$algoritmo = \Vblite\Convert\Auth::algoritmo();
vero('l\'algoritmo scelto e\' davvero disponibile', in_array($algoritmo, array_merge(password_algos(), [PASSWORD_BCRYPT]), true));

$hash = \Vblite\Convert\Auth::hash('unapasswordlunga');
vero('la password si verifica contro il proprio hash', password_verify('unapasswordlunga', $hash));
verifica('una password sbagliata non passa', false, password_verify('altracosa', $hash));

// Anche il ripiego deve reggere: e' quello che gira sul server.
$conBcrypt = password_hash('unapasswordlunga', PASSWORD_BCRYPT, ['cost' => 12]);
vero('bcrypt come ripiego funziona', password_verify('unapasswordlunga', $conBcrypt));

// ── Tracciato in uscita ──────────────────────────────────────────────────
$testate = (new ScrittoreScidoo())->testate();
verifica('32 colonne in uscita', 32, count($testate));

if (is_file($tracciato)) {
    $atteso  = new \Vblite\Convert\Test\LettoreXlsx($tracciato);
    $diverse = [];
    foreach ($testate as $i => $testata) {
        $lettera = lettera($i);
        $suo = (string) $atteso->valore($lettera . '1');
        if ($suo !== $testata) {
            $diverse[] = "{$lettera}: «{$suo}» ≠ «{$testata}»";
        }
    }
    verifica('intestazioni identiche al file del cliente', [], $diverse);
}

// ── Registro delle tipologie ─────────────────────────────────────────────
verifica('una sola tipologia attiva', 1, count(Registro::tutte()));
vero('tipologia trovata per chiave', Registro::trova('octo_scidoo') !== null);
verifica('chiave sconosciuta', null, Registro::trova('inesistente'));

// ── Pipeline sul PDF reale ───────────────────────────────────────────────
if (!is_file($pdf)) {
    echo "PDF di esempio assente: saltate le prove sul file reale.\n";
} else {
    $conversione = new ConversioneOctoScidoo();

    $verifica = $conversione->verifica($pdf);
    vero('la stampa Octorate e\' riconosciuta', $verifica['ok']);
    verifica('201 pagine', 201, $verifica['pagine']);
    verifica('periodo letto dalla testata', '01/01/2024', $verifica['intestazione']['dal']);

    $estratto = (new Parser())->estrai($pdf);
    verifica('1.174 righe cliente', 1174, count($estratto['righe']));
    verifica('584 prenotazioni distinte', 584, count(array_unique(array_column($estratto['righe'], 'npren'))));

    // Nessun importo deve uscire malformato: i numeri col separatore delle
    // migliaia arrivano spezzati in due frammenti e vanno ricuciti senza spazio.
    $malformati = [];
    foreach ($estratto['righe'] as $riga) {
        foreach (['importo', 'caparre', 'acconti'] as $campo) {
            if ($riga[$campo] !== '' && !preg_match('~^-?[\d.]+,\d{2}$~', $riga[$campo])) {
                $malformati[] = "{$riga['npren']}.{$campo}={$riga[$campo]}";
            }
        }
    }
    verifica('nessun importo malformato', [], $malformati);

    // La somma dei Pax dichiarati per camera deve dare il numero di righe ospite.
    $gruppi = [];
    foreach ($estratto['righe'] as $riga) {
        $gruppi[$riga['npren']][] = $riga;
    }
    $incoerenti = [];
    foreach ($gruppi as $npren => $ospiti) {
        $pax = array_sum(array_map('intval', array_column($ospiti, 'pax')));
        if ($pax !== count($ospiti)) {
            $incoerenti[] = (string) $npren;
        }
    }
    verifica('somma Pax coerente col numero di ospiti', [], $incoerenti);

    // La prima prenotazione del PDF, verificata a mano sulla pagina 1.
    $primo = $estratto['righe'][0];
    verifica('capofila: N°pren.', '4.256', $primo['npren']);
    verifica('capofila: cognome', 'LAUDATI', $primo['cognome']);
    verifica('capofila: nome', 'flavio', $primo['nome']);
    verifica('capofila: arrivo', '16/01/2024', $primo['arrivo']);
    verifica('capofila: camera', '8', $primo['camera']);
    verifica('capofila: importo', '416,00', $primo['importo']);

    $uscita = sys_get_temp_dir() . '/prova-convert-' . getmypid() . '.xlsx';

    // Il picco va misurato su UNA conversione: nel resto dello script se ne
    // fanno diverse e il contatore le somma.
    if (function_exists('memory_reset_peak_usage')) {
        memory_reset_peak_usage();
    }
    $out = $conversione->converti($pdf, $uscita, Raggruppatore::REGOLE_DEFAULT);
    $piccoConversione = memory_get_peak_usage(true) / 1048576;
    verifica('584 righe scritte', 584, $out['righe_scritte']);
    vero('il file esiste ed e\' non vuoto', is_file($uscita) && filesize($uscita) > 10000);

    $foglio = new \Vblite\Convert\Test\LettoreXlsx($uscita);
    verifica('prima riga: ID senza punto', '4256', $foglio->valore('B2'));
    verifica('prima riga: cognome', 'LAUDATI', $foglio->valore('D2'));
    verifica('prima riga: arrivo come data vera', '45307', $foglio->valore('E2'));
    vero('arrivo e\' un numero, non testo', $foglio->eNumero('E2'));
    verifica('arrivo formattato come data', 'm/d/yyyy', $foglio->formato('E2'));
    verifica('prima riga: camera come testo', '8', $foglio->valore('G2'));
    vero('camera resta testo, non numero', !$foglio->eNumero('G2'));
    verifica('prima riga: prezzo come numero', '416', $foglio->valore('T2'));
    verifica('prezzo formattato come valuta', '#,##0.00\\ "€"', $foglio->formato('T2'));
    verifica('prima riga: retta', 'Room Only', $foglio->valore('S2'));
    verifica('prima riga: servizio iniziale', 'Pernotto', $foglio->valore('AD2'));
    verifica('ultima riga scritta', 585, $foglio->ultimaRiga());

    // Excel applica alla lettera la sequenza dichiarata dallo schema OOXML.
    // Un ordine sbagliato passa inosservato alle librerie tolleranti e fa
    // rifiutare il file a Excel, che offre di «recuperare il contenuto».
    $ordine = \Vblite\Convert\Test\LettoreXlsx::ordineElementi($uscita);
    $attesa = ['dimension', 'sheetViews', 'sheetFormatPr', 'cols', 'sheetData'];
    verifica('gli elementi del foglio sono nell\'ordine che pretende lo schema', $attesa, $ordine);

    $colonne = \Vblite\Convert\Test\LettoreXlsx::colonneDichiarate($uscita);
    $ordinate = $colonne;
    sort($ordinate);
    verifica('le colonne sono dichiarate in ordine crescente', $ordinate, $colonne);
    verifica('la prima colonna dichiarata e\' la A', 1, $colonne[0] ?? 0);

    // Camera vuota deve restare vuota, mai zero (eccezione 7).
    $zeri = 0;
    for ($r = 2; $r <= 585; $r++) {
        if ($foglio->valore('G' . $r) === '0') {
            $zeri++;
        }
    }
    verifica('nessuna camera a zero', 0, $zeri);

    // Le anomalie devono ritrovare i casi dichiarati nel materiale di consegna.
    $chiavi = array_column($out['anomalie'], 'chiave');
    foreach (['4.313', '4.278', '4.310'] as $caso) {
        vero("caso noto {$caso} segnalato", in_array($caso, $chiavi, true));
    }
    $telefonoTroncato = array_filter(
        $out['anomalie'],
        static fn(array $a): bool => $a['chiave'] === '4.278' && str_contains($a['motivo'], 'troncato')
    );
    vero('4.278 segnalato per telefono troncato', $telefonoTroncato !== []);

    // Di default i bambini non si deducono dai Supplementi: nel file di esempio
    // «Letto agg. Bambino» compare in quasi tutte le prenotazioni e non descrive
    // gli ospiti elencati. Con la regola accesa devono comparire, con segnalazione.
    $conteggia = static function (array $risultato): int {
        $n = 0;
        foreach ($risultato['prenotazioni'] as $prenotazione) {
            $n += $prenotazione['bambini'] > 0 ? 1 : 0;
        }

        return $n;
    };

    $default = (new Raggruppatore())->raggruppa($estratto['righe']);
    verifica('default: nessun bambino dedotto', 0, $conteggia($default));

    $acceso = (new Raggruppatore(['bambini_da_supplementi' => true]))->raggruppa($estratto['righe']);
    vero('regola accesa: i bambini compaiono', $conteggia($acceso) > 0);
    vero('regola accesa: ogni caso e\' segnalato', count($acceso['anomalie']) > count($default['anomalie']));

    // Con la regola spenta restano solo le anomalie che richiedono davvero una
    // decisione umana: telefoni troncati, camere multiple, importi mancanti.
    $daCorreggere = array_filter($default['anomalie'], static fn(array $a): bool => $a['gravita'] === 'correggi');
    vero('default: le segnalazioni da correggere restano poche', count($daCorreggere) < 60);

    // Il filtro di periodo deve restringere davvero l'uscita.
    $soloGennaio = (new Raggruppatore(['periodo_dal' => '01/01/2024', 'periodo_al' => '31/01/2024']))->raggruppa($estratto['righe']);
    vero('il periodo restringe le prenotazioni', count($soloGennaio['prenotazioni']) < 584 && count($soloGennaio['prenotazioni']) > 0);

    // ── Il vincolo che decide se il lavoro finisce ───────────────────────
    // In produzione il processo viene fermato poco sopra i 20 MB di dati:
    // non da PHP, che crede di averne 512, ma dal sistema — senza errore e
    // senza log. Questa soglia e' la guardia contro una regressione che si
    // manifesterebbe solo sul server, e in modo muto.
    vero(
        sprintf('una conversione sta sotto i 20 MB (picco %.1f MB)', $piccoConversione),
        $piccoConversione < 20
    );

    // ── Correzioni fatte a mano ──────────────────────────────────────────
    // Non ritoccano il foglio: rientrano nella conversione, che si rifa'.
    $corretto = sys_get_temp_dir() . '/prova-corretto-' . getmypid() . '.xlsx';
    // Attenzione all'ordine: con «+» vincono le chiavi di sinistra, quindi le
    // regole proprie vanno a sinistra e i default riempiono i buchi.
    (new ConversioneOctoScidoo())->converti($pdf, $corretto, [
        'correzioni' => [
            '4.256' => ['Adulti · Bambini' => '9 · 3', 'Categoria Camera' => 'Camera Matrimoniale'],
            '4.277' => ['Prezzo Retta' => '1.234,50'],
        ],
    ] + Raggruppatore::REGOLE_DEFAULT);
    $conCorrezioni = new \Vblite\Convert\Test\LettoreXlsx($corretto);
    verifica('correzione: adulti', '9', $conCorrezioni->valore('O2'));
    verifica('correzione: bambini', '3', $conCorrezioni->valore('P2'));
    verifica('correzione: categoria camera', 'Camera Matrimoniale', $conCorrezioni->valore('H2'));
    verifica('correzione: prezzo con migliaia', '1234.5', $conCorrezioni->valore('T4'));
    verifica('le righe non corrette restano tali', 'LAUDATI', $conCorrezioni->valore('D2'));
    @unlink($corretto);

    // ── CSV ──────────────────────────────────────────────────────────────
    $csv = sys_get_temp_dir() . '/prova-' . getmypid() . '.csv';
    (new ConversioneOctoScidoo())->converti($pdf, $csv, ['formato' => 'csv'] + Raggruppatore::REGOLE_DEFAULT);
    $righeCsv = file($csv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    verifica('CSV: 1 intestazione + 584 righe', 585, count($righeCsv));
    vero('CSV: le date sono leggibili, non seriali', str_contains($righeCsv[1], '16/01/2024'));
    @unlink($csv);

    @unlink($uscita);
}

// ── Esito ────────────────────────────────────────────────────────────────
echo "\n";
if ($falliti === []) {
    echo "OK — {$passati} verifiche passate.\n";
    exit(0);
}
echo "FALLITE " . count($falliti) . " su " . ($passati + count($falliti)) . ":\n\n";
echo implode("\n\n", $falliti) . "\n";
exit(1);
