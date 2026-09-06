<?php
declare(strict_types=1);

use Vblite\Convert\Conversioni\PdfTabella\ConversionePdfTabella;

/** Manifest della tipologia PDF tabellare → foglio di calcolo. */
return [
    'chiave'      => 'pdf_tabella',
    'titolo'      => 'PDF tabellare → Excel',
    'sottotitolo' => 'Stampe ed estratti conto in un foglio di calcolo',
    'attiva'      => true,
    'descrizione' => 'Prende un PDF che <em>sembra</em> una tabella — la stampa di un gestionale, un '
        . 'estratto conto, un listino — e ne ricava un <strong>XLSX</strong> o un <strong>CSV</strong>. '
        . 'Le colonne si deducono dall\'allineamento del testo, perché un PDF non le dichiara.',

    'si_puo' => [
        'Stampe di gestionali, dove le colonne sono allineate davvero',
        'Estratti conto e listini con una struttura regolare',
        'Documenti di molte pagine: testate e piè di pagina vengono tolti da soli',
        'Rinominare e riordinare le colonne, o aggiungerne a valore fisso',
    ],
    'non_si_puo' => [
        'PDF fatti di scansioni: senza riconoscimento ottico non c\'è testo da leggere',
        'Tabelle con celle unite o righe su più livelli',
        'Colonne troppo vicine fra loro: si fondono in una sola',
        'Testo su più colonne di pagina, come in una rivista',
    ],

    'specifiche' => [
        'Ingresso'   => 'PDF con testo',
        'Uscita'     => 'XLSX · CSV',
        'Dimensione' => 'max ' . (ConversionePdfTabella::MAX_BYTE / 1048576) . ' MB',
        'Colonne'    => 'dedotte dall\'allineamento',
        'Metodo'     => 'deterministico, ma per deduzione',
        'Testate'    => 'tolte se si ripetono',
    ],

    'eccezioni' => [
        ['titolo' => 'Un PDF non contiene tabelle.', 'testo' => 'Contiene pezzi di testo con delle coordinate. Quella che a occhio è una tabella, per il file è testo allineato — e l\'allineamento è l\'unica cosa su cui si possa lavorare.'],
        ['titolo' => 'Le colonne si trovano dove non c\'è mai niente.', 'testo' => 'Si proietta tutto il testo del documento su una riga: le fasce che restano vuote su ogni pagina sono i corridoi fra una colonna e l\'altra. Un corridoio che sopravvive a mille righe è un corridoio vero.'],
        ['titolo' => 'Colonne strette e testo lungo si fondono.', 'testo' => 'Se il testo riempie il corridoio, due colonne diventano una. La larghezza minima del corridoio è regolabile qui sotto: stringila e ne troverà di più, allargala e ne troverà di meno.'],
        ['titolo' => 'Testate e piè di pagina spariscono.', 'testo' => 'Le righe che si ripetono su almeno metà delle pagine non sono dati. Vale anche per «Pagina 1», «Pagina 2»: cambiano solo nel numero.'],
        ['titolo' => 'Una riga del PDF è una riga della tabella.', 'testo' => 'Se nel PDF un record occupa più righe — come nella stampa Octorate, dove ogni ospite ne prende quattro — qui escono righe separate. Per raggrupparle serve una tipologia che sappia com\'è fatto quel tracciato.'],
        ['titolo' => 'Guarda l\'anteprima prima di convertire.', 'testo' => 'È una conversione che deduce, e l\'unico modo onesto di consegnarla è farti vedere cosa ha capito prima di produrre il file.'],
        ['titolo' => 'Le intestazioni non si indovinano.', 'testo' => 'Se la tabella ha una riga coi nomi delle colonne, diccelo con la spunta qui sotto: indovinare male vorrebbe dire perdere una riga di dati senza avvisare. Se quei nomi si ripetono a ogni pagina — come fa quasi ogni stampa — li prendiamo da lì, e nessuna riga di dati va persa.'],
    ],

    'regole_conversione' => [
        ['da' => 'Testo allineato',        'a' => 'Colonne',           'regola' => 'Dedotte dai corridoi vuoti', 'accento' => true],
        ['da' => 'Riga del PDF',           'a' => 'Riga della tabella','regola' => 'Una a una, senza raggruppare'],
        ['da' => 'Testate ripetute',       'a' => null,                'regola' => 'Tolte: si ripetono su ogni pagina, non sono dati'],
        ['da' => 'Prima riga',             'a' => 'Intestazioni',      'regola' => 'Solo se lo dici tu'],
        ['da' => '(valore fisso)',         'a' => 'Colonna nuova',     'regola' => 'Lo stesso valore su tutte le righe'],
    ],

    'regole_opzionali' => [
        ['chiave' => 'pdf_salta_ripetute', 'titolo' => 'Togli testate e piè di pagina', 'nota' => 'Le righe che si ripetono su almeno metà delle pagine.', 'default' => true],
        ['chiave' => 'pdf_intestazioni',   'titolo' => 'La tabella ha una riga di intestazioni', 'nota' => 'Presa dalla riga ripetuta a ogni pagina, o dalla prima riga se compare una volta sola. Se non la spunti, le colonne si chiamano «Colonna 1», «Colonna 2»…', 'default' => false],
    ],

    'campi'  => [],
    'scelte' => [
        [
            'chiave'    => 'pdf_corridoio',
            'etichetta' => 'Larghezza minima del corridoio',
            'nota'      => 'Quanto spazio vuoto serve perché sia una colonna. Stringila se le colonne si fondono, allargala se ne trova troppe.',
            'opzioni'   => [
                '3'  => 'Stretta — trova più colonne',
                '6'  => 'Normale',
                '12' => 'Larga — trova meno colonne',
                '20' => 'Molto larga',
            ],
            'default'   => '6',
        ],
    ],

    'formati_uscita'      => ['xlsx' => 'XLSX', 'csv' => 'CSV'],
    'estensioni_ingresso' => ['pdf'],
    'nome_uscita'         => null,
    'max_pagine'          => 0,
    'ha_periodo'          => false,
    'ha_mappatura'        => true,

    'invito_upload'    => 'Trascina il PDF in quest\'area',
    'titolo_upload'    => 'Carica il PDF tabellare',
    'lede_upload'      => 'Un PDF <strong>con testo</strong> — non una scansione — in cui i dati sono allineati in colonne. Al passo dopo vedrai cosa ho riconosciuto, prima di convertire.',
    'titolo_eccezioni' => 'Come funziona, e dove sbaglia',
    'titolo_colonne'   => 'Tipi di colonna disponibili',
    'colonne_uscita'   => ['Testo', 'Numero', 'Intero', 'Data', 'Valuta'],
    'titolo_regole'    => 'Cosa diventa cosa',
    'colonna_da'       => 'Nel PDF',
    'colonna_a'        => 'In uscita',
    'nota_uscita'      => 'L\'XLSX porta i tipi di colonna; il CSV è testo e basta.',
    'nota_informative' => 'Come è stato letto il PDF, riportato qui per trasparenza:',
    'titolo_rivedere'  => 'Cosa controllare',
    'etichetta_chiave' => 'Punto',
    'etichetta_colonna'=> 'Riguarda',
    'avviso_rivedere'  => 'Le colonne sono dedotte dall\'allineamento del testo, non dichiarate dal PDF. Qui c\'è quello che vale la pena controllare prima di usare il file.',

    'lessico' => [
        'unita'         => 'riga',
        'unita_plurale' => 'righe',
        'origine'       => 'righe lette dal PDF',
        'pronto'        => '%s righe estratte dal PDF.',
        'sommario'      => '%1$s righe lette, %2$s scritte con le colonne riconosciute.',
        'anteprima'     => 'Prime righe del risultato',
        'passi'         => [
            'PDF aperto',
            'Corridoi fra le colonne trovati',
            'Testate e piè di pagina riconosciuti',
            'Righe distribuite nelle colonne',
            'Mappatura applicata',
            'Scrittura del foglio',
        ],
    ],
];
