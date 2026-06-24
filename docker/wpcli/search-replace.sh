#!/usr/bin/env bash
# Rewrite the production URL to the local one across the whole DB.
# Run standalone any time:  docker compose run --rm wpcli search-replace.sh
set -euo pipefail

: "${PROD_URL:?PROD_URL not set}"
: "${WP_URL:?WP_URL not set}"

echo "[search-replace] ${PROD_URL}  ->  ${WP_URL}"

# --precise forces PHP-based replacement (correctly handles serialized data).
# --skip-columns=guid leaves post GUIDs alone (they must stay constant).
# --skip-plugins/--skip-themes avoids loading plugin code during the rewrite.
wp --allow-root search-replace "${PROD_URL}" "${WP_URL}" \
  --all-tables \
  --precise \
  --skip-columns=guid \
  --skip-plugins \
  --skip-themes \
  --report-changes-only
