<?php
declare(strict_types=1);

use Vblite\Convert\Conversioni\Documenti\ConversioneDocumenti;
use Vblite\Convert\Conversioni\Documenti\Formati;

/**
 * Manifest della tipologia Documenti ↔ Markdown.
 * Alimenta la tessera nella home e la scheda dello step 1.
 */
$inIngresso = Formati::formatiInIngresso();
$inUscita   = Formati::formatiInUscita();

return [
    'chiave'      => 'documenti_md',
    'titolo'      => 'Documenti ↔ Markdown',
    'sottotitolo' => 'Word, PDF, RTF e testo verso Markdown — e ritorno',
    'attiva'      => true,
    'descrizione' => 'Converte documenti fra <strong>Word (.docx)</strong>, <strong>PDF</strong>, '
        . '<strong>RTF</strong>, <strong>testo semplice</strong> e <strong>Markdown</strong>, in qualunque '
        . 'direzione. Tiene titoli, elenchi, tabelle, grassetto e corsivo; le immagini vengono estratte a fianco.',

    'si_puo' => [
        'Word <span class="mono">.docx</span> → Markdown: titoli, elenchi, tabelle, collegamenti e immagini',
        'Markdown → Word, PDF o RTF, con stili veri e non solo testo',
        'PDF → Markdown: il testo si recupera, la struttura si deduce',
        'RTF di Word, Pages o TextEdit, nei due sensi',
        'Testo semplice, con gli elenchi scritti a mano riconosciuti',
    ],
    'non_si_puo' => [
        'Word 97-2003 <span class="mono">.doc</span>: aprilo in Word e salvalo come <span class="mono">.docx</span>',
        'PDF fatti di scansioni: senza riconoscimento ottico non c\'è testo da leggere',
        'Impaginazione: colonne, cornici, testo attorno alle figure',
        'Note a piè di pagina, revisioni, commenti e campi calcolati',
    ],

    'specifiche' => [
        'Ingresso'      => strtoupper(implode(' · ', array_keys($inIngresso))),
        'Uscita'        => strtoupper(implode(' · ', array_keys($inUscita))),
        'Dimensione'    => 'max ' . (ConversioneDocumenti::MAX_BYTE / 1048576) . ' MB',
        'Immagini'      => 'estratte in media/',
        'Motore'        => 'deterministico',
        'Granularità'   => '1 blocco = 1 titolo, paragrafo, elenco…',
    ],

    'eccezioni' => [
        ['titolo' => 'Un PDF non ha struttura.', 'testo' => 'Contiene la stampa di un documento, non il documento: niente titoli né paragrafi, solo testo posizionato. Quello che somiglia a una struttura viene dedotto dal corpo del carattere, e dichiarato.'],
        ['titolo' => 'L\'RTF dipende da chi l\'ha scritto.', 'testo' => 'Quello di Word o TextEdit ha struttura e si converte bene; quello di un generatore di stampe posiziona ogni pezzo in modo assoluto, e lì si recupera il testo e poco altro.'],
        ['titolo' => 'Le immagini escono a fianco.', 'testo' => 'Verso Markdown o testo semplice il risultato è uno <span class="mono">.zip</span> con il documento e la cartella <span class="mono">media/</span>: un file solo che rimanda a immagini che non hai sarebbe inutile.'],
        ['titolo' => 'Il PDF in uscita incorpora solo i JPEG.', 'testo' => 'Gli altri formati andrebbero decodificati e ricompressi, e la memoria dell\'hosting non basta: al loro posto resta una nota.'],
        ['titolo' => 'Il PDF in uscita usa i font standard.', 'testo' => 'Helvetica e Courier, che ogni lettore ha già: il file resta leggero, ma copre gli alfabeti dell\'Europa occidentale. Greco o cirillico vanno in un altro formato.'],
        ['titolo' => 'I titoli di Word si riconoscono dagli stili.', 'testo' => 'Se il documento usa il grassetto al posto dello stile «Titolo 1», il titolo non viene riconosciuto: è scritto come testo, e come testo esce.'],
        ['titolo' => 'Word 97-2003 non si legge.', 'testo' => 'È un formato binario e non esiste una libreria PHP affidabile per leggerlo. Meglio dirlo che restituire un testo a pezzi senza avvisare.'],
    ],

    'regole_conversione' => [
        ['da' => 'Stili «Titolo 1-6»',         'a' => '# ## ###',            'regola' => 'Riconosciuti in italiano, inglese, francese, spagnolo e tedesco'],
        ['da' => 'Grassetto · Corsivo',        'a' => '** · *',              'regola' => 'Tenuti anche a metà parola'],
        ['da' => 'Elenchi puntati e numerati', 'a' => '- · 1.',              'regola' => 'Con i rientri, fino a nove livelli'],
        ['da' => 'Tabelle',                    'a' => 'Tabella Markdown',    'regola' => 'Prima riga come intestazione', 'accento' => true],
        ['da' => 'Collegamenti',               'a' => '[testo](url)',        'regola' => 'Presi dalle relazioni del documento'],
        ['da' => 'Carattere a spaziatura fissa', 'a' => '`codice`',          'regola' => 'Consolas, Courier, Menlo e simili'],
        ['da' => 'Immagini',                   'a' => '![](media/…)',        'regola' => 'Estratte a fianco, in uno zip'],
        ['da' => 'Note, revisioni, commenti',  'a' => null,                  'regola' => 'Non previsti dal modello → si perdono, e viene detto'],
    ],

    'regole_opzionali' => [],

    /** I formati fra cui scegliere in uscita: li dichiara il registro dei formati. */
    'formati_uscita'      => $inUscita,
    'estensioni_ingresso' => array_keys($inIngresso),
    // Il file prodotto tiene il nome di quello caricato.
    'nome_uscita'         => null,
    // Nessun limite di pagine: qui «pagine» sarebbe il numero di blocchi, e un
    // documento di venti cartelle ne ha ben più di cinquecento.
    'max_pagine'          => 0,
    'ha_periodo'          => false,

    'lessico' => [
        'unita'          => 'blocco',
        'unita_plurale'  => 'blocchi',
        'origine'        => 'blocchi letti',
        'pronto'         => 'Documento convertito, %s blocchi.',
        'sommario'       => '%2$s fra titoli, paragrafi, elenchi e tabelle, con grassetto e corsivo al loro posto.',
        'anteprima'      => 'Cosa contiene il documento',
        'passi'          => [
            'Formato riconosciuto',
            'Testo e struttura estratti',
            'Titoli, elenchi e tabelle riconosciuti',
            'Immagini estratte',
            'Tratti di formattazione conservati',
            'Scrittura nel formato scelto',
        ],
    ],
    'invito_upload'       => 'Trascina il documento in quest\'area',
    'nota_uscita'         => 'Se il documento porta immagini e scegli Markdown o testo, il risultato è uno <span class="mono">.zip</span> con la cartella <span class="mono">media/</span>.',
];
