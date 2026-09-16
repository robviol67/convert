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
    'titolo'      => 'Octo → Scidoo',
    'sottotitolo' => 'Le stampe di Octorate (PDF) → File Import Prenotazioni (XLSX)',
    'attiva'      => true,
    'descrizione' => 'Converte le stampe del gestionale <strong>Octorate</strong> nel tracciato di import di <strong>Scidoo</strong>. Riconosce da sé quale delle due stampe le hai dato: la <em>Stampa clienti presenti</em>, che ha una riga per ospite e viene raggruppata per prenotazione, oppure la <em>Stampa prenotazioni</em>, che ne ha già una per prenotazione. Il file in uscita è lo stesso in tutti e due i casi.',

    'si_puo' => [
        '<em>Stampa clienti presenti</em>, in PDF, con la testata e le 23 colonne originali',
        '<em>Stampa prenotazioni</em>, in PDF: la riconosce da sé, non devi dire quale sia',
        'Qualunque periodo, anche pluriennale: le stampe lunghe si leggono a tappe',
        'Prenotazioni dirette e da OTA: Booking.com, Quick Booking, Expedia',
        'Gruppi con più ospiti sullo stesso N°pren.',
        'Dalla <em>Stampa prenotazioni</em> arrivano anche telefono, e-mail, data d\'inserimento e scadenza dell\'opzione',
    ],
    'non_si_puo' => [
        'Le altre stampe Octorate (listini, disponibilità, statistiche): se te ne serve una, si aggiunge',
        'PDF ritagliati, uniti a mano o stampati con colonne nascoste',
        'Fotografie o scansioni storte della stampa',
        'File già in Excel: il tracciato si costruisce dal PDF',
    ],

    'specifiche' => [
        'Ingresso'      => 'PDF · max 50 MB · 10.000 pagine',
        'Colonne lette' => '23 · 25',
        'Uscita'        => 'XLSX · 32 colonne · o CSV',
        'Granularità'   => '1 riga = 1 prenotazione',
        'Motore'        => 'deterministico',
        'Chiave'        => 'N°pren.',
    ],

    'eccezioni' => [
        ['titolo' => 'Le due stampe si riconoscono dal titolo.', 'testo' => 'Quello che cambia sono le colonne, non il metodo: le bande si ricavano sempre dalle coordinate della testata. Se carichi una stampa che non è nessuna delle due, viene detto quali si accettano.'],
        ['titolo' => 'Categoria Camera dipende dalla stampa.', 'testo' => 'La <em>Stampa prenotazioni</em> la dichiara nella colonna Tipo camera e si prende quella. La <em>Stampa clienti presenti</em> non ce l\'ha: si deduce dai Commenti solo se attivi la regola opzionale, altrimenti esce vuota.'],
        ['titolo' => 'Importi solo sulla riga capofila.',        'testo' => 'Nel gruppo, Importo, Supplementi, Caparre e Acconti stanno sulla prima riga: vengono portati sulla prenotazione, non divisi per ospite.'],
        ['titolo' => 'Le fasce d\'età dipendono dalla stampa.', 'testo' => 'La <em>Stampa prenotazioni</em> le dichiara in tre colonne — A, B, I — e allora si leggono e basta. Nella <em>Stampa clienti presenti</em> non ci sono: gli adulti sono le righe ospite del gruppo, e il supplemento «Letto agg. Bambino» può contare un bambino solo se attivi la regola opzionale.'],
        ['titolo' => 'Telefoni troncati.',                       'testo' => 'La stampa taglia la colonna Telefono («349 674»): il numero incompleto si segnala, non si inventa.'],
        ['titolo' => 'Commenti OTA con tag HTML.',               'testo' => 'Booking ed Expedia stampano <span class="mono">&amp;lt;b&amp;gt;</span> e a capo dentro il testo; il commento è ripetuto su ogni ospite del gruppo e va tenuto solo sull\'intestatario.'],
        ['titolo' => 'Tassa soggiorno, Sconto e Convenzione',    'testo' => 'non hanno colonna nel tracciato Scidoo: finiscono trascritte in Note.'],
        ['titolo' => 'Numero camera dalla stampa.',              'testo' => 'Octorate stampa il numero nella colonna Cam. (7, 8, 9…): se il campo è vuoto la camera esce vuota, mai a zero.'],
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
        ['da' => 'Importo · Supplementi · Caparre · Acconti','a' => 'Prezzo Retta · Prezzo Extra · Caparra · Acconto', 'regola' => 'Dalla riga capofila del gruppo'],
        ['da' => 'Commenti',                                'a' => 'Note · Note Ota',                           'regola' => 'Tag HTML e <span class="mono">&amp;lt;b&amp;gt;</span> ripuliti; testo OTA separato'],
        ['da' => 'Tassa sogg · Sconto · Convenzione',       'a' => null,                                        'regola' => 'Non previste dal tracciato → finiscono in Note'],
    ],

    'regole_opzionali' => [
        ['chiave' => 'una_riga_per_prenotazione', 'titolo' => 'Una riga per prenotazione', 'nota' => 'Se togli la spunta esce una riga per ospite', 'default' => true],
        ['chiave' => 'ripulisci_commenti',        'titolo' => 'Ripulisci i commenti OTA',  'nota' => 'Toglie i tag HTML di Booking ed Expedia',    'default' => true],
        ['chiave' => 'bambini_da_supplementi',    'titolo' => 'Deduci i bambini dai Supplementi', 'nota' => 'Conta un bambino per ogni «Letto agg. Bambino». Nel file di esempio compare in 522 prenotazioni su 584: da attivare solo se è davvero un ospite.', 'default' => false],
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
    'lede_upload'         => 'Va bene la <em>Stampa clienti presenti</em> o la <em>Stampa prenotazioni</em>, in PDF, esattamente come le produce Octorate — niente ritagli né stampe parziali. Quale delle due sia lo capisce da sola.',
    'titolo_eccezioni'    => 'Le sette eccezioni note di questo tracciato',
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
