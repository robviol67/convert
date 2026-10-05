<?php
declare(strict_types=1);

use Vblite\Convert\Conversioni\OctoScidoo\ScrittoreScidoo;

/**
 * Manifest della tipologia Octo prenotazioni → Scidoo.
 *
 * La stampa è un'altra rispetto a quella dei clienti presenti, e i testi lo
 * dicono: chi carica deve sapere quale export gli serve prima di cercarlo in
 * Octorate.
 */
return [
    'chiave'      => 'octo_prenotazioni',
    'titolo'      => 'Octo prenotazioni → Scidoo',
    'sottotitolo' => 'Stampa prenotazioni Octorate (PDF) → File Import Prenotazioni (XLSX)',
    'attiva'      => true,
    'descrizione' => 'Converte la <strong>Stampa prenotazioni</strong> di <strong>Octorate</strong> nel tracciato '
        . 'di import di <strong>Scidoo</strong>. È la stampa delle prenotazioni che <strong>non hanno ancora '
        . 'fatto il check-in</strong>: una riga per prenotazione, con telefono, e-mail, data d\'inserimento e '
        . 'scadenza dell\'opzione. Per i clienti già arrivati e partiti c\'è la tipologia <em>Octo clienti → '
        . 'Scidoo</em>, che legge l\'altra stampa.',

    'si_puo' => [
        '<em>Stampa prenotazioni</em> di Octorate, in PDF, con la testata e le colonne originali',
        'Adulti, bambini e neonati come li dichiara la stampa, nelle colonne <span class="mono">A · B · I</span>',
        'Telefono, e-mail, data d\'inserimento e scadenza dell\'opzione',
        'Qualunque periodo: le stampe lunghe si leggono a tappe',
        'Prenotazioni dirette e da OTA: Booking.com, Quick Booking, Expedia',
        'Prenotazioni su più camere: una riga per camera, con gli importi di ciascuna',
    ],
    'non_si_puo' => [
        'La <em>Stampa clienti presenti</em>: quella va in <em>Octo clienti → Scidoo</em>',
        'Le altre stampe Octorate (listini, disponibilità, statistiche): se te ne serve una, si aggiunge',
        'PDF ritagliati, uniti a mano o stampati con colonne nascoste',
        'Fotografie o scansioni storte della stampa',
    ],

    'specifiche' => [
        'Ingresso'      => 'PDF · max 50 MB · 10.000 pagine',
        'Colonne lette' => '25',
        'Uscita'        => 'XLSX · 32 colonne · o CSV',
        'Granularità'   => '1 riga = 1 camera di 1 prenotazione',
        'Motore'        => 'deterministico',
        'Chiave'        => 'N°pren.',
    ],

    'eccezioni' => [
        ['titolo' => 'È la stampa delle prenotazioni, non dei clienti.', 'testo' => 'Qui ci sono le prenotazioni che non hanno fatto il check-in. Se carichi la <em>Stampa clienti presenti</em> viene rifiutata, dicendoti dove portarla: le colonne sono diverse e il risultato sarebbe sbagliato.'],
        ['titolo' => 'L\'intestazione cambia da struttura a struttura.', 'testo' => 'Il numero di prenotazione è scritto <span class="mono">N°pren.</span> in un pezzo solo o spezzato in tre; le colonne si ricavano comunque dalle coordinate della testata, che si ripete a ogni pagina.'],
        ['titolo' => 'Le fasce d\'età sono dichiarate.', 'testo' => 'Le colonne <span class="mono">A · B · I</span> dicono adulti, bambini e neonati: si leggono e basta, senza dedurre niente dai supplementi.'],
        ['titolo' => 'Categoria Camera esce vuota.', 'testo' => 'Il «Tipo camera» di questa stampa è testo libero — «tripla XXX», «matrimoniale Superior» — e non sono le categorie di Scidoo: entra solo se lo chiedi con la regola opzionale.'],
        ['titolo' => 'Su più camere fa più righe.', 'testo' => 'In Scidoo una riga è un soggiorno in una camera. La prenotazione esce una volta per camera — stesso numero, stesso cliente, stesse date — e ogni riga porta gli importi della sua.'],
        ['titolo' => 'Telefoni troncati.', 'testo' => 'La colonna Telefono è stretta e taglia: un numero incompleto ma riconoscibile si importa e si segnala, un prefisso rimasto da solo («06», «0341») non si importa — non è un numero.'],
        ['titolo' => 'Note interne così come sono.', 'testo' => 'La colonna Note interne finisce in Note, su più righe se la stampa le spezza. Sconto %, Sconto valore e Predisposizione restano fuori, come chiesto.'],
    ],

    'regole_conversione' => [
        ['da' => 'N°pren.',                      'a' => 'ID',                                         'regola' => 'Punto delle migliaia rimosso: <span class="mono">81.001 → 81001</span>'],
        ['da' => 'Cognome · Nome',               'a' => 'Cognome / Nome Cliente',                     'regola' => 'Dalla colonna anagrafica, che impila anche telefono ed e-mail'],
        ['da' => 'Telefono · eMail',             'a' => 'Telefono · Cellulare · Email Cliente',        'regola' => 'Fisso e mobile distinti; i prefissi monchi restano fuori'],
        ['da' => 'Arrivo · Partenza',            'a' => 'Data di Arrivo / Partenza',                  'regola' => 'Data vera, non testo'],
        ['da' => 'A · B · I',                    'a' => 'Adulti · Bambini · Neonati',                 'regola' => 'Dichiarati dalla stampa, non dedotti', 'accento' => true],
        ['da' => 'Data + Ora',                   'a' => 'Data Inserimento',                           'regola' => 'Anno a 2 cifre completato'],
        ['da' => 'Data opzione',                 'a' => 'Stato Prenotazione + Data Scadenza Opzione', 'regola' => 'Se presente → <em>Opzione</em>, altrimenti <em>Confermata con Pagamento</em>'],
        ['da' => 'Agenzia pagante / prenotante', 'a' => 'Agenzia · Voucher Agenzia',                  'regola' => 'Booking.com · Quick Booking · diretta'],
        ['da' => 'Trattamento',                  'a' => 'Retta · Servizio Iniziale',                  'regola' => 'Bed & Breakfast → Bed & Breakfast + Pernotto'],
        ['da' => 'Cam. con più numeri',          'a' => 'Una riga per camera',                        'regola' => 'Stessa prenotazione, stesse date, camera diversa', 'accento' => true],
        ['da' => 'Importo · Caparre · Acconti',  'a' => 'Prezzo Retta · Caparra · Acconto',           'regola' => 'Seguono la camera a cui sono scritti'],
        ['da' => 'Note interne',                 'a' => 'Note · Note Ota',                            'regola' => 'Tag HTML ripuliti; testo OTA separato'],
        ['da' => 'Convenzione',                  'a' => null,                                         'regola' => 'Non prevista dal tracciato → in Note, se dice altro dal Trattamento'],
        ['da' => 'Sconto % · Sconto valore · Tipo camera · Predisposizione', 'a' => null,              'regola' => 'Lasciati fuori su richiesta'],
    ],

    'regole_opzionali' => [
        ['chiave' => 'camere_su_righe_separate',  'titolo' => 'Una riga per camera', 'nota' => 'Una prenotazione su più camere esce in una riga per camera, ognuna con gli importi della sua. Se la togli, le camere finiscono tutte in una cella sola e resta il solo importo della prima.', 'default' => true],
        ['chiave' => 'categoria_da_tipo_camera',  'titolo' => 'Categoria Camera dal «Tipo camera»', 'nota' => 'È testo libero e non una categoria di Scidoo: di suo resta fuori.', 'default' => false],
        ['chiave' => 'ripulisci_commenti',        'titolo' => 'Ripulisci le note OTA',  'nota' => 'Toglie i tag HTML di Booking ed Expedia', 'default' => true],
        ['chiave' => 'salta_annullate',           'titolo' => 'Salta le prenotazioni annullate', 'nota' => null, 'default' => true],
    ],

    'formati_uscita'      => ['xlsx' => 'XLSX', 'csv' => 'CSV'],
    'estensioni_ingresso' => ['pdf'],
    'nome_uscita'         => 'File Import Prenotazioni',
    'max_pagine'          => 10000,
    'ha_periodo'          => true,

    'lessico' => [
        'unita'          => 'prenotazione',
        'unita_plurale'  => 'prenotazioni',
        'origine'        => 'righe della stampa',
        'pronto'         => '%s prenotazioni, pronte per Scidoo.',
        'sommario'       => '%1$s righe della stampa in %2$s prenotazioni, 32 colonne, date come date e importi come valuta.',
        'anteprima'      => 'Prime righe del tracciato',
        'passi'          => [
            'Stampa prenotazioni riconosciuta',
            'Righe estratte dalle coordinate',
            'Adulti, bambini e neonati letti da A · B · I',
            'Camere su righe separate',
            'Pulizia delle note',
            'Scrittura tracciato Scidoo · 32 colonne',
        ],
    ],
    'invito_upload'       => "Trascina la Stampa prenotazioni in quest'area",
    'titolo_upload'       => 'Carica la Stampa prenotazioni',
    'lede_upload'         => 'Serve l\'export <em>Stampa prenotazioni</em> di Octorate, in PDF, esattamente come lo '
        . 'produce — niente ritagli né stampe parziali. Per i clienti arrivati e partiti c\'è l\'altra tipologia.',
    'titolo_eccezioni'    => 'Cosa sapere di questa stampa',
    'titolo_colonne'      => 'Colonne in uscita',
    'titolo_regole'       => 'Regole di conversione · Stampa prenotazioni → 32 colonne Scidoo',
    'nota_informative'    => 'Voci risolte dalle regole del tracciato e riportate qui solo per trasparenza:',
    'titolo_rivedere'     => "Da rivedere prima dell'import",
    'etichetta_chiave'    => 'N°pren.',
    'etichetta_colonna'   => 'Colonna Scidoo',
    'avviso_rivedere'     => 'Sono già nel file, ma con un valore incerto: Scidoo le accetterebbe sbagliate. Correggi qui e il foglio si riscrive.',
    'colonna_da'          => 'Octorate',
    'colonna_a'           => 'Scidoo',
    'colonne_uscita'      => array_values(array_filter(
        array_map('trim', (new ScrittoreScidoo())->testate()),
        static fn(string $t): bool => !str_starts_with($t, '*')
    )),
    'nota_uscita'         => 'Intestazioni e ordine colonne dal <span class="mono">File Import Prenotazioni</span> di Scidoo.',

    'sorgenti_camera' => [
        'cam'    => 'Dalla colonna Cam.',
        'gruppo' => 'Dalla colonna Gruppo',
        'vuoto'  => 'Lascia vuoto',
    ],
];
