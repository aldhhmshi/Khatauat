-- Khatauat Phase 0 static schema reconciliation
-- Generated from the audited production schema copy; contains schema only.
-- Additive and idempotent: no DROP, DELETE, or data mutation statements.
PRAGMA foreign_keys = ON;
BEGIN;

-- Table: billing_ledger
CREATE TABLE IF NOT EXISTS billing_ledger (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 user_id INTEGER NOT NULL,
 order_id INTEGER,
 grant_id INTEGER,
 case_id INTEGER,
 entry_type TEXT NOT NULL CHECK(entry_type IN ('credit','consume_case','refund_reversal','admin_adjustment')),
 delta_cases INTEGER NOT NULL CHECK(delta_cases<>0),
 balance_after INTEGER NOT NULL CHECK(balance_after>=0),
 reference TEXT NOT NULL,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY(order_id) REFERENCES billing_orders(id) ON DELETE RESTRICT,
 FOREIGN KEY(grant_id) REFERENCES case_credit_grants(id) ON DELETE RESTRICT,
 FOREIGN KEY(case_id) REFERENCES problem_cases(id) ON DELETE RESTRICT
);

-- Table: billing_orders
CREATE TABLE IF NOT EXISTS billing_orders (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 order_uuid TEXT NOT NULL UNIQUE,
 user_id INTEGER NOT NULL,
 product_id INTEGER NOT NULL,
 product_code_snapshot TEXT NOT NULL,
 product_name_snapshot TEXT NOT NULL,
 product_type_snapshot TEXT NOT NULL CHECK(product_type_snapshot IN ('problem_pack','subscription_monthly','subscription_annual')),
 amount_minor INTEGER NOT NULL CHECK(amount_minor>=100),
 currency TEXT NOT NULL DEFAULT 'SAR' CHECK(length(currency)=3),
 included_cases_snapshot INTEGER NOT NULL CHECK(included_cases_snapshot>0),
 case_message_limit_snapshot INTEGER NOT NULL CHECK(case_message_limit_snapshot BETWEEN 3 AND 100),
 validity_days_snapshot INTEGER NOT NULL CHECK(validity_days_snapshot BETWEEN 1 AND 730),
 status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','paid','failed','canceled','refunded','partially_refunded','expired')),
 provider TEXT NOT NULL DEFAULT 'moyasar',
 provider_invoice_id TEXT UNIQUE,
 provider_invoice_url TEXT,
 provider_payment_id TEXT,
 idempotency_key TEXT NOT NULL UNIQUE,
 terms_version TEXT NOT NULL,
 privacy_version TEXT NOT NULL,
 terms_accepted_at TEXT NOT NULL,
 privacy_accepted_at TEXT NOT NULL,
 expires_at TEXT NOT NULL,
 paid_at TEXT,
 refunded_minor INTEGER NOT NULL DEFAULT 0 CHECK(refunded_minor>=0 AND refunded_minor<=amount_minor),
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY(product_id) REFERENCES billing_products(id) ON DELETE RESTRICT
);

