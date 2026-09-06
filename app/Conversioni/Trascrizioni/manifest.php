<?php
declare(strict_types=1);

use Vblite\Convert\Conversioni\Documenti\Formati;
use Vblite\Convert\Conversioni\Trascrizioni\ConversioneTrascrizione;

/**
 * Manifest della tipologia Trascrizioni → documento.
 *
 * I formati in uscita sono quelli dei documenti meno l'EPUB: una trascrizione
 * senza capitoli non è un libro, e offrirlo vorrebbe dire chiedere titolo,
 * autore e taglio dei capitoli per niente.
 */
$inUscita = Formati::formatiInUscita();
unset($inUscita['epub']);

return [
    'chiave'      => 'trascrizioni',
    'titolo'      => 'Trascrizioni → documento',
    'sottotitolo' => 'Sottotitoli e trascrizioni che diventano un testo da leggere',
    'attiva'      => true,
    'descrizione' => 'Prende i sottotitoli di un video — <strong>.srt</strong>, <strong>.vtt</strong>, '
        . '<strong>.sbv</strong> — o il testo della trascrizione che hai copiato, e ne fa un documento '
        . 'vero: le battute ricucite in periodi, i periodi in paragrafi, chi parla riconosciuto. '
        . 'In uscita Markdown, Word, PDF, RTF o testo semplice.',

    'si_puo' => [
        'Sottotitoli <span class="mono">.srt</span> di qualunque provenienza',
        '<span class="mono">.vtt</span> e <span class="mono">.sbv</span>, i due formati che YouTube Studio consegna',
        'Il testo copiato dal pannello «Mostra trascrizione», incollato qui sotto',
        'Sottotitoli automatici: la ripetizione a scorrimento viene tolta',
        'Chi parla, se il file lo dichiara — <span class="mono">&lt;v Nome&gt;</span>, <span class="mono">&gt;&gt; Nome:</span>, <span class="mono">NOME:</span>',
        'Marcatori di tempo tenuti a inizio paragrafo, se li vuoi',
    ],
    'non_si_puo' => [
        'Scaricare i sottotitoli da un indirizzo YouTube: non c\'è un\'interfaccia pubblica che li dia, e prenderli lo stesso è contro le condizioni del servizio',
        'Rimettere la punteggiatura dove non c\'è: i sottotitoli automatici non ne hanno, e inventarla vorrebbe dire cambiare il senso',
        'Distinguere due persone che il file non distingue',
        'Correggere quello che il riconoscimento vocale ha capito male',
    ],

    'specifiche' => [
        'Ingresso'    => 'SRT · VTT · SBV · TXT · incollato',
        'Uscita'      => strtoupper(implode(' · ', array_keys($inUscita))),
        'Dimensione'  => 'max ' . (ConversioneTrascrizione::MAX_BYTE / 1048576) . ' MB',
        'Motore'      => 'deterministico',
        'Granularità' => '1 blocco = 1 paragrafo ricostruito',
    ],

    'eccezioni' => [
        ['titolo' => 'I sottotitoli non sono un testo.', 'testo' => 'Sono battute spezzate ogni quaranta caratteri perché devono stare in fondo a uno schermo. Il lavoro di questa conversione è tutto qui: rimetterle insieme.'],
        ['titolo' => 'I paragrafi vengono ricostruiti.', 'testo' => 'Nel file di partenza non ci sono. Si chiudono al primo punto fermo dopo una certa lunghezza — e dove la punteggiatura manca, solo sulla lunghezza. È una scelta di leggibilità, non un dato del file.'],
        ['titolo' => 'I sottotitoli automatici si ripetono.', 'testo' => 'Ogni riga compare due volte, da sola e poi in testa alla successiva, per dare l\'effetto di scorrimento. Su carta è solo il doppio delle parole, e viene tolta.'],
        ['titolo' => 'Musica e applausi spariscono.', 'testo' => 'Le indicazioni fra parentesi quadre — <span class="mono">[Musica]</span>, <span class="mono">[Applausi]</span> — servono a chi non sente, non sono parole dette. Se le vuoi tenere, togli la spunta al passo dopo.'],
        ['titolo' => 'Chi parla si riconosce solo se è scritto.', 'testo' => 'Le tre notazioni in giro sono <span class="mono">&lt;v Nome&gt;</span>, <span class="mono">&gt;&gt; Nome:</span> e <span class="mono">NOME:</span>. Se il file non dice chi parla, nessuno può dedurlo.'],
        ['titolo' => 'I sottotitoli te li procuri tu.', 'testo' => 'Da YouTube Studio se il canale è vostro, o copiando la trascrizione dal pannello del video. Da qui non si scarica niente: non esiste un\'interfaccia pubblica che lo permetta.'],
    ],

    'regole_conversione' => [
        ['da' => 'Battute spezzate',     'a' => 'Periodi',      'regola' => 'Ricucite in ordine di tempo', 'accento' => true],
        ['da' => 'Periodi',              'a' => 'Paragrafi',    'regola' => 'Al punto fermo, o sulla lunghezza dove non ce n\'è'],
        ['da' => 'Ripetizione a scorrimento', 'a' => null,      'regola' => 'Tolta: è un effetto dello schermo, non testo'],
        ['da' => 'Etichette di chi parla', 'a' => 'Nome in grassetto', 'regola' => 'A capo a ogni cambio di voce'],
        ['da' => 'Marcatori di tempo',   'a' => '[12:34]',      'regola' => 'A inizio paragrafo, se li chiedi'],
        ['da' => 'Karaoke parola per parola', 'a' => null,      'regola' => 'È evidenziazione, non contenuto'],
        ['da' => '[Musica] · [Applausi]', 'a' => null,          'regola' => 'Tolti, salvo che tu li voglia'],
    ],

    'regole_opzionali' => [
        [
            'chiave'  => 'tr_pulisci',
            'titolo'  => 'Togli musica, applausi e rumori di scena',
            'nota'    => 'Le indicazioni fra parentesi: servono a chi guarda senza audio, non a chi legge.',
            'default' => true,
        ],
    ],

    'campi' => [],

    'scelte' => [
        [
            'chiave'    => 'tr_raggruppa',
            'etichetta' => 'Come raggruppare il testo',
            'nota'      => null,
            'opzioni'   => [
                'periodi'  => 'In paragrafi, ai punti fermi',
                'parlante' => 'Un paragrafo per ogni intervento',
                'battuta'  => 'Una riga per ogni battuta',
            ],
            'default'   => 'periodi',
        ],
        [
            'chiave'    => 'tr_tempi',
            'etichetta' => 'Marcatori di tempo',
            'nota'      => null,
            'opzioni'   => [
                'no'        => 'Non tenerli',
                'paragrafo' => 'A inizio paragrafo',
                'battuta'   => 'A ogni battuta',
            ],
            'default'   => 'no',
        ],
    ],

    'formati_uscita'      => $inUscita,
    'estensioni_ingresso' => ['srt', 'vtt', 'sbv', 'txt'],
    'accetta_incolla'     => true,
    'max_incolla'         => ConversioneTrascrizione::MAX_BYTE,
    'nome_uscita'         => null,
    'max_pagine'          => 0,
    'ha_periodo'          => false,

    'lessico' => [
        'unita'         => 'paragrafo',
        'unita_plurale' => 'paragrafi',
        'origine'       => 'paragrafi ricostruiti',
        'pronto'        => 'Trascrizione convertita, %s paragrafi.',
        'sommario'      => '%2$s ricostruiti dalle battute dei sottotitoli, in ordine di tempo.',
        'anteprima'     => 'Cosa contiene la trascrizione',
        'passi'         => [
            'Formato dei sottotitoli riconosciuto',
            'Battute lette in ordine di tempo',
            'Ripetizione a scorrimento tolta',
            'Chi parla riconosciuto',
            'Battute ricucite in periodi',
            'Scrittura nel formato scelto',
        ],
    ],

    'invito_upload'    => 'Trascina qui il file dei sottotitoli',
    'titolo_upload'    => 'Carica i sottotitoli, o incolla la trascrizione',
    'lede_upload'      => '<span class="mono">.srt</span>, <span class="mono">.vtt</span>, '
        . '<span class="mono">.sbv</span> o <span class="mono">.txt</span>. Se hai copiato la trascrizione '
        . 'dal pannello di YouTube, incollala nel riquadro qui sotto.',
    'titolo_eccezioni' => 'Cosa aspettarsi',
    'titolo_colonne'   => 'Cosa viene tenuto',
    'titolo_regole'    => 'Cosa succede alle battute',
    'nota_informative' => 'Quello che la conversione ha deciso da sé:',
    'titolo_rivedere'  => 'Cosa non è passato intero',
    'etichetta_chiave' => 'Dove',
    'etichetta_colonna'=> 'Formato',
    'avviso_rivedere'  => 'Non sono errori da correggere: sono cose da sapere prima di fidarti del risultato.',
    'colonna_da'       => 'Nei sottotitoli',
    'colonna_a'        => 'Nel documento',
    'colonne_uscita'   => [
        'Paragrafi', 'Periodi ricuciti', 'Chi parla', 'Marcatori di tempo',
        'Grassetto sui nomi', 'Ordine cronologico',
    ],
    'nota_uscita'      => 'Nessuna immagine: una trascrizione è solo testo.',
];
