# vblite /convert

Strumento interno di Insert Srl per la conversione di tracciati dati.
Oggi una sola tipologia: **Octo → Scidoo**, dalla stampa prenotazioni di Octorate
(PDF) al *File Import Prenotazioni* di Scidoo (XLSX).

Architettura scelta: **PHP 8 + SQLite** (opzione A del documento di consegna),
con la logica di conversione isolata dietro l'interfaccia `Conversione`, così che
un domani si possa spostare tutto su Node o in-browser senza riscrivere le schermate.

---

## Avvio in locale

```bash
php composer.phar install
CONVERT_PASS_ROBVIOL='…' CONVERT_PASS_CLAUDIO='…' php bin/installa.php
php -S 127.0.0.1:8899 -t .
```

Poi `http://127.0.0.1:8899/?p=accesso`.

Se non passi le password in ambiente, l'installatore ne genera due casuali e le
stampa una volta sola.

## Verifiche

```bash
php tests/prova.php
```

59 verifiche, senza dipendenze esterne. Le attese vengono dai due file di esempio
del committente: 201 pagine, 1.174 righe cliente, 584 prenotazioni, e le
intestazioni del tracciato confrontate colonna per colonna con il file vero.

```bash
php bin/diagnostica.php
```

Dice se l'hosting regge: estensioni, limiti di upload, tempo di esecuzione,
cartelle scrivibili. Sul server, dove non c'è SSH, gli stessi controlli stanno
in `?p=diagnostica`.

```bash
php bin/prova-conversione.php [ingresso.pdf] [uscita.xlsx]
```

Converte un PDF da riga di comando e riassume le anomalie per colonna.

---

## Com'è fatto

```
index.php                  router: una pagina per ogni schermata
app/
  Config.php               percorsi e limiti
  Database.php             connessione SQLite e schema
  Auth.php                 accesso, sessione, gettone anti-CSRF, freno ai tentativi
  Job.php                  ciclo di vita di una conversione
  Correzioni.php           riscrittura del foglio dopo le correzioni a mano
  Vista.php                rendering e formattazione
  Conversioni/
    Conversione.php        l'interfaccia che ogni tipologia implementa
    Registro.php           registro delle tipologie: alimenta home e scheda
    OctoScidoo/
      manifest.php         titoli, eccezioni, regole: i testi dell'interfaccia
      colonne.php          schema delle 32 colonne in uscita
      Parser.php           PDF → righe ospite
      Raggruppatore.php    righe ospite → prenotazioni + anomalie
      Normalizza.php       date, importi, telefoni, agenzie, commenti
      ScrittoreScidoo.php  prenotazioni → XLSX (o CSV)
views/                     le nove schermate
public/css/                design system Broadsheet + stili dell'applicazione
data/convert.db            database (protetto da .htaccess)
storage/in · storage/out   i file, che non scadono mai
```

### Aggiungere una tipologia di conversione

1. Crea `app/Conversioni/LaTua/` con `manifest.php` e una classe che implementa
   `Conversione`.
2. Aggiungi la classe a `Registro::CLASSI`.
3. Togli una voce da `Registro::IN_ARRIVO`.

Nessuna schermata va toccata: la tessera nella home e la scheda dello step 1
si costruiscono dal manifest.

### Come legge il PDF

La stampa Octorate è una tabella a colonne fisse con quattro righe logiche per
ospite. Invece di leggere il testo impaginato (fragile), il parser usa le
**coordinate**: le intestazioni si ripetono su ogni pagina e danno gli ancoraggi,
i confini di colonna stanno a metà fra due intestazioni, e le sotto-righe si
distinguono per scostamento verticale.

Risultato sul file di esempio: 201 pagine in **meno di un secondo**, 1.174 righe,
584 prenotazioni, nessun importo malformato.

Il documento di consegna stimava ~25 s: la stima era prudente.

---

## Pubblicazione su www.vblite.com/convert

L'hosting è Plesk condiviso: **niente SSH**. Il deploy è una copia di file via
FTP, con il kit **K21 · deploy-ftp** di VIOLINUX — un file alla volta, FTP
semplice, verifica con `SIZE`. Le cinque regole del kit e il perché di ognuna
stanno in testa a `deploy/carica.sh`; in breve: FTPS tronca i file a 16384 byte,
il parallelo fa scattare l'anti-hammering di ProFTPD, e `LIST` non mostra i
dotfile.

```bash
cp deploy/carica.conf.esempio deploy/carica.conf   # e riempilo
bash deploy/costruisci.sh                          # prepara _dist
bash deploy/carica.sh --tutto                      # primo giro: tutto
bash deploy/carica.sh --verifica                   # controlla che sia intero
```

Le credenziali stanno in un file `netrc` fuori dal progetto (permessi 600),
mai nel repository. Dal secondo giro in poi `bash deploy/carica.sh` carica solo
i file il cui sha è cambiato.

`_dist` contiene solo ciò che va sul server: restano fuori `tests/`, `bin/`,
`composer.phar` e — soprattutto — il database e i file convertiti, che nascono
sul server e non vanno sovrascritti.