-- Table: billing_products
CREATE TABLE IF NOT EXISTS billing_products (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 code TEXT NOT NULL UNIQUE,
 product_type TEXT NOT NULL CHECK(product_type IN ('problem_pack','subscription_monthly','subscription_annual')),
 name TEXT NOT NULL,
 description TEXT NOT NULL DEFAULT '',
 price_minor INTEGER NOT NULL CHECK(price_minor>=100),
 currency TEXT NOT NULL DEFAULT 'SAR' CHECK(length(currency)=3),
 included_cases INTEGER NOT NULL CHECK(included_cases>0 AND included_cases<=1000),
 case_message_limit INTEGER NOT NULL DEFAULT 12 CHECK(case_message_limit BETWEEN 3 AND 100),
 validity_days INTEGER NOT NULL CHECK(validity_days BETWEEN 1 AND 730),
 status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','inactive')),
 sort_order INTEGER NOT NULL DEFAULT 100,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Table: billing_subscriptions
CREATE TABLE IF NOT EXISTS billing_subscriptions (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 user_id INTEGER NOT NULL,
 product_id INTEGER NOT NULL,
 order_id INTEGER NOT NULL UNIQUE,
 status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','expired','canceled')),
 starts_at TEXT NOT NULL,
 ends_at TEXT NOT NULL,
 auto_renew INTEGER NOT NULL DEFAULT 0 CHECK(auto_renew IN (0,1)),
 cases_included INTEGER NOT NULL CHECK(cases_included>0),
 case_message_limit INTEGER NOT NULL CHECK(case_message_limit BETWEEN 3 AND 100),
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY(product_id) REFERENCES billing_products(id) ON DELETE RESTRICT,
 FOREIGN KEY(order_id) REFERENCES billing_orders(id) ON DELETE RESTRICT
);

-- Table: billing_webhook_events
CREATE TABLE IF NOT EXISTS billing_webhook_events (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 provider TEXT NOT NULL,
 event_id TEXT NOT NULL,
 event_type TEXT NOT NULL,
 payload_hash TEXT NOT NULL,
 order_uuid TEXT,
 invoice_id TEXT,
 payment_id TEXT,
 amount_minor INTEGER NOT NULL DEFAULT 0 CHECK(amount_minor>=0),
 currency TEXT NOT NULL DEFAULT 'SAR',
 refunded_minor INTEGER NOT NULL DEFAULT 0 CHECK(refunded_minor>=0),
 status TEXT NOT NULL DEFAULT 'queued' CHECK(status IN ('queued','processed','ignored','failed')),
 error_message TEXT,
 received_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 processed_at TEXT,
 UNIQUE(provider,event_id)
);

-- Table: calculator_definitions
CREATE TABLE IF NOT EXISTS calculator_definitions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    category TEXT NOT NULL DEFAULT 'general',
    icon TEXT,
    purpose TEXT,
    engine_key TEXT NOT NULL,
    entity TEXT,
    source_label TEXT,
    source_url TEXT,
    rule_version TEXT NOT NULL DEFAULT '1.0',
    verified_at TEXT,
    disclaimer TEXT,
    sort_order INTEGER NOT NULL DEFAULT 100,
    status TEXT NOT NULL DEFAULT 'published' CHECK(status IN ('draft','published')),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Table: case_credit_grants
CREATE TABLE IF NOT EXISTS case_credit_grants (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 user_id INTEGER NOT NULL,
 order_id INTEGER NOT NULL,
 subscription_id INTEGER,
 source_type TEXT NOT NULL,
 total_cases INTEGER NOT NULL CHECK(total_cases>0),
 remaining_cases INTEGER NOT NULL CHECK(remaining_cases>=0 AND remaining_cases<=total_cases),
 case_message_limit INTEGER NOT NULL CHECK(case_message_limit BETWEEN 3 AND 100),
 valid_from TEXT NOT NULL,
 expires_at TEXT,
 status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','exhausted','expired','revoked')),
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY(order_id) REFERENCES billing_orders(id) ON DELETE RESTRICT,
 FOREIGN KEY(subscription_id) REFERENCES billing_subscriptions(id) ON DELETE RESTRICT
);

-- Table: live_service_incidents
CREATE TABLE IF NOT EXISTS live_service_incidents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    target_id INTEGER NOT NULL,
    source_id INTEGER,
    issue_type TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'suspected' CHECK(status IN ('suspected','confirmed','resolved')),
    title TEXT NOT NULL,
    summary TEXT NOT NULL,
    workaround TEXT,
    first_seen_at TEXT,
    last_seen_at TEXT,
    expires_at TEXT NOT NULL,
    evidence_count INTEGER NOT NULL DEFAULT 0,
    user_report_count INTEGER NOT NULL DEFAULT 0,
    official_evidence_count INTEGER NOT NULL DEFAULT 0,
    official_evidence_url TEXT,
    confidence INTEGER NOT NULL DEFAULT 0,
    resolved_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(target_id,issue_type),
    FOREIGN KEY(target_id) REFERENCES social_watch_targets(id) ON DELETE CASCADE,
    FOREIGN KEY(source_id) REFERENCES source_registry(id) ON DELETE SET NULL
);

-- Table: official_entity_contacts
CREATE TABLE IF NOT EXISTS official_entity_contacts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    entity_key TEXT NOT NULL UNIQUE,
    entity_name TEXT NOT NULL,
    aliases TEXT,
    phone TEXT,
    email TEXT,
    support_url TEXT,
    branches_url TEXT,
    maps_query TEXT,
    source_url TEXT NOT NULL,
    trust_status TEXT NOT NULL DEFAULT 'needs_review' CHECK(trust_status IN ('verified','needs_review')),
    verified_at TEXT,
    priority INTEGER NOT NULL DEFAULT 50,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
