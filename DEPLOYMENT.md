# Deploy the Bookly PHP API to Wasmer with Neon

This repository is a custom PHP API, **not Laravel**. Its HTTP entry point is
`public/index.php`. `wasmer.toml` maps only `public/`, `app/`, and `vendor/`
into the runtime. The frontend is a separate Vite project and is deployed
separately.

## Before you deploy

1. Rotate the Neon database password that was shared outside Neon. Use only the
   new password below. Also rotate any other secrets previously committed in
   `.env`. Removing a file from Git now does **not** remove it from Git history.
2. Review `git status`, commit the deployment changes, and push to the `main`
   branch connected to Wasmer. `.env`, local logs, and `_debug_customer.php`
   must not be included in the commit. Keep them locally if needed.
3. Check whether your Neon database already contains the Bookly schema and
   data. Follow **one** of the database paths below before using `/books`.

## 1. Prepare the Neon database

Use Neon's **direct** connection hostname for administrative imports/migrations.
Use its **pooled** hostname for the running API. Both are shown in Neon's
Connection Details. Do not put a connection string or password in source code.

### Empty Neon database (new installation)

From PowerShell in this repository, prompt for the *new* password without
placing it in a command-line argument:

```powershell
$neonPassword = Read-Host 'New Neon database password' -AsSecureString
$neonPasswordText = [System.Net.NetworkCredential]::new('', $neonPassword).Password
.\scripts\apply_backend_migrations.ps1 -Mode Fresh -HostName 'YOUR-DIRECT-NEON-HOST' -Port 5432 -Database neondb -Username neondb_owner -Password $neonPasswordText -SslMode require -ChannelBinding require
Remove-Variable neonPasswordText, neonPassword
```

The fresh path applies `database/schema_clean.sql`,
`database/schema_cart_invoices.sql`, `REFRESH_TOKEN_MIGRATION.sql`, and
`SECURITY_MIGRATION.sql` in that order. It creates empty tables, **not** your
existing books, users, or orders.

### Existing database or existing local data

If Neon already has an older Bookly schema, back it up first and use the same
command with `-Mode Existing`. That mode applies the legacy update, clean
schema, cart/invoice schema, refresh-token update, and security update in order.
Do not use `-Mode Existing` on an empty database: its first migration alters
tables that do not exist yet.

If you need to **preserve local data**, migrate a PostgreSQL dump to Neon
instead of creating only empty tables. Confirm the imported schema and row
counts before applying any incremental migration. Do not run these schema
scripts blindly against a populated database with a different structure.

## 2. Configure the Wasmer GitHub import screen

Select repository `danishsary8/bookly_backend_v1` and branch `main`.

1. Choose the **PHP** preset and PHP **8.3**. Use project link
   `bookly-backend-v1`, matching `app.yaml` (hyphens, not underscores).
2. In **Build Settings**, make sure Composer is enabled and the document root
   is `public/`. Do not add `php artisan` commands; this is not Laravel.
3. Turn **Enable Database off** because Neon is the database. A Wasmer-managed
   database would be a different, empty PostgreSQL instance.
4. Open **Environment variables** and set the following values. Wasmer may
   merge settings from `app.yaml`; the table shows the intended final values.

| Variable | Value |
| --- | --- |
| `APP_ENV` | `production` |
| `DB_DRIVER` | `pgsql` |
| `DB_HOST` | Neon **pooled hostname** only, without `postgresql://` |
| `DB_PORT` | `5432` |
| `DB_NAME` | `neondb` |
| `DB_USER` | Your Neon database role, e.g. `neondb_owner` |
| `DB_PASS` | The **new** Neon database password |
| `DB_SSLMODE` | `require` |
| `DB_CHANNEL_BINDING` | `require` |
| `JWT_SECRET` | A new long random value |
| `ADMIN_REGISTER_SECRET` | A different long random value |
| `FRONTEND_ORIGIN` | Exact HTTPS frontend origin, no trailing slash |

The API reads these `DB_*` variables, **not** `DATABASE_URL` and not Wasmer's
managed-DB names `DB_USERNAME`/`DB_PASSWORD`. `DB_SSLMODE` goes in the PDO
PostgreSQL DSN; `DB_CHANNEL_BINDING` is passed to libpq as `PGCHANNELBINDING`
because this PHP PDO driver rejects `channel_binding` in its DSN.

If password-reset mail is needed, also provide `MAIL_HOST`, `MAIL_PORT`,
`MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION`, `MAIL_FROM_ADDRESS`, and
`MAIL_FROM_NAME`. Optional values are `RESET_TOKEN_EXPIRY_MINUTES=15`,
`JWT_TTL_SECONDS=604800`, and `JWT_REFRESH_TTL_SECONDS=2592000`.

Use Wasmer's secret/environment-variable editor for passwords and keys. Never
put them in `app.yaml`, `wasmer.toml`, Git, or frontend `VITE_*` variables.

## 3. Deploy and verify

Click **Deploy project**. Once Wasmer supplies the app URL, test in this order:

```powershell
Invoke-RestMethod 'https://YOUR-APP.wasmer.app/health'
Invoke-RestMethod 'https://YOUR-APP.wasmer.app/books'
```

`/health` confirms PHP starts but does **not** touch PostgreSQL. `/books`
checks the database and schema. A 500 response on `/health` may mean
`JWT_SECRET` is missing. If `/books` fails, check Wasmer logs, the Neon
host/credentials, TLS settings, tables, and whether the deployed PHP build has
the `pdo_pgsql` driver. Local XAMPP having that driver does not prove Wasmer
has it. Do not assume changing a package name will fix a missing driver;
verify the runtime or contact Wasmer support.

After the backend works, set the frontend's build-time `VITE_API_BASE_URL` to
the Wasmer HTTPS API URL, deploy/rebuild the frontend, and set
`FRONTEND_ORIGIN` to that frontend's exact origin. Check a book image, login,
cart/checkout, and the browser's CORS/network errors.

For later changes, push commits to the connected `main` branch and check the
new deployment. Wasmer also permits local CLI deployment with `wasmer login`,
`wasmer run .`, and `wasmer deploy`, but use one deployment flow consistently.

## References

- [Wasmer Git deployment](https://docs.wasmer.io/edge/git/)
- [Wasmer PHP deployment](https://docs.wasmer.io/edge/guides/php/)
- [Wasmer secrets](https://docs.wasmer.io/edge/learn/secrets/)
- [PHP PostgreSQL PDO DSN](https://www.php.net/pdo-pgsql.connection)
