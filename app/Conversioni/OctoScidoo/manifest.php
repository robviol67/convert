<?php
declare(strict_types=1);

use Vblite\Convert\Conversioni\OctoScidoo\ScrittoreScidoo;

/**
 * Manifest della tipologia Octo → Scidoo.
 * Alimenta la tessera nella home (2b) e la scheda dello step 1 (2c): i testi
 * dell'interfaccia stanno qui, non nelle pagine.
 */
return [
    'chiave'      => 'octo_scidoo',
    'titolo'      => 'Octo clienti → Scidoo',
    'sottotitolo' => 'Stampa clienti presenti Octorate (PDF) → File Import Prenotazioni (XLSX)',
    'attiva'      => true,
    'descrizione' => 'Converte la <strong>Stampa clienti presenti</strong> del gestionale <strong>Octorate</strong> '
        . 'nel tracciato di import di <strong>Scidoo</strong>. È la stampa dei clienti <strong>arrivati e '
        . 'partiti</strong>: ha una riga per ospite, e le righe vengono raggruppate in una prenotazione per '
        . 'N°pren. Per le prenotazioni che non hanno ancora fatto il check-in c\'è la tipologia '
        . '<em>Octo prenotazioni → Scidoo</em>, che legge l\'altra stampa.',

    'si_puo' => [
        '<em>Stampa clienti presenti</em> di Octorate, in PDF, con la testata e le 23 colonne originali',
        'Qualunque periodo, anche pluriennale: le stampe lunghe si leggono a tappe',
        'Prenotazioni dirette e da OTA: Booking.com, Quick Booking, Expedia',
        'Gruppi con più ospiti sullo stesso N°pren.',
        'Prenotazioni su più camere: una riga per camera, con gli importi di ciascuna',
    ],
    'non_si_puo' => [
        'La <em>Stampa prenotazioni</em>: quella va in <em>Octo prenotazioni → Scidoo</em>',
        'Le altre stampe Octorate (listini, disponibilità, statistiche): se te ne serve una, si aggiunge',
        'PDF ritagliati, uniti a mano o stampati con colonne nascoste',
        'Fotografie o scansioni storte della stampa',
        'File già in Excel: il tracciato si costruisce dal PDF',
    ],

    'specifiche' => [
        'Ingresso'      => 'PDF · max 50 MB · 10.000 pagine',
        'Colonne lette' => '23',
        'Uscita'        => 'XLSX · 32 colonne · o CSV',
        'Granularità'   => '1 riga = 1 prenotazione',
        'Motore'        => 'deterministico',
        'Chiave'        => 'N°pren.',
    ],

    'eccezioni' => [
        ['titolo' => 'È la stampa dei clienti, non delle prenotazioni.', 'testo' => 'Qui ci sono i clienti arrivati e partiti. Se carichi la <em>Stampa prenotazioni</em> viene rifiutata, dicendoti dove portarla: le colonne sono diverse e il risultato sarebbe sbagliato.'],
        ['titolo' => 'Categoria Camera esce vuota.', 'testo' => 'In Octorate non c\'è una colonna che le corrisponda: si deduce dai Commenti («Camera Matrimoniale – Single Use») solo se attivi la regola opzionale.'],
        ['titolo' => 'Importi per camera, non per ospite.',      'testo' => 'Nel gruppo, Importo, Caparre e Acconti stanno sulla riga della camera a cui appartengono: seguono la camera, non si dividono fra gli ospiti. Se una camera non ne dichiara, resta senza.'],
        ['titolo' => 'Fasce d\'età non dichiarate.', 'testo' => 'Questa stampa non distingue adulti e bambini: gli adulti sono le righe ospite del gruppo, e il supplemento «Letto agg. Bambino» può contare un bambino solo se attivi la regola opzionale — nel file di esempio compare in quasi tutte le prenotazioni, comprese quelle da un solo ospite.'],
        ['titolo' => 'Telefoni troncati.',                       'testo' => 'La stampa taglia la colonna Telefono («349 674»): il numero incompleto si segnala, non si inventa.'],
        ['titolo' => 'Commenti OTA con tag HTML.',               'testo' => 'Booking ed Expedia stampano <span class="mono">&amp;lt;b&amp;gt;</span> e a capo dentro il testo; il commento è ripetuto su ogni ospite del gruppo e va tenuto solo sull\'intestatario.'],
        ['titolo' => 'Tassa soggiorno e Convenzione',            'testo' => 'non hanno colonna nel tracciato Scidoo: finiscono trascritte in Note. Lo Sconto resta fuori, come chiesto: nelle stampe viste finora è sempre vuoto.'],
        ['titolo' => 'Numero camera dalla stampa.',              'testo' => 'Octorate stampa il numero nella colonna Cam. (7, 8, 9…): se il campo è vuoto la camera esce vuota, mai a zero.'],
        ['titolo' => 'Su più camere fa più righe.',              'testo' => 'In Scidoo una riga è un soggiorno in una camera. La prenotazione esce una volta per camera — stesso numero, stesso cliente, stesse date — e ogni riga porta gli importi della sua, che la stampa scrive separati. Non si ripetono: ripeterli moltiplicherebbe il valore della prenotazione.'],
    ],

    'regole_conversione' => [
        ['da' => 'N°pren.',                                 'a' => 'ID',                                        'regola' => 'Punto delle migliaia rimosso: <span class="mono">4.256 → 4256</span>'],
        ['da' => 'Cognome + Nome',                          'a' => 'Cognome / Nome Cliente',                    'regola' => 'Prima riga del gruppo = intestatario'],
        ['da' => 'Arrivo · Partenza',                       'a' => 'Data di Arrivo / Partenza',                 'regola' => 'Data vera, non testo'],
        ['da' => 'Righe del gruppo',                        'a' => 'Adulti · Bambini · Neonati',                'regola' => 'Una riga ospite = un adulto; i bambini solo con la regola opzionale', 'accento' => true],
        ['da' => 'Data + Ora',                              'a' => 'Data Inserimento',                          'regola' => 'Anno a 2 cifre completato'],
        ['da' => 'Data opzione',                            'a' => 'Stato Prenotazione + Data Scadenza Opzione','regola' => 'Se presente → <em>Opzione</em>, altrimenti <em>Confermata con Pagamento</em>'],
        ['da' => 'Agenzia pagante / prenotante',            'a' => 'Agenzia',                                   'regola' => 'Booking.com · Quick Booking · diretta'],
        ['da' => 'Trattamento',                             'a' => 'Retta · Servizio Iniziale',                 'regola' => 'Pernottamento Giornaliero → Room Only + Pernotto'],
        ['da' => 'Cam. con più numeri',                     'a' => 'Una riga per camera',                       'regola' => 'Stessa prenotazione, stesse date, camera diversa', 'accento' => true],
        ['da' => 'Importo · Supplementi · Caparre · Acconti','a' => 'Prezzo Retta · Prezzo Extra · Caparra · Acconto', 'regola' => 'Seguono la camera a cui sono scritti'],
        ['da' => 'Commenti',                                'a' => 'Note · Note Ota',                           'regola' => 'Tag HTML e <span class="mono">&amp;lt;b&amp;gt;</span> ripuliti; testo OTA separato'],
        ['da' => 'Tassa sogg · Convenzione',                'a' => null,                                        'regola' => 'Non previste dal tracciato → finiscono in Note'],
        ['da' => 'Sconto',                                  'a' => null,                                        'regola' => 'Lasciato fuori su richiesta'],
    ],

    'regole_opzionali' => [
        ['chiave' => 'una_riga_per_prenotazione', 'titolo' => 'Una riga per prenotazione', 'nota' => 'Se togli la spunta esce una riga per ospite', 'default' => true],
        ['chiave' => 'ripulisci_commenti',        'titolo' => 'Ripulisci i commenti OTA',  'nota' => 'Toglie i tag HTML di Booking ed Expedia',    'default' => true],
        ['chiave' => 'bambini_da_supplementi',    'titolo' => 'Deduci i bambini dai Supplementi', 'nota' => 'Conta un bambino per ogni «Letto agg. Bambino». Nel file di esempio compare in 522 prenotazioni su 584: da attivare solo se è davvero un ospite.', 'default' => false],
        ['chiave' => 'camere_su_righe_separate',  'titolo' => 'Una riga per camera', 'nota' => 'Una prenotazione su più camere esce in una riga per camera, ognuna con gli importi della sua. Se la togli, le camere finiscono tutte in una cella sola e resta il solo importo della prima.', 'default' => true],
        ['chiave' => 'deduci_categoria_camera',   'titolo' => 'Deduci la Categoria Camera', 'nota' => 'Octorate non la stampa: si ricava dai Commenti. Da verificare a campione.', 'default' => false],
        ['chiave' => 'salta_annullate',           'titolo' => 'Salta le prenotazioni annullate', 'nota' => null, 'default' => true],
    ],

    'formati_uscita'      => ['xlsx' => 'XLSX', 'csv' => 'CSV'],
    'estensioni_ingresso' => ['pdf'],
    'nome_uscita'         => 'File Import Prenotazioni',
    // Le stampe si leggono a tappe e il tetto della memoria non dipende più
    // dalle pagine: il limite resta solo come freno a un file sbagliato.
    'max_pagine'          => 10000,
    'ha_periodo'          => true,

    // Come si chiamano le cose, in questa tipologia. Le schermate sono uniche:
    // il vocabolario no, e metterlo qui evita di scrivere «prenotazioni» dove
    // un domani ci saranno paragrafi.
    'lessico' => [
        'unita'          => 'prenotazione',
        'unita_plurale'  => 'prenotazioni',
        'origine'        => 'righe cliente',
        'pronto'         => '%s prenotazioni, pronte per Scidoo.',
        'sommario'       => '%1$s righe cliente raggruppate in %2$s prenotazioni, 32 colonne, date come date e importi come valuta.',
        'anteprima'      => 'Prime righe del tracciato',
        'passi'          => [
            'Testata Octorate riconosciuta · 23 colonne',
            'Righe cliente estratte',
            'Raggruppamento per N°pren.',
            'Conteggio Adulti / Bambini / Neonati',
            'Pulizia commenti OTA',
            'Scrittura tracciato Scidoo · 32 colonne',
        ],
    ],
    'invito_upload'       => "Trascina il PDF in quest'area",
    'titolo_upload'       => 'Carica la stampa Octorate',
    'lede_upload'         => 'Serve l\'export <em>Stampa clienti presenti</em>, in PDF, esattamente come lo produce Octorate — niente ritagli né stampe parziali. Per le prenotazioni senza check-in c\'è l\'altra tipologia.',
    'titolo_eccezioni'    => 'Le eccezioni note di questo tracciato',
    'titolo_colonne'      => 'Colonne in uscita',
    'titolo_regole'       => 'Regole di conversione · 23 colonne Octorate → 32 colonne Scidoo',
    'nota_informative'    => 'Voci risolte dalle regole del tracciato e riportate qui solo per trasparenza:',
    'titolo_rivedere'     => "Da rivedere prima dell'import",
    'etichetta_chiave'    => 'N°pren.',
    'etichetta_colonna'   => 'Colonna Scidoo',
    'avviso_rivedere'     => 'Sono già nel file, ma con un valore incerto: Scidoo le accetterebbe sbagliate. Correggi qui e il foglio si riscrive.',
    'colonna_da'          => 'Octorate',
    'colonna_a'           => 'Scidoo',
    // Le colonne le sa lo scrittore: elencarle di nuovo qui vorrebbe dire
    // tenerle allineate a mano. La nota in R1 non è una colonna, si salta.
    'colonne_uscita'      => array_values(array_filter(
        array_map('trim', (new ScrittoreScidoo())->testate()),
        static fn(string $t): bool => !str_starts_with($t, '*')
    )),
    'nota_uscita'         => 'Intestazioni e ordine colonne dal tuo <span class="mono">File Import Prenotazioni.xlsx</span>.',

    'sorgenti_camera' => [
        'cam'    => 'Dalla colonna Cam. (7, 8, 9…)',
        'gruppo' => 'Dalla colonna Gruppo',
        'vuoto'  => 'Lascia vuoto',
    ],
];