, verification_level TEXT NOT NULL DEFAULT 'official_site_only', maps_enabled INTEGER NOT NULL DEFAULT 0, support_scope TEXT NOT NULL DEFAULT 'authority', notes TEXT);

-- Table: official_service_centers
CREATE TABLE IF NOT EXISTS official_service_centers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    contact_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    region TEXT,
    city TEXT,
    address TEXT,
    latitude REAL,
    longitude REAL,
    google_maps_url TEXT,
    source_url TEXT,
    verified_at TEXT,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(contact_id) REFERENCES official_entity_contacts(id) ON DELETE CASCADE
);

-- Table: official_source_support
CREATE TABLE IF NOT EXISTS official_source_support (
    source_id INTEGER PRIMARY KEY,
    contact_id INTEGER NOT NULL,
    support_scope TEXT NOT NULL DEFAULT 'official_site',
    verified_at TEXT,
    notes TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(source_id) REFERENCES source_registry(id) ON DELETE CASCADE,
    FOREIGN KEY(contact_id) REFERENCES official_entity_contacts(id) ON DELETE CASCADE
);

-- Table: official_x_accounts
CREATE TABLE IF NOT EXISTS official_x_accounts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    target_id INTEGER NOT NULL,
    source_id INTEGER,
    handle TEXT NOT NULL,
    x_url TEXT NOT NULL,
    account_role TEXT NOT NULL DEFAULT 'general' CHECK(account_role IN ('support','general','service')),
    verification_status TEXT NOT NULL DEFAULT 'verified' CHECK(verification_status IN ('verified','needs_review','rejected')),
    verified_from_url TEXT NOT NULL,
    verification_method TEXT NOT NULL DEFAULT 'official_html_link',
    verified_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(target_id,handle),
    FOREIGN KEY(target_id) REFERENCES social_watch_targets(id) ON DELETE CASCADE,
    FOREIGN KEY(source_id) REFERENCES source_registry(id) ON DELETE SET NULL
);

-- Table: policy_acceptances
CREATE TABLE IF NOT EXISTS policy_acceptances (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 user_id INTEGER NOT NULL,
 order_uuid TEXT,
 terms_version TEXT NOT NULL,
 privacy_version TEXT NOT NULL,
 context TEXT NOT NULL CHECK(context IN ('checkout','account','registration')),
 ip_hash TEXT,
 user_agent_hash TEXT,
 accepted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT
);

-- Table: problem_cases
CREATE TABLE IF NOT EXISTS problem_cases (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 case_ref TEXT NOT NULL UNIQUE,
 user_id INTEGER NOT NULL,
 grant_id INTEGER NOT NULL,
 status TEXT NOT NULL DEFAULT 'open' CHECK(status IN ('open','resolved','escalated','expired')),
 message_limit INTEGER NOT NULL CHECK(message_limit BETWEEN 3 AND 100),
 message_count INTEGER NOT NULL DEFAULT 0 CHECK(message_count>=0 AND message_count<=message_limit),
 opened_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 expires_at TEXT NOT NULL,
 closed_at TEXT,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY(grant_id) REFERENCES case_credit_grants(id) ON DELETE RESTRICT
);

-- Table: rate_limit_buckets
CREATE TABLE IF NOT EXISTS rate_limit_buckets (
 scope TEXT NOT NULL,
 key_hash TEXT NOT NULL,
 window_start INTEGER NOT NULL,
 request_count INTEGER NOT NULL DEFAULT 0 CHECK(request_count>=0),
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(scope,key_hash,window_start)
) WITHOUT ROWID;

