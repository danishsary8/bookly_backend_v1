-- Server-side cart + invoice schema for Bookly (PostgreSQL)
-- NOTE: Keep CREATE TABLE IF NOT EXISTS to avoid breaking existing DBs.

BEGIN;

-- Admin table (in case your DB is missing it in schema_clean.sql)
CREATE TABLE IF NOT EXISTS admin (
    id BIGSERIAL PRIMARY KEY,
    first_name VARCHAR(80) NOT NULL,
    last_name VARCHAR(80) NOT NULL,
    email VARCHAR(180) NOT NULL UNIQUE,
    phone VARCHAR(25),
    role VARCHAR(40) NOT NULL DEFAULT 'admin',
    address TEXT,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_admin_email ON admin(email);

-- One active cart per customer
CREATE TABLE IF NOT EXISTS carts (
    id BIGSERIAL PRIMARY KEY,
    customer_id BIGINT NOT NULL UNIQUE REFERENCES customers(id) ON DELETE CASCADE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Cart line items
CREATE TABLE IF NOT EXISTS cart_items (
    id BIGSERIAL PRIMARY KEY,
    cart_id BIGINT NOT NULL REFERENCES carts(id) ON DELETE CASCADE,
    book_id BIGINT NOT NULL REFERENCES books(id) ON DELETE RESTRICT,
    quantity INTEGER NOT NULL CHECK (quantity > 0),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(cart_id, book_id)
);

CREATE INDEX IF NOT EXISTS idx_cart_items_cart_id ON cart_items(cart_id);
CREATE INDEX IF NOT EXISTS idx_cart_items_book_id ON cart_items(book_id);

-- Checkout result (invoice / order)
CREATE TABLE IF NOT EXISTS invoices (
    id VARCHAR(64) PRIMARY KEY,
    customer_id BIGINT NOT NULL REFERENCES customers(id) ON DELETE RESTRICT,
    customer_email VARCHAR(180) NOT NULL,
    customer_name VARCHAR(160) NOT NULL,
    payment_method VARCHAR(10) NOT NULL CHECK (payment_method IN ('card', 'cod')),
    status VARCHAR(10) NOT NULL CHECK (status IN ('paid', 'pending')) DEFAULT 'pending',
    shipping_address TEXT,

    subtotal NUMERIC(10,2) NOT NULL CHECK (subtotal >= 0),
    shipping NUMERIC(10,2) NOT NULL CHECK (shipping >= 0),
    tax NUMERIC(10,2) NOT NULL CHECK (tax >= 0),
    total NUMERIC(10,2) NOT NULL CHECK (total >= 0),

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_invoices_customer_id ON invoices(customer_id);
CREATE INDEX IF NOT EXISTS idx_invoices_created_at ON invoices(created_at DESC);

-- Snapshot items for invoices
CREATE TABLE IF NOT EXISTS invoice_items (
    id BIGSERIAL PRIMARY KEY,
    invoice_id VARCHAR(64) NOT NULL REFERENCES invoices(id) ON DELETE CASCADE,
    book_id BIGINT,
    title VARCHAR(255) NOT NULL,
    author_name VARCHAR(120) NOT NULL,
    unit_price NUMERIC(10,2) NOT NULL CHECK (unit_price >= 0),
    quantity INTEGER NOT NULL CHECK (quantity > 0),
    line_total NUMERIC(10,2) NOT NULL CHECK (line_total >= 0)
);

CREATE INDEX IF NOT EXISTS idx_invoice_items_invoice_id ON invoice_items(invoice_id);

COMMIT;

