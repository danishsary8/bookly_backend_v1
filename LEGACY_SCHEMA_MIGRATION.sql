BEGIN;

ALTER TABLE books
    ADD COLUMN IF NOT EXISTS sales_count INTEGER NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id BIGSERIAL PRIMARY KEY,
    customer_id BIGINT NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
    token VARCHAR(10) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    used_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_password_reset_tokens_customer_id ON password_reset_tokens(customer_id);
CREATE INDEX IF NOT EXISTS idx_password_reset_tokens_token ON password_reset_tokens(token);
CREATE INDEX IF NOT EXISTS idx_password_reset_tokens_expires_at ON password_reset_tokens(expires_at);

ALTER TABLE customers DROP COLUMN IF EXISTS reset_token;
ALTER TABLE customers DROP COLUMN IF EXISTS reset_token_expires;
ALTER TABLE customers DROP COLUMN IF EXISTS verify_otp;
ALTER TABLE customers DROP COLUMN IF EXISTS verify_otp_expires;
ALTER TABLE customers DROP COLUMN IF EXISTS is_verified;

COMMIT;
