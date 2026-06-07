#!/usr/bin/env bash
# Déploie kpopify-ping sur tous les sites Kpopify (Oracle 1 + Oracle 2)
# Usage : ./deploy.sh [oracle1|oracle2|all]
# Les alias SSH oracle1 / oracle2 doivent être définis dans ~/.ssh/config

set -e

PLUGIN_DIR="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_NAME="kpopify-ping"

ORACLE1_HOSTS=(
  "oracle1:/home/kpopify/html/wp-content/plugins/"        # FR
)

ORACLE2_HOSTS=(
  "oracle2:/home/kpopify-es/html/wp-content/plugins/"     # ES
  "oracle2:/home/kpopify-pt/html/wp-content/plugins/"     # PT
  "oracle2:/home/kpopify-ph/html/wp-content/plugins/"     # PH
  "oracle2:/home/kpopify-ms/html/wp-content/plugins/"     # MS
  "oracle2:/home/kpopify-ar/html/wp-content/plugins/"     # AR
  "oracle2:/home/kpopify-vi/html/wp-content/plugins/"     # VI
  "oracle2:/home/kpopify-th/html/wp-content/plugins/"     # TH
  "oracle2:/home/kpopify-id/html/wp-content/plugins/"     # ID
)

deploy_to() {
  local dest="$1"
  echo "  → $dest"
  scp -r "$PLUGIN_DIR" "$dest"
}

TARGET="${1:-all}"

if [[ "$TARGET" == "oracle1" || "$TARGET" == "all" ]]; then
  echo "=== Oracle 1 ==="
  for dest in "${ORACLE1_HOSTS[@]}"; do deploy_to "$dest"; done
fi

if [[ "$TARGET" == "oracle2" || "$TARGET" == "all" ]]; then
  echo "=== Oracle 2 ==="
  for dest in "${ORACLE2_HOSTS[@]}"; do deploy_to "$dest"; done
fi

echo ""
echo "✓ Déploiement terminé."
echo "  Penser à activer le plugin via WP Admin > Extensions sur chaque site."