-- Table: security_events
CREATE TABLE IF NOT EXISTS security_events (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 event_type TEXT NOT NULL,
 severity TEXT NOT NULL DEFAULT 'info' CHECK(severity IN ('info','warning','critical')),
 user_id INTEGER,
 ip_hash TEXT,
 user_agent_hash TEXT,
 metadata_json TEXT,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Table: service_problem_knowledge
CREATE TABLE IF NOT EXISTS service_problem_knowledge (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    knowledge_key TEXT NOT NULL UNIQUE,
    service_id INTEGER,
    title TEXT NOT NULL,
    trigger_terms TEXT NOT NULL,
    verified_facts_json TEXT NOT NULL DEFAULT '[]',
    diagnostic_questions_json TEXT NOT NULL DEFAULT '[]',
    source_title TEXT NOT NULL,
    source_url TEXT NOT NULL,
    trust_status TEXT NOT NULL DEFAULT 'needs_review' CHECK(trust_status IN ('verified','needs_review')),
    verified_at TEXT,
    priority INTEGER NOT NULL DEFAULT 50,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(service_id) REFERENCES services(id) ON DELETE SET NULL
);

-- Table: social_intelligence_scans
CREATE TABLE IF NOT EXISTS social_intelligence_scans (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    target_id INTEGER NOT NULL,
    provider TEXT NOT NULL DEFAULT 'exa',
    mode TEXT NOT NULL DEFAULT 'daily_batch',
    query_text TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'running' CHECK(status IN ('running','ok','failed')),
    result_count INTEGER NOT NULL DEFAULT 0,
    error_message TEXT,
    started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at TEXT,
    FOREIGN KEY(target_id) REFERENCES social_watch_targets(id) ON DELETE CASCADE
);

-- Table: social_signals
CREATE TABLE IF NOT EXISTS social_signals (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    target_id INTEGER NOT NULL,
    source_id INTEGER,
    network TEXT NOT NULL DEFAULT 'x',
    post_url TEXT NOT NULL UNIQUE,
    author_handle TEXT,
    title TEXT,
    excerpt TEXT,
    published_at TEXT,
    observed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    evidence_type TEXT NOT NULL DEFAULT 'user_report' CHECK(evidence_type IN ('official_content','user_report')),
    issue_type TEXT NOT NULL,
    solution_text TEXT,
    confidence INTEGER NOT NULL DEFAULT 0,
    official_confirmed INTEGER NOT NULL DEFAULT 0,
    review_status TEXT NOT NULL DEFAULT 'candidate' CHECK(review_status IN ('candidate','verified','dismissed','expired')),
    expires_at TEXT NOT NULL,
    content_hash TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(target_id) REFERENCES social_watch_targets(id) ON DELETE CASCADE,
    FOREIGN KEY(source_id) REFERENCES source_registry(id) ON DELETE SET NULL
);

-- Table: social_solution_knowledge
CREATE TABLE IF NOT EXISTS social_solution_knowledge (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    target_id INTEGER NOT NULL,
    source_id INTEGER,
    issue_type TEXT NOT NULL,
    problem_excerpt TEXT,
    solution_text TEXT NOT NULL,
    evidence_url TEXT NOT NULL UNIQUE,
    official_handle TEXT NOT NULL,
    first_seen_at TEXT NOT NULL,
    last_verified_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    valid_until TEXT NOT NULL,
    confidence INTEGER NOT NULL DEFAULT 90,
    status TEXT NOT NULL DEFAULT 'usable' CHECK(status IN ('usable','expired','dismissed')),
    content_hash TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(target_id) REFERENCES social_watch_targets(id) ON DELETE CASCADE,
    FOREIGN KEY(source_id) REFERENCES source_registry(id) ON DELETE SET NULL
);

-- Table: social_watch_targets
CREATE TABLE IF NOT EXISTS social_watch_targets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    target_key TEXT NOT NULL UNIQUE,
    source_id INTEGER,
    network TEXT NOT NULL DEFAULT 'x',
    entity_name TEXT NOT NULL,
    aliases TEXT,
    sector TEXT,
    official_handle TEXT,
    official_x_url TEXT,
    handle_status TEXT NOT NULL DEFAULT 'needs_review' CHECK(handle_status IN ('verified','needs_review','unknown')),
    handle_source_url TEXT,
    priority INTEGER NOT NULL DEFAULT 50,
    active INTEGER NOT NULL DEFAULT 1,
    last_scanned_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(source_id) REFERENCES source_registry(id) ON DELETE CASCADE
);

-- Table: user_ai_daily_usage
CREATE TABLE IF NOT EXISTS user_ai_daily_usage (
    user_id INTEGER NOT NULL,
    usage_date TEXT NOT NULL,
    request_count INTEGER NOT NULL DEFAULT 0,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(user_id,usage_date),
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Table: x_handle_discovery_log
CREATE TABLE IF NOT EXISTS x_handle_discovery_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    target_id INTEGER NOT NULL,
    discovered_handle TEXT,
    official_page_url TEXT,
    status TEXT NOT NULL,
    note TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(target_id) REFERENCES social_watch_targets(id) ON DELETE CASCADE
);

