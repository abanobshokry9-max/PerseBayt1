# PerseBayt public GitHub sanitization

This package was prepared for a **public GitHub repository**. The supplied originals were not modified.

## Removed from the public package

- `private/runtime/master.key`
- encrypted vault files such as `secrets.enc` and their backups
- runtime file backups and generated runtime state
- any production database row data from the included SQL copy
- production database name/user from the public configuration

## Configuration changes

`private/config.php` now reads credentials from environment variables and contains no real secret values. A matching `.env.example` is included with empty placeholders.

## Database

`database/persebayt_public_schema.sql` is **schema-only**. It contains tables/indexes/constraints but no `INSERT`/`REPLACE` production rows. This intentionally prevents credentials, password hashes, vault snapshots, customer/contact records, messages, and other operational data from being published.

## Git protection

`.gitignore` blocks common secret files, local environment files, runtime vault contents, backups, logs, private keys, encrypted secret files, and arbitrary SQL dumps. Only the explicit public schema is allowed.

## Important

Never commit your real `.env`, production `config.php` containing credentials, `master.key`, `secrets.enc`, database dumps, access tokens, API keys, or backups to a public repository.
