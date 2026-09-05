<?php
declare(strict_types=1);

/**
 * Schema delle colonne del tracciato Scidoo «File Import Prenotazioni».
 *
 * L'ordine di questo array e' l'ordine delle colonne nel foglio, a partire da B
 * (la colonna A resta libera: nel file di esempio ospita l'etichetta ESEMPIO).
 * Le intestazioni sono trascritte alla lettera dal file del cliente, spazi finali
 * compresi, perche' l'import di Scidoo le confronta come stringhe.
 *
 * Per aggiungere le fasce d'eta' (nota in R1 del file originale) basta inserire
 * qui altre voci fra «neonati» e «retta»: scrittore e mappatura le seguono.
 */
return [
    // Larghezza 8 invece dei 3,83 del file del cliente: li' gli ID di esempio
    // avevano tre cifre, i N°pren. veri ne hanno quattro o cinque e Excel
    // mostrerebbe «###». E' un'indicazione di visualizzazione, non un dato: il
    // valore importato non cambia.
    ['chiave' => 'id',                'testata' => 'ID',                     'tipo' => 'intero',  'larghezza' => 8],
    ['chiave' => 'nome',              'testata' => 'Nome Cliente',           'tipo' => 'testo',   'larghezza' => 12.33],
    ['chiave' => 'cognome',           'testata' => 'Cognome Cliente',        'tipo' => 'testo',   'larghezza' => 15.33],
    ['chiave' => 'arrivo',            'testata' => 'Data di Arrivo',         'tipo' => 'data',    'larghezza' => 13],
    ['chiave' => 'partenza',          'testata' => 'Data di Partenza',       'tipo' => 'data',    'larghezza' => 15.16],
    ['chiave' => 'camera',            'testata' => 'Camera',                 'tipo' => 'testo',   'larghezza' => 7.16],
    ['chiave' => 'categoria_camera',  'testata' => 'Categoria Camera',       'tipo' => 'testo',   'larghezza' => 18.66],
    ['chiave' => 'agenzia',           'testata' => 'Agenzia',                'tipo' => 'testo',   'larghezza' => 11.16],
    ['chiave' => 'voucher',           'testata' => 'Voucher Agenzia',        'tipo' => 'testo',   'larghezza' => 15.16],
    ['chiave' => 'data_inserimento',  'testata' => 'Data Inserimento',       'tipo' => 'data',    'larghezza' => 15.66],
    ['chiave' => 'stato',             'testata' => 'Stato Prenotazione',     'tipo' => 'testo',   'larghezza' => 23.66],
    ['chiave' => 'scadenza_opzione',  'testata' => 'Data Scadenza Opzione',  'tipo' => 'data',    'larghezza' => 21],
    ['chiave' => 'data_annullamento', 'testata' => 'Data Annullamento',      'tipo' => 'data',    'larghezza' => 17.83],
    ['chiave' => 'adulti',            'testata' => 'Adulti',                 'tipo' => 'intero',  'larghezza' => 6.16],
    ['chiave' => 'bambini',           'testata' => 'Bambini',                'tipo' => 'intero',  'larghezza' => 7.83],
    ['chiave' => 'neonati',           'testata' => 'Neonati',                'tipo' => 'intero',  'larghezza' => 7.66],
    ['chiave' => 'fasce_eta',         'testata' => '*se serve creare tante colonne in base alle fasce di età create in Scidoo',
                                                                            'tipo' => 'testo',   'larghezza' => 14.83],
    ['chiave' => 'retta',             'testata' => 'Retta',                  'tipo' => 'testo',   'larghezza' => 16.66],
    ['chiave' => 'prezzo_retta',      'testata' => 'Prezzo Retta',           'tipo' => 'valuta',  'larghezza' => 11.5],
    ['chiave' => 'prezzo_extra',      'testata' => 'Prezzo Extra',           'tipo' => 'valuta',  'larghezza' => 11.16],
    ['chiave' => 'acconto',           'testata' => 'Acconto',                'tipo' => 'valuta',  'larghezza' => 8],
    ['chiave' => 'caparra',           'testata' => 'Caparra',                'tipo' => 'valuta',  'larghezza' => 7.83],
    ['chiave' => 'data_acconto',      'testata' => 'Data Acconto ',          'tipo' => 'data',    'larghezza' => 13.16],
    ['chiave' => 'data_caparra',      'testata' => 'Data Caparra',           'tipo' => 'data',    'larghezza' => 12],
    ['chiave' => 'note',              'testata' => 'Note ',                  'tipo' => 'testo',   'larghezza' => 5.66],
    ['chiave' => 'note_pulizie',      'testata' => 'Note Pulizie',           'tipo' => 'testo',   'larghezza' => 14],
    ['chiave' => 'note_ota',          'testata' => 'Note Ota',               'tipo' => 'testo',   'larghezza' => 14],
    ['chiave' => 'note_ristorante',   'testata' => 'Note Ristorante',        'tipo' => 'testo',   'larghezza' => 16],
    ['chiave' => 'servizio_iniziale', 'testata' => 'Servizio Iniziale',      'tipo' => 'testo',   'larghezza' => 17],
    ['chiave' => 'telefono',          'testata' => 'Telefono Cliente',       'tipo' => 'testo',   'larghezza' => 16],
    ['chiave' => 'cellulare',         'testata' => 'Cellulare Cliente',      'tipo' => 'testo',   'larghezza' => 17],
    ['chiave' => 'email',             'testata' => 'Email Cliente',          'tipo' => 'testo',   'larghezza' => 24],
];
