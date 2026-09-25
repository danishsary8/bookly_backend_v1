BEGIN;

CREATE TABLE IF NOT EXISTS refresh_tokens (
    id BIGSERIAL PRIMARY KEY,
    customer_id BIGINT NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
    token_hash VARCHAR(64) NOT NULL UNIQUE,
    jwt_id VARCHAR(64) NOT NULL UNIQUE,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_refresh_tokens_customer_id ON refresh_tokens(customer_id);
CREATE INDEX IF NOT EXISTS idx_refresh_tokens_expires_at ON refresh_tokens(expires_at);

ALTER TABLE refresh_tokens
    ADD COLUMN IF NOT EXISTS jwt_id VARCHAR(64);

UPDATE refresh_tokens
SET jwt_id = COALESCE(jwt_id, md5(id::text || token_hash || expires_at::text))
WHERE jwt_id IS NULL;

ALTER TABLE refresh_tokens
    ALTER COLUMN jwt_id SET NOT NULL;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'refresh_tokens_jwt_id_key'
    ) THEN
        ALTER TABLE refresh_tokens
            ADD CONSTRAINT refresh_tokens_jwt_id_key UNIQUE (jwt_id);
    END IF;
END $$;

ALTER TABLE invoices
    ALTER COLUMN status TYPE VARCHAR(20);

ALTER TABLE invoices
    DROP CONSTRAINT IF EXISTS invoices_status_check;

ALTER TABLE invoices
    ADD CONSTRAINT invoices_status_check
    CHECK (status IN ('pending', 'paid', 'processing', 'shipped', 'cancelled'));

COMMIT;
