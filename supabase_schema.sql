-- ====================================================================
-- Reaching Out Initiative (ROI) Platform - Supabase PostgreSQL Schema
-- Location: Mombasa, Kenya
-- Version: 2.0.0 (2026 Edition)
-- ====================================================================

-- Enable UUID extension if needed
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

-- 1. Admin Users Table (Singular Global Slot)
CREATE TABLE IF NOT EXISTS admin_users (
    id SERIAL PRIMARY KEY,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    last_login TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- 2. Blog Posts Table
CREATE TABLE IF NOT EXISTS blog_posts (
    id SERIAL PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    slug VARCHAR(255) UNIQUE NOT NULL,
    summary TEXT NOT NULL,
    content TEXT NOT NULL,
    category VARCHAR(100) DEFAULT 'Social Impact',
    author VARCHAR(150) DEFAULT 'ROI Communications',
    image_url TEXT,
    is_published BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_blog_posts_slug ON blog_posts(slug);
CREATE INDEX idx_blog_posts_category ON blog_posts(category);
CREATE INDEX idx_blog_posts_published ON blog_posts(is_published);

-- 3. Media Items (ROI TV / Reaching Out Media)
CREATE TABLE IF NOT EXISTS media_items (
    id SERIAL PRIMARY KEY,
    youtube_id VARCHAR(50) UNIQUE NOT NULL,
    title VARCHAR(255) NOT NULL,
    category VARCHAR(100) NOT NULL, -- Mentorship Sessions, Community Outreach, Educational Content, Events & Conferences, Success Stories
    summary TEXT,
    thumbnail_url TEXT,
    duration VARCHAR(20) DEFAULT '5:30',
    is_featured BOOLEAN DEFAULT FALSE,
    published_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_media_items_youtube_id ON media_items(youtube_id);
CREATE INDEX idx_media_items_category ON media_items(category);
CREATE INDEX idx_media_items_featured ON media_items(is_featured);

-- 4. Events Table
CREATE TABLE IF NOT EXISTS events (
    id SERIAL PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    date VARCHAR(100) NOT NULL,
    time VARCHAR(100) DEFAULT '09:00 AM EAT',
    location VARCHAR(255) DEFAULT 'Mombasa, Kenya',
    description TEXT NOT NULL,
    category VARCHAR(100) DEFAULT 'Conference',
    image_url TEXT,
    is_active BOOLEAN DEFAULT TRUE
);

CREATE INDEX idx_events_active ON events(is_active);

-- 5. Volunteers Registry Table
CREATE TABLE IF NOT EXISTS volunteers (
    id SERIAL PRIMARY KEY,
    full_name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    phone VARCHAR(50) NOT NULL,
    primary_skill VARCHAR(100) NOT NULL,
    availability VARCHAR(255) NOT NULL,
    motivation TEXT,
    status VARCHAR(50) DEFAULT 'Pending Review',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_volunteers_email ON volunteers(email);
CREATE INDEX idx_volunteers_skill ON volunteers(primary_skill);

-- 6. Donations Table (Multi-Currency & Gateways)
CREATE TABLE IF NOT EXISTS donations (
    id SERIAL PRIMARY KEY,
    donor_name VARCHAR(255) DEFAULT 'Anonymous',
    email VARCHAR(255),
    amount DOUBLE PRECISION NOT NULL,
    currency VARCHAR(10) DEFAULT 'KES', -- KES, USD, EUR, GBP
    gateway VARCHAR(50) NOT NULL, -- Paystack, Stripe, M-Pesa KCB, M-Pesa Equity
    frequency VARCHAR(20) DEFAULT 'one-time',
    reference VARCHAR(100) UNIQUE NOT NULL,
    checkout_request_id VARCHAR(100), -- Deterministic Daraja STK identifier
    merchant_request_id VARCHAR(100), -- Deterministic Daraja merchant identifier
    status VARCHAR(50) DEFAULT 'Completed',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_donations_reference ON donations(reference);
CREATE INDEX idx_donations_checkout_id ON donations(checkout_request_id);
CREATE INDEX idx_donations_merchant_id ON donations(merchant_request_id);

-- 7. Contact Inquiries Table
CREATE TABLE IF NOT EXISTS inquiries (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    subject VARCHAR(255) DEFAULT 'General Inquiry',
    message TEXT NOT NULL,
    status VARCHAR(50) DEFAULT 'New',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- 8. Immutable Security Audit Logs Table
CREATE TABLE IF NOT EXISTS audit_logs (
    id SERIAL PRIMARY KEY,
    admin_email VARCHAR(255) NOT NULL,
    action VARCHAR(100) NOT NULL, -- "blog created", "blog edited", "event deleted", "login succeeded", "login failed"
    details TEXT,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_audit_logs_email ON audit_logs(admin_email);
CREATE INDEX idx_audit_logs_action ON audit_logs(action);

-- Row Level Security (RLS) Policies Example for Supabase
ALTER TABLE blog_posts ENABLE ROW LEVEL SECURITY;
CREATE POLICY "Public read access for published blog posts" ON blog_posts
    FOR SELECT USING (is_published = true);

ALTER TABLE media_items ENABLE ROW LEVEL SECURITY;
CREATE POLICY "Public read access for media items" ON media_items
    FOR SELECT USING (true);

ALTER TABLE events ENABLE ROW LEVEL SECURITY;
CREATE POLICY "Public read access for active events" ON events
    FOR SELECT USING (is_active = true);

-- 9. Ticketing foundation
CREATE TABLE IF NOT EXISTS ticket_types (
    id SERIAL PRIMARY KEY,
    event_id INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
    name VARCHAR(120) NOT NULL,
    description TEXT,
    price DOUBLE PRECISION DEFAULT 0,
    currency VARCHAR(10) DEFAULT 'KES',
    quantity INTEGER,
    sold_count INTEGER DEFAULT 0,
    sales_start TIMESTAMP,
    sales_end TIMESTAMP,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ticket_orders (
    id SERIAL PRIMARY KEY,
    event_id INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
    buyer_name VARCHAR(255) NOT NULL,
    buyer_email VARCHAR(255) NOT NULL,
    buyer_phone VARCHAR(50),
    gateway VARCHAR(50) NOT NULL,
    reference VARCHAR(100) UNIQUE NOT NULL,
    checkout_request_id VARCHAR(100),
    merchant_request_id VARCHAR(100),
    amount DOUBLE PRECISION NOT NULL,
    currency VARCHAR(10) DEFAULT 'KES',
    status VARCHAR(80) DEFAULT 'Pending Payment',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    paid_at TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ticket_order_items (
    id SERIAL PRIMARY KEY,
    ticket_order_id INTEGER NOT NULL REFERENCES ticket_orders(id) ON DELETE CASCADE,
    ticket_type_id INTEGER NOT NULL REFERENCES ticket_types(id) ON DELETE CASCADE,
    quantity INTEGER NOT NULL,
    unit_price DOUBLE PRECISION NOT NULL
);

CREATE TABLE IF NOT EXISTS tickets (
    id SERIAL PRIMARY KEY,
    ticket_order_id INTEGER NOT NULL REFERENCES ticket_orders(id) ON DELETE CASCADE,
    ticket_type_id INTEGER NOT NULL REFERENCES ticket_types(id) ON DELETE CASCADE,
    code VARCHAR(40) UNIQUE NOT NULL,
    attendee_name VARCHAR(255),
    attendee_email VARCHAR(255),
    status VARCHAR(40) DEFAULT 'valid',
    issued_at TIMESTAMP,
    checked_in_at TIMESTAMP
);
