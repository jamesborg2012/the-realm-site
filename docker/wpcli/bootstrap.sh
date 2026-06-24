#!/usr/bin/env bash
# First-boot bootstrap for the local Docker stack. Idempotent: safe to re-run.
set -euo pipefail

# Dispatch: with args, run them instead of bootstrapping. Lets you do e.g.
#   docker compose run --rm wpcli wp --allow-root plugin list
#   docker compose run --rm wpcli search-replace.sh
if [ "$#" -gt 0 ]; then
  cd /var/www/html
  exec "$@"
fi

cd /var/www/html
WP="wp --allow-root"

log() { echo "[bootstrap] $*"; }

# 1. Wait for the wordpress container to write wp-config.php into the shared volume.
log "Waiting for wp-config.php..."
until [ -f /var/www/html/wp-config.php ]; do sleep 2; done

# 2. Wait for the database to accept queries.
log "Waiting for database..."
until $WP db check >/dev/null 2>&1; do sleep 3; done

# 3. Install third-party themes/plugins (Storefront, WooCommerce, ACF, etc.).
log "Running composer install..."
composer install --no-interaction --prefer-dist --no-progress

# 4. Install WordPress if it isn't already.
if ! $WP core is-installed >/dev/null 2>&1; then
  log "Installing WordPress at ${WP_URL}..."
  $WP core install \
    --url="${WP_URL}" \
    --title="${WP_TITLE}" \
    --admin_user="${WP_ADMIN_USER}" \
    --admin_password="${WP_ADMIN_PASSWORD}" \
    --admin_email="${WP_ADMIN_EMAIL}" \
    --skip-email
else
  log "WordPress already installed — skipping core install."
fi

# 5. Activate the parent theme's child (Storefront is the parent, loaded automatically).
log "Activating theme: the-realm-malta"
$WP theme activate the-realm-malta

# 6. Activate third-party + custom plugins. Missing ones are warned, not fatal.
PLUGINS=(
  woocommerce
  woocommerce-paypal-payments
  woocommerce-payments
  advanced-custom-fields-pro   # ACF PRO — installed via composer (needs auth.json)
  meta-box
  megamenu
  members
  ultimate-blocks
  wpforms-lite
  wordpress-seo
  classic-editor
  classic-widgets
  disable-gutenberg
  realm-members-manager
  realm-events-manager
  realm-barcode-scanner
  realm-gw-excel-uploader
  realm-gw-order-tracker
)
for plugin in "${PLUGINS[@]}"; do
  if $WP plugin is-installed "$plugin" >/dev/null 2>&1; then
    $WP plugin activate "$plugin" || log "WARN: could not activate $plugin"
  else
    log "WARN: $plugin not installed — skipping (add it to composer.json if needed)"
  fi
done

# 7. Rewrite the production URL to the local domain (no-op if already done, or
#    on a fresh install with no prod URLs). Runs the shared helper.
if [ -n "${PROD_URL:-}" ] && [ "${PROD_URL}" != "${WP_URL}" ]; then
  log "Rewriting ${PROD_URL} -> ${WP_URL}"
  search-replace.sh || log "WARN: search-replace failed"
fi

# 8. Pretty permalinks (needed for WC/REST routes used by the custom plugins).
$WP rewrite structure '/%postname%/' --hard
$WP rewrite flush --hard

# 9. Make wp-content writable by the web server. Skip the bind-mounted uploads
#    dir — it's large and Docker Desktop already maps host writes correctly.
find /var/www/html/wp-content -mindepth 1 -maxdepth 1 ! -name uploads \
  -exec chown -R www-data:www-data {} + 2>/dev/null || true
chown www-data:www-data /var/www/html/wp-content 2>/dev/null || true

log "Done. Site: ${WP_URL}  (admin: ${WP_ADMIN_USER} / ${WP_ADMIN_PASSWORD})"
