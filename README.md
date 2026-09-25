# Book Ecommerce Server

This backend is now aligned to a cleaner and easier-to-maintain database structure:

- Keep each table focused (no mixed concerns in one table).
- Avoid runtime `ALTER TABLE` in repository code.
- Use explicit SQL schema files under [`database`](./database).

## Clean Database Structure

Main schema file:
- [`database/schema_clean.sql`](./database/schema_clean.sql)

Core tables:
1. `authors`
2. `categories`
3. `books`
4. `customers`
5. `password_reset_tokens`
6. `admin`
7. `carts`
8. `cart_items`
9. `invoices`
10. `invoice_items`
11. `refresh_tokens`

### Why this is cleaner

- `customers` only stores customer profile/auth data.
- Password reset OTP/token is moved to `password_reset_tokens`.
- `books` has consistent ordering fields:
  - `created_at` for New Arrivals
  - `sales_count` for Best Sellers

## Apply Schema

Run the schema on PostgreSQL:

```sql
\i LEGACY_SCHEMA_MIGRATION.sql
\i database/schema_clean.sql
\i database/schema_cart_invoices.sql
\i REFRESH_TOKEN_MIGRATION.sql
\i SECURITY_MIGRATION.sql
```

If you already have old columns in `customers`, run optional cleanup:

```sql
\i database/migration_cleanup.sql
```

## OTP / Forgot Password Flow

Only forgot/reset password uses OTP now.

Required `.env` values:

```env
JWT_SECRET=your_long_random_secret
JWT_TTL_SECONDS=604800
JWT_REFRESH_TTL_SECONDS=2592000
ADMIN_REGISTER_SECRET=your_admin_bootstrap_secret
FRONTEND_ORIGIN=https://your-frontend.example.com

RESET_TOKEN_EXPIRY_MINUTES=15

MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your_email@gmail.com
MAIL_PASSWORD=your_app_password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=your_email@gmail.com
MAIL_FROM_NAME=Bookly
```

Flow:
1. `POST /customers/forgot-password` with `{ "email": "user@email.com" }`
2. Server stores OTP in `password_reset_tokens`
3. OTP email is sent with PHPMailer
4. `POST /customers/reset-password` with `{ "token": "1234", "new_password": "..." }`

## Auth and RBAC

Roles:
- `customer`
- `admin`

Core auth endpoints:
- `POST /customers/login`
- `POST /admin/login`
- `GET /auth/me`
- `POST /auth/refresh`
- `POST /auth/logout`

Customer and admin login now return:
- `access_token`
- `refresh_token`
- `token_type`
- `expires_in`
- `user`

Refresh tokens now exist for both customer and admin sessions.

Protected endpoints require:

```http
Authorization: Bearer <access_token>
```

Customer token rotation endpoints expect:

```json
{
  "refresh_token": "..."
}
```

Security hardening included:
- DB-backed rate limiting for login, forgot-password, and reset-password flows
- 6-digit reset OTPs instead of 4-digit OTPs
- logout now requires a valid access token plus the matching refresh token
- forgot-password does not reveal whether an email exists

## Deploy Checklist

- Use PostgreSQL and apply both schema files before starting the API.
- If your database was created from the older project version, run [LEGACY_SCHEMA_MIGRATION.sql](/e:/xampp/htdocs/book_project/book-ecommerce-server/LEGACY_SCHEMA_MIGRATION.sql) first.
- Set strong `JWT_SECRET` and `ADMIN_REGISTER_SECRET` values.
- Replace placeholder SMTP credentials before enabling forgot-password in production.
- Restrict `FRONTEND_ORIGIN` to the deployed frontend URL.
- Serve the API behind HTTPS in production.

Detailed deployment steps are in [DEPLOYMENT.md](/e:/xampp/htdocs/book_project/book-ecommerce-server/DEPLOYMENT.md).

## Removed Legacy Endpoints

These register-OTP endpoints were removed to simplify authentication:

- `POST /customers/verify-register-otp`
- `POST /customers/resend-register-otp`
