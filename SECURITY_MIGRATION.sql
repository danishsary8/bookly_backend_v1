BEGIN;

CREATE TABLE IF NOT EXISTS admin_refresh_tokens (
    id BIGSERIAL PRIMARY KEY,
    admin_id BIGINT NOT NULL REFERENCES admin(id) ON DELETE CASCADE,
    token_hash VARCHAR(64) NOT NULL UNIQUE,
    jwt_id VARCHAR(64) NOT NULL UNIQUE,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_admin_refresh_tokens_admin_id ON admin_refresh_tokens(admin_id);
CREATE INDEX IF NOT EXISTS idx_admin_refresh_tokens_expires_at ON admin_refresh_tokens(expires_at);

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

CREATE TABLE IF NOT EXISTS auth_rate_limits (
    id BIGSERIAL PRIMARY KEY,
    action VARCHAR(80) NOT NULL,
    identifier VARCHAR(255) NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    window_started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    blocked_until TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(action, identifier)
);

CREATE INDEX IF NOT EXISTS idx_auth_rate_limits_action_identifier
    ON auth_rate_limits(action, identifier);

DROP VIEW IF EXISTS reporting_monthly_sales;
DROP VIEW IF EXISTS reporting_top_books;
DROP VIEW IF EXISTS reporting_sales_overview;

ALTER TABLE invoices
    ALTER COLUMN status TYPE VARCHAR(20);

ALTER TABLE invoices
    ADD COLUMN IF NOT EXISTS discount_amount NUMERIC(10,2) NOT NULL DEFAULT 0;

ALTER TABLE invoices
    ADD COLUMN IF NOT EXISTS promo_code VARCHAR(40);

ALTER TABLE invoices
    ADD COLUMN IF NOT EXISTS carrier VARCHAR(120);

ALTER TABLE invoices
    ADD COLUMN IF NOT EXISTS tracking_number VARCHAR(120);

ALTER TABLE invoices
    ADD COLUMN IF NOT EXISTS tracking_url TEXT;

ALTER TABLE invoices
    ADD COLUMN IF NOT EXISTS estimated_delivery_at TIMESTAMP NULL;

ALTER TABLE invoices
    ADD COLUMN IF NOT EXISTS delivered_at TIMESTAMP NULL;

ALTER TABLE invoices
    DROP CONSTRAINT IF EXISTS invoices_status_check;

ALTER TABLE invoices
    ADD CONSTRAINT invoices_status_check
    CHECK (status IN ('pending', 'paid', 'processing', 'shipped', 'delivered', 'cancelled'));

CREATE OR REPLACE FUNCTION set_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_customers_set_updated_at ON customers;
CREATE TRIGGER trg_customers_set_updated_at
BEFORE UPDATE ON customers
FOR EACH ROW
EXECUTE FUNCTION set_updated_at();

DROP TRIGGER IF EXISTS trg_carts_set_updated_at ON carts;
CREATE TRIGGER trg_carts_set_updated_at
BEFORE UPDATE ON carts
FOR EACH ROW
EXECUTE FUNCTION set_updated_at();

DROP TRIGGER IF EXISTS trg_invoices_set_updated_at ON invoices;
CREATE TRIGGER trg_invoices_set_updated_at
BEFORE UPDATE ON invoices
FOR EACH ROW
EXECUTE FUNCTION set_updated_at();

DROP TRIGGER IF EXISTS trg_auth_rate_limits_set_updated_at ON auth_rate_limits;
CREATE TRIGGER trg_auth_rate_limits_set_updated_at
BEFORE UPDATE ON auth_rate_limits
FOR EACH ROW
EXECUTE FUNCTION set_updated_at();

CREATE OR REPLACE VIEW reporting_sales_overview AS
SELECT
    (SELECT COUNT(*) FROM books) AS total_books,
    (SELECT COUNT(*) FROM customers) AS total_customers,
    COUNT(*) AS total_orders,
    COUNT(*) FILTER (WHERE status = 'delivered') AS completed_orders,
    COUNT(*) FILTER (WHERE status = 'cancelled') AS cancelled_orders,
    COUNT(*) FILTER (WHERE status IN ('pending', 'paid', 'processing', 'shipped')) AS open_orders,
    COALESCE(SUM(total) FILTER (WHERE status IN ('paid', 'processing', 'shipped', 'delivered')), 0) AS revenue_total
FROM invoices;

CREATE OR REPLACE VIEW reporting_top_books AS
SELECT
    ii.book_id,
    ii.title,
    ii.author_name,
    COALESCE(MAX(b.book_img), '') AS book_img,
    SUM(ii.quantity) AS units_sold,
    SUM(ii.line_total) AS revenue
FROM invoice_items ii
INNER JOIN invoices inv ON inv.id = ii.invoice_id
LEFT JOIN books b ON b.id = ii.book_id
WHERE inv.status <> 'cancelled'
GROUP BY ii.book_id, ii.title, ii.author_name;

CREATE OR REPLACE VIEW reporting_monthly_sales AS
SELECT
    DATE_TRUNC('month', created_at)::date AS month_start,
    COUNT(*) FILTER (WHERE status <> 'cancelled') AS order_count,
    COALESCE(SUM(total) FILTER (WHERE status IN ('paid', 'processing', 'shipped', 'delivered')), 0) AS revenue
FROM invoices
GROUP BY DATE_TRUNC('month', created_at)::date;

CREATE TABLE IF NOT EXISTS store_settings (
    id SMALLINT PRIMARY KEY DEFAULT 1,
    store_name VARCHAR(160) NOT NULL DEFAULT 'Bookly',
    support_email VARCHAR(180) NOT NULL DEFAULT 'support@bookly.local',
    support_phone VARCHAR(25),
    hero_heading VARCHAR(255) NOT NULL DEFAULT 'Discover Stories That Match Your Mood',
    hero_subheading TEXT NOT NULL DEFAULT 'Explore fresh arrivals, trending picks, and timeless classics with a clean shopping experience.',
    free_shipping_threshold NUMERIC(10,2) NOT NULL DEFAULT 60 CHECK (free_shipping_threshold >= 0),
    shipping_fee NUMERIC(10,2) NOT NULL DEFAULT 2.00 CHECK (shipping_fee >= 0),
    tax_rate NUMERIC(6,4) NOT NULL DEFAULT 0 CHECK (tax_rate >= 0),
    low_stock_threshold INTEGER NOT NULL DEFAULT 5 CHECK (low_stock_threshold >= 0),
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT store_settings_singleton CHECK (id = 1)
);

INSERT INTO store_settings (
    id,
    store_name,
    support_email,
    support_phone,
    hero_heading,
    hero_subheading,
    free_shipping_threshold,
    shipping_fee,
    tax_rate,
    low_stock_threshold
)
VALUES (
    1,
    'Bookly',
    'support@bookly.local',
    NULL,
    'Discover Stories That Match Your Mood',
    'Explore fresh arrivals, trending picks, and timeless classics with a clean shopping experience.',
    60,
    2.00,
    0,
    5
)
ON CONFLICT (id) DO NOTHING;

UPDATE store_settings
SET
    shipping_fee = 2.00,
    tax_rate = 0
WHERE id = 1;

DROP TRIGGER IF EXISTS trg_store_settings_set_updated_at ON store_settings;
CREATE TRIGGER trg_store_settings_set_updated_at
BEFORE UPDATE ON store_settings
FOR EACH ROW
EXECUTE FUNCTION set_updated_at();

CREATE TABLE IF NOT EXISTS promotions (
    id BIGSERIAL PRIMARY KEY,
    code VARCHAR(40) NOT NULL UNIQUE,
    description VARCHAR(500),
    discount_type VARCHAR(20) NOT NULL CHECK (discount_type IN ('percent', 'fixed')),
    discount_value NUMERIC(10,2) NOT NULL CHECK (discount_value >= 0),
    min_subtotal NUMERIC(10,2) NOT NULL DEFAULT 0 CHECK (min_subtotal >= 0),
    starts_at TIMESTAMP NULL,
    ends_at TIMESTAMP NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

DROP TRIGGER IF EXISTS trg_promotions_set_updated_at ON promotions;
CREATE TRIGGER trg_promotions_set_updated_at
BEFORE UPDATE ON promotions
FOR EACH ROW
EXECUTE FUNCTION set_updated_at();

CREATE INDEX IF NOT EXISTS idx_promotions_code ON promotions(code);
CREATE INDEX IF NOT EXISTS idx_promotions_is_active ON promotions(is_active);

INSERT INTO promotions (
    code,
    description,
    discount_type,
    discount_value,
    min_subtotal,
    starts_at,
    ends_at,
    is_active
)
VALUES (
    'WELCOME10',
    'Default welcome promotion for first-pass checkout testing and launch support.',
    'percent',
    10,
    20,
    NULL,
    NULL,
    TRUE
)
ON CONFLICT (code) DO NOTHING;

CREATE TABLE IF NOT EXISTS book_reviews (
    id BIGSERIAL PRIMARY KEY,
    book_id BIGINT NOT NULL REFERENCES books(id) ON DELETE CASCADE,
    customer_id BIGINT NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
    customer_name VARCHAR(180) NOT NULL,
    rating SMALLINT NOT NULL CHECK (rating BETWEEN 1 AND 5),
    comment TEXT NOT NULL,
    is_verified_purchase BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (book_id, customer_id)
);

DROP TRIGGER IF EXISTS trg_book_reviews_set_updated_at ON book_reviews;
CREATE TRIGGER trg_book_reviews_set_updated_at
BEFORE UPDATE ON book_reviews
FOR EACH ROW
EXECUTE FUNCTION set_updated_at();

CREATE INDEX IF NOT EXISTS idx_book_reviews_book_id_created_at
    ON book_reviews(book_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_book_reviews_customer_id
    ON book_reviews(customer_id);

CREATE TABLE IF NOT EXISTS return_requests (
    id BIGSERIAL PRIMARY KEY,
    invoice_id VARCHAR(80) NOT NULL REFERENCES invoices(id) ON DELETE CASCADE,
    invoice_item_id BIGINT NOT NULL REFERENCES invoice_items(id) ON DELETE CASCADE,
    customer_id BIGINT NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
    customer_name VARCHAR(180) NOT NULL,
    book_id BIGINT NOT NULL REFERENCES books(id) ON DELETE CASCADE,
    quantity INTEGER NOT NULL CHECK (quantity > 0),
    reason TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'requested'
        CHECK (status IN ('requested', 'approved', 'rejected', 'received', 'refunded')),
    admin_note TEXT NULL,
    refund_amount NUMERIC(10,2) NOT NULL DEFAULT 0 CHECK (refund_amount >= 0),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

DROP TRIGGER IF EXISTS trg_return_requests_set_updated_at ON return_requests;
CREATE TRIGGER trg_return_requests_set_updated_at
BEFORE UPDATE ON return_requests
FOR EACH ROW
EXECUTE FUNCTION set_updated_at();

CREATE INDEX IF NOT EXISTS idx_return_requests_invoice_id
    ON return_requests(invoice_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_return_requests_customer_id
    ON return_requests(customer_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_return_requests_status
    ON return_requests(status, created_at DESC);

CREATE EXTENSION IF NOT EXISTS pg_trgm;

CREATE INDEX IF NOT EXISTS idx_books_created_at ON books(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_books_category_id ON books(category_id);
CREATE INDEX IF NOT EXISTS idx_books_author_id ON books(author_id);
CREATE INDEX IF NOT EXISTS idx_books_price ON books(price);
CREATE INDEX IF NOT EXISTS idx_books_title_trgm ON books USING GIN (title gin_trgm_ops);
CREATE INDEX IF NOT EXISTS idx_authors_name_trgm ON authors USING GIN (name gin_trgm_ops);
CREATE INDEX IF NOT EXISTS idx_categories_name_trgm ON categories USING GIN (name gin_trgm_ops);

CREATE INDEX IF NOT EXISTS idx_invoices_status_created_at ON invoices(status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_invoices_customer_id_created_at ON invoices(customer_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_invoice_items_invoice_id ON invoice_items(invoice_id);
CREATE INDEX IF NOT EXISTS idx_invoice_items_book_id ON invoice_items(book_id);
CREATE INDEX IF NOT EXISTS idx_carts_customer_id ON carts(customer_id);

CREATE TABLE IF NOT EXISTS inventory_movements (
    id BIGSERIAL PRIMARY KEY,
    book_id BIGINT NOT NULL REFERENCES books(id) ON DELETE CASCADE,
    change_type VARCHAR(30) NOT NULL,
    quantity_change INTEGER NOT NULL,
    stock_before INTEGER NOT NULL CHECK (stock_before >= 0),
    stock_after INTEGER NOT NULL CHECK (stock_after >= 0),
    reference_type VARCHAR(30),
    reference_id VARCHAR(80),
    note TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT inventory_movements_change_type_check
        CHECK (change_type IN ('created', 'adjustment', 'sale', 'restock'))
);

CREATE INDEX IF NOT EXISTS idx_inventory_movements_book_id_created_at
    ON inventory_movements(book_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_inventory_movements_created_at
    ON inventory_movements(created_at DESC);

INSERT INTO inventory_movements (
    book_id,
    change_type,
    quantity_change,
    stock_before,
    stock_after,
    reference_type,
    reference_id,
    note
)
SELECT
    b.id,
    'created',
    b.stock,
    0,
    b.stock,
    'migration',
    'inventory-baseline',
    'Baseline stock snapshot created when inventory ledger was enabled'
FROM books b
WHERE NOT EXISTS (
    SELECT 1
    FROM inventory_movements im
    WHERE im.book_id = b.id
);

COMMIT;
