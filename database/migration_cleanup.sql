-- Optional cleanup for existing databases after adopting schema_clean.sql
-- Run only after backup.

BEGIN;

-- Remove legacy customer columns that mixed authentication concerns into customers table.
ALTER TABLE customers DROP COLUMN IF EXISTS reset_token;
ALTER TABLE customers DROP COLUMN IF EXISTS reset_token_expires;
ALTER TABLE customers DROP COLUMN IF EXISTS verify_otp;
ALTER TABLE customers DROP COLUMN IF EXISTS verify_otp_expires;
ALTER TABLE customers DROP COLUMN IF EXISTS is_verified;

-- Ensure books has predictable ordering columns used by APIs.
ALTER TABLE books ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE books ADD COLUMN IF NOT EXISTS sales_count INTEGER NOT NULL DEFAULT 0;

COMMIT;
