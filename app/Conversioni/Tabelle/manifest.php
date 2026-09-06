<?php
declare(strict_types=1);

use Vblite\Convert\Conversioni\Tabelle\ConversioneTabelle;

/** Manifest della tipologia Tabella → Tabella. */
return [
    'chiave'      => 'tabelle',
    'titolo'      => 'Tabella → Tabella',
    'sottotitolo' => 'CSV e Excel, con le colonne mappate come vuoi tu',
    'attiva'      => true,
    'descrizione' => 'Prende un <strong>CSV</strong> o un <strong>XLSX</strong> e ne produce un altro con le '
        . 'colonne che servono: rinominate, riordinate, scartate. Puoi anche aggiungere colonne con un '
        . '<strong>valore fisso</strong>, uguale per tutte le righe.',

    'si_puo' => [
        'Rinominare, riordinare e scartare colonne',
        'Aggiungere colonne con un valore fisso — un codice, un tag, una data di importazione',
        'Dire di che tipo è ogni colonna: testo, numero, data, valuta',
        'Salvare la mappatura come preset e riusarla sul prossimo file',
        'Da CSV a XLSX e viceversa, con il separatore riconosciuto da solo',
    ],
    'non_si_puo' => [
        'Calcoli fra colonne, formule, condizioni',
        'Unire due file o incrociare tabelle diverse',
        'Fogli multipli: si legge il primo',
        'Celle unite, filtri, grafici e formattazione condizionale',
    ],

    'specifiche' => [
        'Ingresso'     => 'CSV · TSV · XLSX',
        'Uscita'       => 'XLSX · CSV',
        'Dimensione'   => 'max ' . (ConversioneTabelle::MAX_BYTE / 1048576) . ' MB',
        'Intestazioni' => 'prima riga',
        'Fogli'        => 'il primo',
        'Motore'       => 'deterministico',
    ],

    'eccezioni' => [
        ['titolo' => 'La prima riga sono le intestazioni.', 'testo' => 'Servono a dare un nome alle colonne nella mappatura. Se il file non le ha, le colonne prendono un nome di comodo («Colonna 1»).'],
        ['titolo' => 'Le date di Excel sono numeri.', 'testo' => 'Nel file XLSX una data è il numero dei giorni dal 1899: si riconosce dal formato della cella e si riscrive leggibile. Se una colonna esce come <span class="mono">45658</span>, quella cella non era formattata come data.'],
        ['titolo' => 'Il separatore del CSV si indovina.', 'testo' => 'Punto e virgola, virgola, tabulazione o barra verticale: si sceglie quello più frequente nella prima riga. In Italia è quasi sempre il punto e virgola, perché la virgola è già il separatore decimale.'],
        ['titolo' => 'Le colonne vuote non spostano le altre.', 'testo' => 'Un XLSX salta le celle vuote invece di scriverle: si legge il riferimento di ogni cella, altrimenti una riga con un buco slitterebbe di una colonna.'],
        ['titolo' => 'Il valore fisso è uguale per tutte le righe.', 'testo' => 'Non è una formula e non guarda il contenuto: se serve un valore diverso a seconda della riga, questa tipologia non lo fa.'],
        ['titolo' => 'Si legge un foglio solo.', 'testo' => 'Il primo dichiarato dal file — non il primo in ordine di zip, che non vuol dire niente.'],
        ['titolo' => 'Le mappature si salvano.', 'testo' => 'Una volta impostata, la mappatura si salva come preset: se il fornitore manda lo stesso tracciato ogni mese, la seconda volta è un clic.'],
    ],

    'regole_conversione' => [
        ['da' => 'Colonna del file',        'a' => 'Colonna in uscita', 'regola' => 'Scegli quale, o lasciala fuori'],
        ['da' => 'Nome della colonna',      'a' => 'Nuovo nome',        'regola' => 'Si rinomina senza toccare i dati'],
        ['da' => '(valore fisso)',          'a' => 'Colonna nuova',     'regola' => 'Lo stesso valore su tutte le righe', 'accento' => true],
        ['da' => 'Ordine delle colonne',    'a' => 'Ordine in uscita',  'regola' => 'Quello della mappatura'],
        ['da' => 'Date di Excel',           'a' => 'Date leggibili',    'regola' => 'Il seriale si riconosce dal formato della cella'],
        ['da' => 'Colonne non mappate',     'a' => null,                'regola' => 'Restano fuori dal file prodotto'],
    ],

    'regole_opzionali' => [
        ['chiave' => 'salta_righe_vuote', 'titolo' => 'Salta le righe che escono vuote', 'nota' => 'Utile quando si tengono poche colonne e il resto del file è rumore.', 'default' => true],
    ],

    'campi'  => [],
    'scelte' => [],

    'formati_uscita'      => ['xlsx' => 'XLSX', 'csv' => 'CSV'],
    'estensioni_ingresso' => ['csv', 'tsv', 'xlsx'],
    'nome_uscita'         => null,
    'max_pagine'          => 0,
    'ha_periodo'          => false,
    'ha_mappatura'        => true,

    'invito_upload'    => 'Trascina il CSV o l\'XLSX in quest\'area',
    'titolo_upload'    => 'Carica la tabella',
    'lede_upload'      => 'Un <span class="mono">.csv</span> o un <span class="mono">.xlsx</span>, con le intestazioni nella prima riga. Le colonne si mappano al passo dopo.',
    'titolo_eccezioni' => 'Cosa sapere prima di mappare',
    'titolo_colonne'   => 'Tipi di colonna disponibili',
    'colonne_uscita'   => ['Testo', 'Numero', 'Intero', 'Data', 'Valuta'],
    'titolo_regole'    => 'Come funziona la mappatura',
    'colonna_da'       => 'Nel file',
    'colonna_a'        => 'In uscita',
    'nota_uscita'      => 'L\'XLSX porta i tipi di colonna; il CSV è testo e basta.',
    'nota_informative' => 'Deciso dalla mappatura e riportato qui per trasparenza:',
    'titolo_rivedere'  => 'Colonne che non tornano',
    'etichetta_chiave' => 'Colonna',
    'etichetta_colonna'=> 'In uscita',
    'avviso_rivedere'  => 'La mappatura cita colonne che in questo file non ci sono: escono vuote. Succede riusando un preset su un tracciato diverso.',

    'lessico' => [
        'unita'         => 'riga',
        'unita_plurale' => 'righe',
        'origine'       => 'righe lette',
        'pronto'        => '%s righe, pronte.',
        'sommario'      => '%1$s righe lette, %2$s scritte con le colonne che hai scelto.',
        'anteprima'     => 'Prime righe del risultato',
        'passi'         => [
            'File riconosciuto',
            'Intestazioni lette',
            'Mappatura applicata',
            'Valori fissi aggiunti',
            'Tipi di colonna assegnati',
            'Scrittura del file',
        ],
    ],
];
