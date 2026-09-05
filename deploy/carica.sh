#!/bin/bash
# K21 · deploy-ftp — carica l'albero di build su hosting condiviso, via FTP,
# un file alla volta, verificando che sia arrivato intero.
#
#   bash deploy/carica.sh                  # solo i file cambiati dall'ultimo giro
#   bash deploy/carica.sh --tutto          # tutti
#   bash deploy/carica.sh api/foo.php …    # solo quelli elencati
#   bash deploy/carica.sh --verifica       # non carica: controlla e basta
#
# Configurazione — da deploy/carica.conf (non versionato) o da ambiente:
#   FTP_HOST    IP o host FTP. Meglio l'IP: il DNS può non essere propagato.
#   FTP_ROOT    cartella remota di destinazione, es. /httpdocs o /httpdocs/app
#   FTP_NETRC   file in stile netrc con le credenziali (default: ~/.netrc)
#   DIST        cartella locale da caricare (default: _dist)
#   FTP_PAUSA   pausa fra un file e l'altro in secondi (default: 0.6)
#
# ─────────────────────────────────────────────────────────────────────────────
# LE CINQUE REGOLE, E PERCHÉ
#
# Nessuna è una preferenza di stile: ognuna viene da un modo concreto in cui
# l'hosting condiviso (Aruba/Plesk, ProFTPD) fa perdere mezze giornate.
#
# 1. FTP SEMPLICE, MAI FTPS. Con --ssl-reqd il canale dati di ProFTPD si
#    tronca: i file arrivano a 0 byte o mozzati esattamente a 16384, e curl a
#    volte esce comunque 0. È il difetto più insidioso di tutti perché il file
#    SEMBRA caricato, e te ne accorgi da un 500 tre passi dopo.
#    Prezzo da sapere e da accettare consapevolmente: le credenziali FTP
#    passano in chiaro sulla rete. Su un hosting che non offre SSH, l'alternativa
#    non è «FTPS», è «file corrotti in produzione».
#
# 2. UNO ALLA VOLTA, MAI IN PARALLELO. Le connessioni dati passive cadono se si
#    spara un lotto insieme, e curl lo dice male: in un caso reale ha riportato
#    UN fallimento quando ne mancavano VENTICINQUE. Peggio: ProFTPD conta le
#    connessioni, e troppe in poco tempo fanno scattare l'anti-hammering —
#    l'IP resta bandito una ventina di minuti, in mezzo a un deploy.
#
# 3. SI VERIFICA CON SIZE, NON CON LIST. Il codice di uscita di curl non basta
#    a dire che il file è arrivato intero. E la LIST di ProFTPD non mostra i
#    dotfile: verificando da lì, .htaccess risulta mancante anche quando è
#    perfetto. `curl -I` sul file emette un SIZE e torna la dimensione vera,
#    dotfile compresi.
#
# 4. NIENTE DELE. Cancellare via FTP va in timeout su questo hosting. Non
#    serve: STOR sovrascrive. Se un file va davvero rimosso, si carica un
#    mini-PHP che fa @unlink() del bersaglio e poi di se stesso.
#
# 5. CACHE-BUST QUANDO CONTROLLI. Aruba serve la versione vecchia dei .php per
#    minuti, ignorando Cache-Control. Se «non è cambiato niente», nove volte su
#    dieci è la cache: aggiungi ?v=$(date +%s).
# ─────────────────────────────────────────────────────────────────────────────
set -uo pipefail
cd "$(dirname "$0")/.."

[ -f deploy/carica.conf ] && . deploy/carica.conf

DIST="${DIST:-_dist}"
HOST="${FTP_HOST:-}"
REMOTA="${FTP_ROOT:-/httpdocs}"
PAUSA="${FTP_PAUSA:-0.6}"
STATO="${DEPLOY_STATO:-.deploy-stato}"
SOLO_VERIFICA=0

[ -n "$HOST" ] || { echo "✗ FTP_HOST non impostato (deploy/carica.conf o ambiente)" >&2; exit 1; }
[ -d "$DIST" ] || { echo "✗ manca $DIST: lancia prima la build" >&2; exit 1; }

if [ -n "${FTP_NETRC:-}" ]; then NETRC=(--netrc-file "$FTP_NETRC"); else NETRC=(--netrc); fi
F() { curl -s "${NETRC[@]}" "$@"; }

# Dimensione remota via SIZE. Vale anche per i dotfile — vedi regola 3.
taglia_remota() {
  F -I -m 45 "ftp://$HOST$REMOTA/$1" 2>/dev/null \
    | tr -d '\r' | awk -F': ' '/^Content-Length:/{print $2; exit}'
}

elenca_tutto() { (cd "$DIST" && find . -type f | sed 's|^\./||' | sort); }

# ── che cosa fare ────────────────────────────────────────────────────────────
ARGS=()
for a in "$@"; do
  case "$a" in
    --verifica) SOLO_VERIFICA=1 ;;
    --tutto)    ARGS+=("--tutto") ;;
    *)          ARGS+=("$a") ;;
  esac
