# Backend Deployment

## 1. Prepare environment

Use [.env.production.example](/e:/xampp/htdocs/book_project/book-ecommerce-server/.env.production.example) as the template for your production `.env`.

You must replace these yourself:
- database host, db name, user, password
- `JWT_SECRET`
- `ADMIN_REGISTER_SECRET`
- `FRONTEND_ORIGIN`
- all SMTP values if you need forgot-password email delivery

## 2. Apply migrations

From [scripts/apply_backend_migrations.ps1](/e:/xampp/htdocs/book_project/book-ecommerce-server/scripts/apply_backend_migrations.ps1):

```powershell
.\scripts\apply_backend_migrations.ps1 -Password "your_db_password" -Database "your_db_name"
```

Migration order:
- [LEGACY_SCHEMA_MIGRATION.sql](/e:/xampp/htdocs/book_project/book-ecommerce-server/LEGACY_SCHEMA_MIGRATION.sql)
- [database/schema_cart_invoices.sql](/e:/xampp/htdocs/book_project/book-ecommerce-server/database/schema_cart_invoices.sql)
- [REFRESH_TOKEN_MIGRATION.sql](/e:/xampp/htdocs/book_project/book-ecommerce-server/REFRESH_TOKEN_MIGRATION.sql)
- [SECURITY_MIGRATION.sql](/e:/xampp/htdocs/book_project/book-ecommerce-server/SECURITY_MIGRATION.sql)

## 3. Start backend

Example:

```powershell
php -S 127.0.0.1:8082 -t public
```

For production, use a real web server / PHP runtime behind HTTPS.

## 4. Verify backend

Run [scripts/backend_smoke_test.ps1](/e:/xampp/htdocs/book_project/book-ecommerce-server/scripts/backend_smoke_test.ps1) after startup:

```powershell
.\scripts\backend_smoke_test.ps1 -DbPassword "your_db_password"
```

## 5. Manual items you still must do

- set real SMTP credentials if forgot-password email must work
- point `FRONTEND_ORIGIN` to the real frontend domain
- deploy behind HTTPS
- back up the production database before running migrations
