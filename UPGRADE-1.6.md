# Upgrading to lara-verifactu 1.6

1.6 changes how the package delivers its database schema (AID-1886). Until 1.5.x it copied its migrations into your application (`database/migrations`) under new timestamps. From 1.6 it **runs them from its own folder in `vendor/`** and publishes none: installing or upgrading is `composer require` / `composer update` followed by `php artisan migrate`, with nothing else to publish.

The code API does not change.

## If you never published the migrations

Nothing to do: `composer update aichadigital/lara-verifactu` and `php artisan migrate`.

## If you published them (with `verifactu:install` or `vendor:publish --tag=lara-verifactu-migrations`)

Your copies and the package's files have different names, so Laravel would try to create the same tables twice. **1.6 refuses to migrate while any copy remains:** `php artisan migrate` stops before running anything and lists the files, with a `PublishedMigrationsException`.

The tables your copies created cannot be adopted by the package migrations. Recreate the schema:

1. Delete from your repository every published copy the exception lists: files whose name, without the timestamp, matches a migration in `vendor/aichadigital/lara-verifactu/database/migrations`.
2. Update the package: `composer update aichadigital/lara-verifactu`.
3. Rebuild the database: `php artisan migrate:fresh`.

**`migrate:fresh` drops every table in your application's database, not only the Verifactu ones.** Your invoices, users and every other table go with them.

**This reinstall path holds only under a signed exception, and only while your installation holds no real lara-verifactu records.** The project's governance requires every schema change to preserve existing data. The owner signed an exception for this transition on 2026-11-08 (record `2026-11-08-excepcion-firmada-lara-verifactu-aid-1886`), because no known consumer held real Verifactu records. The exception must be reviewed no later than **2026-11-23**; at that review it is kept, narrowed or withdrawn, and if it is not reviewed in time it lapses.

If your installation holds real Verifactu records (registries chained or sent to the AEAT), the exception does not cover you: **do not run `migrate:fresh`**. Open an issue before upgrading; a data-preserving path is owed to you.

## Configuration

Nothing to change. Every configuration key the package reads has a default at the point where it is read, so a configuration file published by an older release keeps working.

## What changes in behaviour

- **`verifactu:install` no longer publishes migrations.** It still publishes the configuration and translations, and offers to run `migrate`.
- **The `lara-verifactu-migrations` publish tag no longer exists.**
- **`php artisan migrate` fails before running anything if published copies remain**, naming the files to delete.
