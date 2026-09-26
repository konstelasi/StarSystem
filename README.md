# StarSystem

A content management system built on [StarDust](https://github.com/damarbob/stardust) and Laravel 13, made to run on ordinary shared hosting: no shell access, no daemons, no queue worker required. Background work runs in-process on a scheduled tick, driven by cron where one exists or by a secret URL where it doesn't.

## What's here

- **Model builder** — a drag-and-drop admin page for defining content models: add, rename, retype, promote and demote fields, with a live preview of what saving will do before it happens. Sixteen core field types ship out of the box (text, rich text, number, date, file, and more), and modules can register their own.
- **Modules** — Composer-free plugins, installed by uploading a zip from the admin. A module registers its own hooks, migrations and admin pages, and is dropped into automatic safe mode if it fails to load rather than taking the whole site down.
- **Media library** — a per-site file store with folders, search, drag-and-drop upload and a picker component other admin pages (like the model builder's file field) reuse.
- **Updater** — the admin can check for and apply a new release from the admin panel: download, verify, swap files, migrate, and roll back automatically if a step fails partway.
- **Multi-site** — off by default (every request resolves to one site), on with `STARSYSTEM_MULTISITE=true`, at which point each site is matched by its own domain.

## Requirements

- PHP 8.3+
- MariaDB 10.11+ or MySQL 8.0+
- Node.js (for building the front end)
- Composer

## Running it locally

```bash
composer setup   # composer install, .env, APP_KEY, migrate, npm install, npm run build
composer dev      # serves the app, queue listener and Vite dev server together
```

Then visit the app in your browser. With no `.env` yet, or one with no `APP_KEY`, every request redirects to `/install`, which walks through requirements, database details, migrations and the first administrator — no shell needed for that part either.

## Testing and checks

The suite needs a real MariaDB or MySQL server; `phpunit.xml` points at MariaDB on `127.0.0.1:33110` by default. Run against MySQL instead by overriding the port:

```bash
composer ci:check              # lint check, types, the full suite (MariaDB)
php artisan test                # just the suite
DB_PORT=33080 php artisan test  # the same suite against MySQL
```

`composer lint` formats with [Pint](https://laravel.com/docs/pint); `npm run check` formats and lints the front end.

## Releasing

`build/release.php` packages one commit into a zip a shared-host owner can upload: `vendor/` installed with `--no-dev`, the built front end, and a `release.json` feed entry with the version, the zip's URL and its checksum. Pushing a `v*` tag matching `VERSION` builds and publishes it as a GitHub release via `.github/workflows/release.yml`.