### Primo avvio sul server

Senza SSH non si può lanciare `php bin/installa.php`. L'installazione si fa
**dal browser**: la prima visita a `/convert` porta a `?p=installa`, che mostra
lo stato dell'hosting e crea i due account. Appena esiste un utente la pagina si
chiude da sola e ogni strada porta all'accesso.

Da loggati, `?p=diagnostica` rimostra gli stessi controlli: se una conversione
non riesce, la causa è quasi sempre lì.

Requisiti: PHP ≥ 8.1 con `pdo_sqlite`, `zip`, `dom`, `mbstring`, `gd`,
`fileinfo`; `upload_max_filesize` e `post_max_size` ≥ 50 MB;
`max_execution_time` ≥ 120 s. `.htaccess` e `.user.ini` provano già ad alzare
questi limiti; se restano bassi vanno alzati dal pannello di controllo.

Se `exec()` è disabilitata (capita sugli hosting condivisi), la conversione gira
in linea nella stessa richiesta invece che in un processo distaccato: funziona
lo stesso, ma la pagina di avanzamento resta ferma finché non ha finito.

### Sicurezza

- Password come hash **Argon2id**, mai in chiaro.
- Sessione su cookie `HttpOnly`, `Secure`, `SameSite=Lax`.
- Gettone anti-CSRF su tutte le POST.
- Freno sui tentativi di accesso: 8 ogni 15 minuti per email+IP.
- `data/`, `storage/`, `app/`, `views/`, `bin/`, `vendor/` non raggiungibili dal web.
- HTTPS forzato da `.htaccess`.

**La password FTP comunicata in chat va cambiata**: è circolata in chiaro.
Non è nel repository e non deve entrarci.

---

## Cosa resta da chiarire col committente

Le prime tre nascono dai dati veri e cambiano il contenuto del file prodotto.

1. **«Letto agg. Bambino» è davvero un bambino?** *(risolto in via provvisoria)*
   Compare in **537 righe su 1.174, cioè in 522 prenotazioni su 584** — comprese
   223 prenotazioni con un solo ospite e diverse prenotazioni aziendali da una
   persona (per esempio 5.130, AMG SRL). Se fosse un bambino, il 92 % delle
   prenotazioni ne avrebbe uno, e in 223 casi il conteggio darebbe *zero adulti*.
   Sembra piuttosto la voce di un supplemento tariffario.
   **Scelta fatta: la regola è spenta di default.** Adulti = righe ospite del
   gruppo, Bambini = 0, e *Da rivedere* contiene **30 casi veri** invece di 560.
   La spunta «Deduci i bambini dai Supplementi» in 2d la riaccende: in quel caso
   303 prenotazioni escono con almeno un bambino e ognuna viene segnalata per
   conferma; nelle restanti 223 il supplemento non può descrivere gli ospiti
   elencati (uno solo) e il conteggio resta su *adulti = ospiti*.
   **Da confermare col committente**: se il supplemento è davvero un ospite in
   più, va deciso se contarlo fra gli ospiti elencati o aggiungerlo al totale.

2. **Il numero di camera sta in `Cam.`, non in `Gruppo`.**
   Nel PDF di esempio la colonna `Gruppo` è **vuota su tutte le 1.174 righe**,
   mentre `Cam.` porta i numeri 1–9. Il documento di consegna indicava `Gruppo`.
   La sorgente è scegliibile in 2d; il default è `Cam.`.

3. **`Data Acconto` e `Data Caparra` restano vuote.**
   Nel PDF le colonne `Caparre`/`Acconti` portano solo l'importo, senza data.
   Da capire da dove ricavarla.

4. **Prenotazioni annullate.**
   La stampa di esempio non ne contiene e non si sa come Octorate le marchi.
   La regola «Salta le annullate» esiste ma oggi non scarta nulla: meglio una
   riga in più da cancellare che una prenotazione persa in silenzio.

5. **Colonna R, fasce d'età.**
   Quali fasce esistono nel loro Scidoo. Lo schema colonne è già estendibile:
   basta inserire le voci in `app/Conversioni/OctoScidoo/colonne.php`.

6. **Una svista nel documento di consegna, per il verbale.**
   Il README affermava «45658 = 15/01/2025». Nel file del cliente `45658` è in
   realtà **01/01/2025**; il seriale del 15/01/2025 è 45672. Il codice segue il
   file vero, non la nota.

### Cosa i dati hanno confermato

- 201 pagine, 1.174 righe, 584 prenotazioni: esatti.
- La somma dei `Pax` dichiarati per camera coincide con il numero di righe ospite
  in **tutte** le 584 prenotazioni.
- `Trattamento` è `Pernottamento Giornaliero` in 1.171 righe; le altre 3 sono
  `Pernott. Prima Colazione` (mappato su *Bed & Breakfast* + *Pernotto*).
- I casi citati nel mockup di 2g esistono davvero: 4.313 (Cragnolini, 3 righe),
  4.278 (telefono troncato «349 674»), 4.310 (commento OTA condiviso).
