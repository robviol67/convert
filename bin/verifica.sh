#!/usr/bin/env bash
# Lancia tutte le verifiche.
set -uo pipefail
cd "$(dirname "$0")/.."

esito=0
for prova in tests/prova.php tests/documenti.php; do
  echo "── $prova"
  php "$prova" || esito=1
  echo
done
exit $esito