-- Index: idx_billing_ledger_user
CREATE INDEX IF NOT EXISTS idx_billing_ledger_user ON billing_ledger(user_id,id DESC);

-- Index: idx_billing_orders_status
CREATE INDEX IF NOT EXISTS idx_billing_orders_status ON billing_orders(status,created_at);

-- Index: idx_billing_orders_user
CREATE INDEX IF NOT EXISTS idx_billing_orders_user ON billing_orders(user_id,id DESC);

-- Index: idx_billing_subscriptions_user
CREATE INDEX IF NOT EXISTS idx_billing_subscriptions_user ON billing_subscriptions(user_id,status,ends_at);

-- Index: idx_billing_webhook_status
CREATE INDEX IF NOT EXISTS idx_billing_webhook_status ON billing_webhook_events(status,received_at);

-- Index: idx_case_grants_user
CREATE INDEX IF NOT EXISTS idx_case_grants_user ON case_credit_grants(user_id,status,expires_at);

-- Index: idx_live_incident_status
CREATE INDEX IF NOT EXISTS idx_live_incident_status ON live_service_incidents(status,expires_at,target_id);

-- Index: idx_official_x_handle
CREATE INDEX IF NOT EXISTS idx_official_x_handle ON official_x_accounts(handle,verification_status);

-- Index: idx_official_x_target
CREATE INDEX IF NOT EXISTS idx_official_x_target ON official_x_accounts(target_id,verification_status,active);

-- Index: idx_policy_acceptances_user
CREATE INDEX IF NOT EXISTS idx_policy_acceptances_user ON policy_acceptances(user_id,accepted_at);

-- Index: idx_problem_cases_user
CREATE INDEX IF NOT EXISTS idx_problem_cases_user ON problem_cases(user_id,status,expires_at);

-- Index: idx_problem_knowledge_service
CREATE INDEX IF NOT EXISTS idx_problem_knowledge_service ON service_problem_knowledge(service_id,trust_status,priority);

-- Index: idx_security_events_severity
CREATE INDEX IF NOT EXISTS idx_security_events_severity ON security_events(severity,created_at);

-- Index: idx_security_events_type
CREATE INDEX IF NOT EXISTS idx_security_events_type ON security_events(event_type,created_at);

-- Index: idx_service_centers_contact
CREATE INDEX IF NOT EXISTS idx_service_centers_contact ON official_service_centers(contact_id,active,city);

-- Index: idx_social_scan_target_time
CREATE INDEX IF NOT EXISTS idx_social_scan_target_time ON social_intelligence_scans(target_id,started_at);

-- Index: idx_social_signals_issue
CREATE INDEX IF NOT EXISTS idx_social_signals_issue ON social_signals(issue_type,evidence_type,official_confirmed);

-- Index: idx_social_signals_target_time
CREATE INDEX IF NOT EXISTS idx_social_signals_target_time ON social_signals(target_id,published_at,expires_at);

-- Index: idx_social_solution_target
CREATE INDEX IF NOT EXISTS idx_social_solution_target ON social_solution_knowledge(target_id,issue_type,status,valid_until);

-- Index: idx_social_watch_active
CREATE INDEX IF NOT EXISTS idx_social_watch_active ON social_watch_targets(active,priority,id);

-- Index: idx_social_watch_source
CREATE INDEX IF NOT EXISTS idx_social_watch_source ON social_watch_targets(source_id,network);

-- Index: idx_source_support_contact
CREATE INDEX IF NOT EXISTS idx_source_support_contact ON official_source_support(contact_id,source_id);

-- Index: idx_user_ai_usage_date
CREATE INDEX IF NOT EXISTS idx_user_ai_usage_date ON user_ai_daily_usage(usage_date,user_id);

-- Index: idx_x_discovery_target_time
CREATE INDEX IF NOT EXISTS idx_x_discovery_target_time ON x_handle_discovery_log(target_id,created_at);

-- Trigger: trg_billing_ledger_no_delete
CREATE TRIGGER IF NOT EXISTS trg_billing_ledger_no_delete BEFORE DELETE ON billing_ledger BEGIN SELECT RAISE(ABORT,'billing_ledger is append-only'); END;

-- Trigger: trg_billing_ledger_no_update
CREATE TRIGGER IF NOT EXISTS trg_billing_ledger_no_update BEFORE UPDATE ON billing_ledger BEGIN SELECT RAISE(ABORT,'billing_ledger is append-only'); END;

COMMIT;
