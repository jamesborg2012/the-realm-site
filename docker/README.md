# Local development with Docker

Runs the full site locally — WordPress core, Storefront, WooCommerce and the
third-party plugins (via Composer/WPackagist), plus the custom `the-realm-malta`
theme and `realm-*` plugins bind-mounted live from this repo.

## Prerequisites

- Docker Desktop (or Docker Engine + Compose v2)

## Quick start

```bash
# 1. Point the local domain at your machine (one-time, needs sudo):
echo "127.0.0.1   local.therealmmalta.com" | sudo tee -a /etc/hosts

# 2. Set up config:
cp .env.example .env             # adjust ports/credentials if you like
cp auth.json.example auth.json   # then fill in your ACF PRO license key + site URL

# 3. Boot:
docker compose up -d             # first boot pulls images + runs composer install
docker compose logs -f wpcli     # watch the bootstrap finish
```

When `wpcli` exits, the site is ready:

- **Store:** http://local.therealmmalta.com (admin: `admin` / `admin` — change in `.env`)
- **phpMyAdmin:** http://localhost:8081

> **Port 80:** the site serves on port 80 for a clean, prod-like URL. If something
> else already uses port 80 (XAMPP/Apache, another stack), stop it, or set
> `WP_PORT=8080` **and** `WP_URL=http://local.therealmmalta.com:8080` in `.env`.

The very first boot takes a few minutes (image pulls + `composer install`).
Subsequent boots are fast — everything is cached in the `wp_html` / `db_data`
volumes.

## How it fits together

| Service     | Role                                                                 |
|-------------|----------------------------------------------------------------------|
| `db`        | MySQL 8.4 (matches prod). Data persists in the `db_data` volume. Table prefix is `cki_`. |
| `wordpress` | `wordpress:6.7-php8.2-apache`. Core lives in `wp_html`; your custom theme + 5 `realm-*` plugins are bind-mounted on top for live editing. |
| `wpcli`     | One-shot bootstrap (wp-cli + Composer): `composer install`, install/verify WP, activate theme + plugins, rewrite prod URL → local, set permalinks. Idempotent. |
| `phpmyadmin`| DB GUI.                                                              |

Third-party themes/plugins are declared in [`../composer.json`](../composer.json)
and installed into `wp-content/` on first boot. They are **not** committed
(`.gitignore` already excludes everything under `wp-content/{plugins,themes}`
except your custom code).

## Database import

On the **first** boot, MySQL auto-imports any `.sql` file in `docker/db/data/`
(mounted into `/docker-entrypoint-initdb.d`). The bootstrap then detects WordPress
is already installed (so it skips the fresh install) and rewrites the production
URL to the local one.

- The import only runs when the `db_data` volume is empty (i.e. first init).
- **To re-import** a new/updated dump: `docker compose down -v && docker compose up -d`.
- If `docker/db/data/` is empty, you just get a clean fresh install instead.
- The dump (`docker/db/`) is gitignored — it stays on your machine.

## Common tasks

```bash
# Re-run the production → local URL search-replace any time
docker compose run --rm wpcli search-replace.sh

# Run any wp-cli command (container runs as root, so pass --allow-root)
docker compose run --rm wpcli wp --allow-root plugin list

# Re-run the full bootstrap (after editing composer.json, e.g. adding a plugin)
docker compose run --rm wpcli              # no args = full bootstrap

# Tail the WP debug log
docker compose exec wordpress tail -f wp-content/debug.log

# Stop / start
docker compose stop
docker compose up -d

# Nuke everything (DB + WP files) and start clean
docker compose down -v
```

## Adding a plugin

Add it to `require` in [`../composer.json`](../composer.json) using its
WPackagist slug (`wpackagist-plugin/<slug>`), add the slug to the `PLUGINS`
list in [`wpcli/bootstrap.sh`](wpcli/bootstrap.sh) so it gets activated, then:

```bash
docker compose run --rm wpcli
```

## Notes

- **WordPress version:** pinned to `6.7` to mirror the local XAMPP install.
  Production runs 7.x — bump the `wordpress:` image tag in `docker-compose.yml`
  when you want to test against it.
- **SCSS:** the theme's CSS is compiled on the host (`npm run compile:sass` in
  `wp-content/themes/the-realm-malta`), not in the container — the compiled
  `assets/css/layout.css` is committed.
- **ACF PRO** (needed by the theme's Account Creation block) is installed via
  ACF's licensed Composer endpoint (`connect.advancedcustomfields.com`). Put your
  credentials in `auth.json` (copy from `auth.json.example`): the **username** is
  your license key, the **password** is the site URL the license is registered to.
  `auth.json` is gitignored and mounted into Composer's home dir — never the web
  root — so the key isn't downloadable. Without it, `composer install` will fail
  to fetch ACF PRO.
- **Other premium / non-WPackagist plugins** (e.g. a paid POS barcode plugin):
  drop the folder into `wp-content/plugins/` manually and add its slug to the
  `PLUGINS` list in `bootstrap.sh`.
- **Fresh DB:** this stack starts empty. To work with real data, import a SQL
  dump via phpMyAdmin or `docker compose exec -T db mysql -u root -p... the_realm < dump.sql`.
