#!/bin/bash
# Prepara _dist: esattamente quello che deve stare sul server, e nulla di piu'.
#
#   bash deploy/costruisci.sh
#
# Non c'e' niente da compilare: e' una copia selettiva. Restano fuori le prove,
# gli strumenti di sviluppo, il database e i file convertiti — sul server quelli
# sono dati, non codice, e non vanno sovrascritti.
#
# ─────────────────────────────────────────────────────────────────────────────
# PERCHE' «vendor» VIAGGIA COME ARCHIVIO
#
# Le librerie sono 756 file. Su questo hosting il deploy e' FTP, un file alla
# volta (kit K21, regola 2: il parallelo fa cadere le connessioni dati e fa
# scattare l'anti-hammering di ProFTPD). 756 file significano oltre 1.500
# connessioni: l'IP viene bandito a meta' strada, e i file iniziano ad arrivare
# a zero byte. Successo misurato: dopo una quindicina di file.
#
# Quindi «vendor» sale come un solo zip, e un piccolo script lo scompatta sul
# server e poi cancella se stesso. Il deploy passa da 760 file a una cinquantina.
# ─────────────────────────────────────────────────────────────────────────────

set -euo pipefail
cd "$(dirname "$0")/.."

DIST="${DIST:-_dist}"

rm -rf "$DIST"
mkdir -p "$DIST"

# ── il codice ────────────────────────────────────────────────────────────────
cp index.php .htaccess .user.ini "$DIST/"
cp -R app views public "$DIST/"

# ── le librerie, in un archivio solo ─────────────────────────────────────────
# L'archivio si rifa' solo quando vendor cambia davvero: rispedire 1,5 MB a ogni
# ritocco di una vista sarebbe sprecato, e lascerebbe sul server uno
# scompattatore senza piu' niente da scompattare.
IMPRONTA="$( { cd vendor && find . -type f -exec shasum -a 256 {} + | sort; } | shasum -a 256 | cut -d' ' -f1)"
CACHE_ZIP=deploy/.vendor-cache.zip
CACHE_SHA=deploy/.vendor-cache.sha

if [ ! -f "$CACHE_ZIP" ] || [ "$(cat "$CACHE_SHA" 2>/dev/null)" != "$IMPRONTA" ]; then
  LAVORO="$(mktemp -d)"
  trap 'rm -rf "$LAVORO"' EXIT
  cp -R vendor "$LAVORO/vendor"
  find "$LAVORO/vendor" -type d \( -name 'test' -o -name 'tests' -o -name 'Tests' -o -name 'docs' -o -name 'samples' \) -prune -exec rm -rf {} + 2>/dev/null || true
  find "$LAVORO/vendor" -type f \( -name '*.md' -o -name 'phpunit*' -o -name '.php-cs-fixer*' -o -name '*.yml' -o -name '*.yaml' -o -name '*.dist' -o -name '.DS_Store' \) -delete 2>/dev/null || true
  (cd "$LAVORO" && zip -q -r -X vendor.zip vendor)
  mv "$LAVORO/vendor.zip" "$CACHE_ZIP"
  printf '%s' "$IMPRONTA" > "$CACHE_SHA"
  VENDOR_NUOVO=1
else
  VENDOR_NUOVO=0
fi

# Il gettone deriva dall'impronta delle librerie: finche' non cambiano, il file
# resta identico byte per byte. Cosi' archivio e scompattatore entrano sempre in
# _dist, ma il deploy incrementale — che confronta gli sha — non li rispedisce.
# Decidere qui di ometterli sarebbe sbagliato: questa build sa cosa e' cambiato
# dall'ultima build, non cosa c'e' davvero sul server. Quello lo sa .deploy-stato.
GETTONE="$(printf '%s' "$IMPRONTA" | shasum -a 256 | cut -c1-32)"

cp "$CACHE_ZIP" "$DIST/vendor.zip"
sed "s/@GETTONE@/$GETTONE/" deploy/scompatta.php.modello > "$DIST/_scompatta.php"

# ── le cartelle dei dati: solo il guscio e la protezione ─────────────────────
# Il database e i file convertiti nascono sul server e restano li'.
for cartella in data storage storage/in storage/out; do
  mkdir -p "$DIST/$cartella"
done
cp data/.htaccess    "$DIST/data/.htaccess"
cp storage/.htaccess "$DIST/storage/.htaccess"
cp app/.htaccess     "$DIST/app/.htaccess"
cp views/.htaccess   "$DIST/views/.htaccess"

# Le cartelle vuote non esistono, per FTP: serve un file dentro perche' vengano
# create. Un indice muto fa anche da schermo se l'hosting elenca le cartelle.
for cartella in storage/in storage/out; do
  printf '<?php http_response_code(404);\n' > "$DIST/$cartella/index.php"
done

find "$DIST" -name '.DS_Store' -delete

echo "_dist pronta: $(find "$DIST" -type f | wc -l | tr -d ' ') file, $(du -sh "$DIST" | cut -f1)"

echo
if [ "$VENDOR_NUOVO" = 1 ]; then
  echo "Librerie ricostruite."
else
  echo "Librerie invariate rispetto all'ultima build."
fi
echo "Se il deploy carica vendor.zip, poi apri una volta sola:"
echo "  https://www.vblite.com/convert/_scompatta.php?k=$GETTONE"
