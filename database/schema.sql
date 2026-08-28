PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE COLLATE NOCASE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'user' CHECK(role IN ('owner','user')),
    notifications_enabled INTEGER NOT NULL DEFAULT 1,
    notification_frequency TEXT NOT NULL DEFAULT 'weekly' CHECK(notification_frequency IN ('instant','daily','weekly')),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT
);

CREATE TABLE IF NOT EXISTS categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    description TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS services (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    category_id INTEGER,
    name TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    official_entity TEXT NOT NULL DEFAULT '',
    summary TEXT NOT NULL DEFAULT '',
    beneficiaries TEXT,
    eligibility TEXT,
    requirements TEXT,
    notes TEXT,
    official_url TEXT,
    official_platform TEXT,
    status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','published')),
    indexable INTEGER NOT NULL DEFAULT 1,
    seo_title TEXT,
    seo_description TEXT,
    published_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(category_id) REFERENCES categories(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_services_status ON services(status);
CREATE INDEX IF NOT EXISTS idx_services_category ON services(category_id);

CREATE TABLE IF NOT EXISTS sources (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    entity TEXT,
    url TEXT NOT NULL,
    monitor_enabled INTEGER NOT NULL DEFAULT 0,
    verified_at TEXT,
    verified_by TEXT,
    last_checked_at TEXT,
    etag TEXT,
    last_modified TEXT,
    content_hash TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS service_steps (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    service_id INTEGER NOT NULL,
    position INTEGER NOT NULL DEFAULT 1,
    title TEXT NOT NULL,
    entity TEXT,
    platform TEXT,
    prerequisite TEXT,
    action_text TEXT,
    output_text TEXT,
    official_url TEXT NOT NULL,
    source_id INTEGER,
    depends_on_step_id INTEGER,
    next_step_id INTEGER,
    is_blocking INTEGER NOT NULL DEFAULT 0,
    visibility_key TEXT,
    trust_status TEXT NOT NULL DEFAULT 'needs_review' CHECK(trust_status IN ('verified','needs_review')),
    verified_at TEXT,
    verified_by TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(service_id) REFERENCES services(id) ON DELETE CASCADE,
    FOREIGN KEY(source_id) REFERENCES sources(id) ON DELETE SET NULL,
    FOREIGN KEY(depends_on_step_id) REFERENCES service_steps(id) ON DELETE SET NULL,
    FOREIGN KEY(next_step_id) REFERENCES service_steps(id) ON DELETE SET NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_steps_service_position ON service_steps(service_id, position);

CREATE TABLE IF NOT EXISTS service_relations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    service_id INTEGER NOT NULL,
    from_step_id INTEGER,
    to_step_id INTEGER,
    relation_type TEXT NOT NULL DEFAULT 'output_to_prerequisite',
    output_label TEXT,
    prerequisite_label TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(service_id) REFERENCES services(id) ON DELETE CASCADE,
    FOREIGN KEY(from_step_id) REFERENCES service_steps(id) ON DELETE CASCADE,
    FOREIGN KEY(to_step_id) REFERENCES service_steps(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS path_questions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    service_id INTEGER NOT NULL,
    question_key TEXT NOT NULL,
    question_text TEXT NOT NULL,
    position INTEGER NOT NULL DEFAULT 1,
    is_required INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(service_id) REFERENCES services(id) ON DELETE CASCADE,
    UNIQUE(service_id, question_key)
);

CREATE TABLE IF NOT EXISTS path_question_options (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    question_id INTEGER NOT NULL,
    option_key TEXT NOT NULL,
    label TEXT NOT NULL,
    position INTEGER NOT NULL DEFAULT 1,
    FOREIGN KEY(question_id) REFERENCES path_questions(id) ON DELETE CASCADE,
    UNIQUE(question_id, option_key)
);

CREATE TABLE IF NOT EXISTS path_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    service_id INTEGER NOT NULL,
    step_id INTEGER NOT NULL,
    question_key TEXT NOT NULL,
    operator TEXT NOT NULL DEFAULT 'eq' CHECK(operator IN ('eq','neq','in','not_in')),
    value TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(service_id) REFERENCES services(id) ON DELETE CASCADE,
    FOREIGN KEY(step_id) REFERENCES service_steps(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS user_paths (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    service_id INTEGER NOT NULL,
    answers_json TEXT NOT NULL DEFAULT '{}',
    last_content_version TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY(service_id) REFERENCES services(id) ON DELETE CASCADE,
    UNIQUE(user_id, service_id)
);

CREATE TABLE IF NOT EXISTS user_step_progress (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_path_id INTEGER NOT NULL,
    step_id INTEGER NOT NULL,
    completed INTEGER NOT NULL DEFAULT 0,
    completed_at TEXT,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(user_path_id) REFERENCES user_paths(id) ON DELETE CASCADE,
    FOREIGN KEY(step_id) REFERENCES service_steps(id) ON DELETE CASCADE,
    UNIQUE(user_path_id, step_id)
);

CREATE TABLE IF NOT EXISTS content_changes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    service_id INTEGER NOT NULL,
    step_id INTEGER,
    field_name TEXT NOT NULL,
    change_summary TEXT NOT NULL,
    source_id INTEGER,
    status TEXT NOT NULL DEFAULT 'approved' CHECK(status IN ('draft','approved')),
    changed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    changed_by TEXT,
    FOREIGN KEY(service_id) REFERENCES services(id) ON DELETE CASCADE,
    FOREIGN KEY(step_id) REFERENCES service_steps(id) ON DELETE SET NULL,
    FOREIGN KEY(source_id) REFERENCES sources(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS trust_reports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    service_id INTEGER NOT NULL,
    step_id INTEGER,
    user_id INTEGER,
    message TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'new' CHECK(status IN ('new','reviewing','closed')),
    reviewed_by INTEGER,
    reviewed_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(service_id) REFERENCES services(id) ON DELETE CASCADE,
    FOREIGN KEY(step_id) REFERENCES service_steps(id) ON DELETE SET NULL,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY(reviewed_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS source_snapshots (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_id INTEGER NOT NULL,
    content_hash TEXT NOT NULL,
    storage_path TEXT NOT NULL,
    captured_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(source_id) REFERENCES sources(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS source_checks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_id INTEGER NOT NULL,
    status TEXT NOT NULL,
    http_status INTEGER,
    content_hash TEXT,
    error_message TEXT,
    checked_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(source_id) REFERENCES sources(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_source_checks_source_date ON source_checks(source_id, checked_at DESC);

CREATE TABLE IF NOT EXISTS updates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    entity TEXT,
    old_text TEXT,
    new_text TEXT,
    impact TEXT,
    source_id INTEGER,
    status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','published','rejected')),
    detected_at TEXT,
    verified_at TEXT,
    verified_by TEXT,
    published_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(source_id) REFERENCES sources(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS articles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    summary TEXT NOT NULL DEFAULT '',
    content TEXT NOT NULL DEFAULT '',
    featured_image TEXT,
    seo_title TEXT,
    seo_description TEXT,
    source_urls TEXT NOT NULL DEFAULT '',
    verification_notes TEXT NOT NULL DEFAULT '',
    verified_at TEXT,
    verified_by TEXT,
    ai_draft_id INTEGER,
    status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','published')),
    published_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS calculators (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    entity TEXT,
    platform TEXT,
    purpose TEXT,
    beneficiaries TEXT,
    instructions TEXT,
    official_url TEXT,
    source_url TEXT,
    verified_at TEXT,
    status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','published')),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS follows (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    follow_type TEXT NOT NULL CHECK(follow_type IN ('service','category','entity','topic')),
    follow_id INTEGER NOT NULL,
    frequency TEXT NOT NULL DEFAULT 'weekly' CHECK(frequency IN ('instant','daily','weekly')),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE(user_id, follow_type, follow_id)
);

CREATE TABLE IF NOT EXISTS notifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    title TEXT NOT NULL,
    message TEXT NOT NULL,
    link TEXT,
    channel TEXT NOT NULL DEFAULT 'in_app' CHECK(channel IN ('in_app','email')),
    status TEXT NOT NULL DEFAULT 'queued' CHECK(status IN ('queued','sent','read','failed')),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at TEXT,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS ai_drafts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    topic TEXT NOT NULL,
    audience TEXT,
    keyword TEXT,
    sources_text TEXT,
    result_text TEXT,
    structured_json TEXT,
    provider TEXT,
    error_detail TEXT,
    status TEXT NOT NULL DEFAULT 'draft',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS contact_messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    email TEXT NOT NULL,
    message TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'new',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ad_experiments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    placement TEXT NOT NULL,
    variant_a TEXT,
    variant_b TEXT,
    traffic_split INTEGER NOT NULL DEFAULT 50,
    status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','running','paused','ended')),
    starts_at TEXT,
    ends_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ad_experiment_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    experiment_id INTEGER NOT NULL,
    variant TEXT NOT NULL CHECK(variant IN ('A','B')),
    event_type TEXT NOT NULL CHECK(event_type IN ('impression','click')),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(experiment_id) REFERENCES ad_experiments(id) ON DELETE CASCADE
);

-- v1.4.1 privacy-aware first-party traffic analytics
CREATE TABLE IF NOT EXISTS traffic_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_type TEXT NOT NULL DEFAULT 'pageview',
    page_path TEXT NOT NULL,
    referrer_host TEXT,
    source_category TEXT,
    device_type TEXT,
    session_hash TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_traffic_events_created ON traffic_events(created_at);
CREATE INDEX IF NOT EXISTS idx_traffic_events_path ON traffic_events(page_path, created_at);
CREATE INDEX IF NOT EXISTS idx_traffic_events_source ON traffic_events(source_category, created_at);

-- Khatauat 2.0: national source registry, AI operations, integrations and marketing OS
CREATE TABLE IF NOT EXISTS source_registry (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    entity TEXT,
    sector TEXT NOT NULL DEFAULT 'عام',
    authority_type TEXT NOT NULL DEFAULT 'government' CHECK(authority_type IN ('government','semi_government','government_platform','regulator','official_gazette','reference')),
    source_role TEXT NOT NULL DEFAULT 'reference' CHECK(source_role IN ('reference','regulation','service','execution','data','verification','directory')),
    url TEXT NOT NULL UNIQUE,
    domain TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'candidate' CHECK(status IN ('candidate','approved','active','paused','rejected')),
    trust_level TEXT NOT NULL DEFAULT 'official' CHECK(trust_level IN ('official','verified_platform','candidate')),
    discovery_method TEXT NOT NULL DEFAULT 'seed',
    auto_monitor INTEGER NOT NULL DEFAULT 1,
    last_checked_at TEXT,
    last_http_status INTEGER,
    notes TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_source_registry_sector ON source_registry(sector,status);
CREATE INDEX IF NOT EXISTS idx_source_registry_domain ON source_registry(domain);

CREATE TABLE IF NOT EXISTS integration_catalog (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    provider_key TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    category TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'not_configured' CHECK(status IN ('not_configured','configured','connected','needs_attention','disabled')),
    enabled INTEGER NOT NULL DEFAULT 0,
    public_config_json TEXT NOT NULL DEFAULT '{}',
    secret_env_keys TEXT NOT NULL DEFAULT '[]',
    capabilities_json TEXT NOT NULL DEFAULT '[]',
    last_checked_at TEXT,
    notes TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS marketing_campaigns (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    objective TEXT NOT NULL DEFAULT '',
    audience TEXT NOT NULL DEFAULT '',
    offer_text TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','ready','running','paused','completed')),
    ai_strategy TEXT,
    created_by INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS marketing_assets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    campaign_id INTEGER NOT NULL,
    platform_key TEXT NOT NULL,
    asset_type TEXT NOT NULL DEFAULT 'post',
    title TEXT,
    content TEXT NOT NULL DEFAULT '',
    cta TEXT,
    hashtags TEXT,
    status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','approved','scheduled','published','rejected')),
    scheduled_at TEXT,
    external_id TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(campaign_id) REFERENCES marketing_campaigns(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_marketing_assets_campaign ON marketing_assets(campaign_id,status);

CREATE TABLE IF NOT EXISTS marketing_publications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    asset_id INTEGER NOT NULL,
    destination_key TEXT NOT NULL DEFAULT 'automation_webhook',
    status TEXT NOT NULL DEFAULT 'queued' CHECK(status IN ('queued','dispatching','dispatched','published','failed','cancelled')),
    scheduled_at TEXT,
    dispatched_at TEXT,
    external_id TEXT,
    response_excerpt TEXT,
    error_detail TEXT,
    attempts INTEGER NOT NULL DEFAULT 0,
    created_by INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(asset_id) REFERENCES marketing_assets(id) ON DELETE CASCADE,
    FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_marketing_publications_due ON marketing_publications(status,scheduled_at);

CREATE TABLE IF NOT EXISTS ai_operations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    operation_type TEXT NOT NULL,
    title TEXT NOT NULL,
    input_text TEXT NOT NULL DEFAULT '',
    result_text TEXT,
    status TEXT NOT NULL DEFAULT 'queued' CHECK(status IN ('queued','running','completed','failed','awaiting_approval','approved','rejected')),
    approval_required INTEGER NOT NULL DEFAULT 1,
    error_detail TEXT,
    created_by INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    executed_at TEXT,
    FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_ai_operations_status ON ai_operations(status,created_at DESC);

CREATE TABLE IF NOT EXISTS audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_user_id INTEGER,
    actor_type TEXT NOT NULL DEFAULT 'user',
    action TEXT NOT NULL,
    entity_type TEXT,
    entity_id TEXT,
    summary TEXT,
    metadata_json TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_audit_logs_created ON audit_logs(created_at DESC);