done
set -- "${ARGS[@]+"${ARGS[@]}"}"

PARZIALE=0
if [ $# -gt 0 ] && [ "${1:-}" != "--tutto" ]; then
  ELENCO="$*"
  PARZIALE=1   # giro su un elenco esplicito: non dice nulla sul resto dell'albero
elif [ "${1:-}" = "--tutto" ] || [ "$SOLO_VERIFICA" = 1 ] || [ ! -f "$STATO" ]; then
  ELENCO="$(elenca_tutto)"
else
  # Solo i cambiati: si confronta lo sha, non la data — la build riscrive tutto
  # ogni volta, quindi le date non dicono niente.
  ELENCO="$(
    while IFS= read -r r; do
      atteso="$(grep -F " $r" "$STATO" 2>/dev/null | head -1 | cut -d' ' -f1)"
      ora="$(shasum -a 256 "$DIST/$r" | cut -d' ' -f1)"
      [ "$atteso" != "$ora" ] && echo "$r"
    done < <(elenca_tutto)
  )"
fi

QUANTI=$(printf '%s\n' $ELENCO | grep -c . || true)
if [ "$QUANTI" -eq 0 ]; then
  # Due silenzi diversi: «tutto già caricato» e «non c'è niente da caricare»
  # si assomigliano a schermo ma vogliono dire il contrario.
  if [ -z "$(elenca_tutto)" ]; then
    echo "✗ $DIST è vuota: la build non ha prodotto niente." >&2; exit 1
  fi
  echo "Niente da fare: $DIST combacia con l'ultimo giro."; exit 0
fi

if [ "$SOLO_VERIFICA" = 1 ]; then
  echo "Verifica di $QUANTI file su ftp://$HOST$REMOTA"; echo
else
  echo "Da caricare: $QUANTI file su ftp://$HOST$REMOTA"; echo
fi

ok=0; ko=0; falliti=""
for rel in $ELENCO; do
  atteso=$(wc -c < "$DIST/$rel" | tr -d ' ')

  if [ "$SOLO_VERIFICA" = 1 ]; then
    ottenuto="$(taglia_remota "$rel")"
    if [ "$ottenuto" = "$atteso" ]; then
      ok=$((ok+1)); printf "  ✓ %-52s %s byte\n" "$rel" "$atteso"
    else
      ko=$((ko+1)); falliti="$falliti $rel"
      printf "  ✗ %-52s %s invece di %s\n" "$rel" "${ottenuto:-assente}" "$atteso"
    fi
    sleep "$PAUSA"; continue
  fi

  esito="?"
  for tentativo in 1 2 3; do
    F --ftp-create-dirs -m 180 -T "$DIST/$rel" "ftp://$HOST$REMOTA/$rel" >/dev/null 2>&1
    sleep "$PAUSA"
    ottenuto="$(taglia_remota "$rel")"
    if [ "$ottenuto" = "$atteso" ]; then esito="ok"; break; fi
    esito="${ottenuto:-0} invece di $atteso"
    sleep 2
  done
  if [ "$esito" = "ok" ]; then
    ok=$((ok+1)); printf "  ✓ %-52s %s byte\n" "$rel" "$atteso"
  else
    ko=$((ko+1)); falliti="$falliti $rel"; printf "  ✗ %-52s %s\n" "$rel" "$esito"
  fi
  sleep "$PAUSA"
done

echo
[ "$SOLO_VERIFICA" = 1 ] && echo "integri $ok · da rifare $ko" || echo "caricati $ok · falliti $ko"
if [ "$ko" -gt 0 ]; then
  echo "Da ripassare:$falliti" >&2
  exit 1
fi

# L'impronta si aggiorna SOLO a giro pulito: se qualcosa è fallito, il prossimo
# «solo i cambiati» deve riprovarci invece di darlo per fatto.
#
# ⚠ E solo a giro COMPLETO. Con un elenco esplicito di file si caricano cinque
# cose e non si sa niente delle altre trecento: scrivere lì l'impronta dell'intero
# albero significa dichiarare caricato ciò che non è mai partito, e il giro dopo
# «niente da fare» sarebbe una bugia. Trovato caricando l'assistente su
# NuovoSostenibile, dove il primo deploy è per forza parziale.
if [ "$SOLO_VERIFICA" = 0 ] && [ "$PARZIALE" = 0 ]; then
  (cd "$DIST" && find . -type f | sed 's|^\./||' | sort | while IFS= read -r r; do
    echo "$(shasum -a 256 "$r" | cut -d' ' -f1) $r"; done) > "$STATO"
fi

echo
echo "Le migrazioni le lancia una persona, dal browser e da loggata: il database"
echo "non lo tocca il deploy. Quando controlli una pagina, ricorda il cache-bust:"
echo "  ?v=\$(date +%s)"
