# StarSystem

A content management system built on [StarDust](https://github.com/damarbob/stardust) and Laravel 13, made to run on ordinary shared hosting. It needs no shell access, no daemons and no queue worker. Background work runs in-process on a scheduled tick, driven by cron where the host has it or by a secret URL where it doesn't.

## What's here

Content starts in the **model builder**, a drag-and-drop admin page where you define a model and its fields. You can add, rename, retype, promote and demote fields, and before anything is saved you see what the save will do to existing entries. Sixteen field types ship with StarSystem (text, rich text, number, date, file and more), and modules can add their own.

Each model then gets its **entries**, the content itself. Editors create and edit them in a form built from the model's fields, and the list pages through them newest first. Deleting an entry moves it to the model's trash, where it can be restored or deleted for good. Anything left in the trash for 30 days is deleted on its own, and `STARSYSTEM_ENTRY_TRASH_DAYS` changes that.

Around them sit the other parts of the admin.

- The **media library** keeps each site's files in folders, with search and drag-and-drop upload. Other pages reuse its picker, the builder's file field among them.
- **Modules** are Composer-free plugins, installed by uploading a zip. A module registers its own hooks, migrations and admin pages, and can add abilities for roles to be given. If one fails to load, StarSystem switches it off and the site keeps running.
- The **updater** checks for a new release and applies it from the admin. It downloads, verifies, swaps the files and migrates, and rolls back by itself if a step fails partway.
- **Multi-site** is off by default, so every request resolves to one site. Set `STARSYSTEM_MULTISITE=true` and each site is matched by its own domain.

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

Then open the app in your browser. With no `.env` yet, or one with no `APP_KEY`, every request redirects to `/install`. The installer walks through requirements, database details, migrations and the first administrator, still with no shell.

## Testing and checks

The suite needs a real MariaDB or MySQL server. `phpunit.xml` points at MariaDB on `127.0.0.1:33110` by default, and overriding the port runs the same suite against MySQL.

```bash
composer ci:check              # lint check, types, front-end tests, the full suite (MariaDB)
php artisan test                # just the suite
DB_PORT=33080 php artisan test  # the same suite against MySQL
```

`composer lint` formats PHP with [Pint](https://laravel.com/docs/pint). `npm run check` formats and lints the front end, and `npm run test` runs its tests.

## Releasing

`build/release.php` packages one commit into a zip a shared-host owner can upload. The zip holds `vendor/` installed with `--no-dev` and the built front end, and the script writes a `release.json` feed entry with the version, the zip's URL and its checksum. Pushing a `v*` tag that matches `VERSION` builds it and publishes it as a GitHub release through `.github/workflows/release.yml`.

The `release.json` is signed with an Ed25519 key, and an install refuses a feed that isn't, so someone who replaces the feed can't hand it a different zip. The public half of the key is `FeedSignature::PUBLIC_KEY` in `app/Update/FeedSignature.php`. The secret half is the repository secret `STARSYSTEM_RELEASE_KEY`, which the release workflow passes to `build/release.php`, and the build stops without it. The build keeps the secret from `composer install`, so no dependency's script can read it. `--unsigned` makes a local test build that no install will accept.

To make a new key pair, run this from the repository root. It prints the secret and then the public key.

```sh
php -r 'require "vendor/autoload.php"; $k = sodium_crypto_sign_keypair(); echo base64_encode(sodium_crypto_sign_secretkey($k)), PHP_EOL, base64_encode(sodium_crypto_sign_publickey($k)), PHP_EOL;'
```

To change the key, publish a release signed with the old key that carries the new public key, then replace the secret. An install that updates to that release checks the next feed with the new key. A copy of StarSystem that publishes its own releases puts its own public key in `FeedSignature::PUBLIC_KEY`, so every release it ships already carries it. An install that follows some other feed sets `STARSYSTEM_UPDATE_FEED` and `STARSYSTEM_UPDATE_KEY` in its `.env`.
