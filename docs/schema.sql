-- NetWatch: skema referensi PostgreSQL (setara dengan migrasi Laravel di server/database/migrations).
-- Tabel bawaan Laravel (sessions, cache, jobs, password_reset_tokens) tidak diulang di sini.
-- Semua timestamp memakai timestamptz agar aman lintas zona waktu.

CREATE TABLE users (
    id                 BIGSERIAL PRIMARY KEY,
    name               VARCHAR(255) NOT NULL,
    email              VARCHAR(255) NOT NULL UNIQUE,
    email_verified_at  TIMESTAMPTZ,
    password           VARCHAR(255) NOT NULL,
    role               VARCHAR(20)  NOT NULL DEFAULT 'viewer',
    is_active          BOOLEAN      NOT NULL DEFAULT TRUE,
    remember_token     VARCHAR(100),
    created_at         TIMESTAMPTZ,
    updated_at         TIMESTAMPTZ,
    CONSTRAINT users_role_check CHECK (role IN ('admin', 'operator', 'viewer'))
);

CREATE TABLE device_groups (
    id          BIGSERIAL PRIMARY KEY,
    name        VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    created_at  TIMESTAMPTZ,
    updated_at  TIMESTAMPTZ
);

CREATE TABLE enrollment_tokens (
    id              BIGSERIAL PRIMARY KEY,
    label           VARCHAR(100) NOT NULL,
    token_hash      CHAR(64)     NOT NULL UNIQUE,
    device_group_id BIGINT REFERENCES device_groups (id) ON DELETE SET NULL,
    max_uses        SMALLINT     NOT NULL DEFAULT 1,
    used_count      SMALLINT     NOT NULL DEFAULT 0,
    expires_at      TIMESTAMPTZ  NOT NULL,
    revoked_at      TIMESTAMPTZ,
    created_by      BIGINT REFERENCES users (id) ON DELETE SET NULL,
    created_at      TIMESTAMPTZ,
    updated_at      TIMESTAMPTZ,
    CONSTRAINT enrollment_tokens_usage_check CHECK (used_count >= 0 AND max_uses >= 1)
);

CREATE TABLE devices (
    id                  BIGSERIAL PRIMARY KEY,
    public_id           UUID         NOT NULL UNIQUE,
    device_group_id     BIGINT REFERENCES device_groups (id) ON DELETE SET NULL,
    enrollment_token_id BIGINT REFERENCES enrollment_tokens (id) ON DELETE SET NULL,
    hostname            VARCHAR(255) NOT NULL,
    display_name        VARCHAR(255),
    os_family           VARCHAR(20)  NOT NULL,
    os_version          VARCHAR(100),
    agent_version       VARCHAR(20)  NOT NULL,
    ip_address          INET,
    mac_address         MACADDR,
    token_hash          CHAR(64)     NOT NULL UNIQUE,
    latest_metrics      JSONB,
    last_seen_at        TIMESTAMPTZ,
    enrolled_at         TIMESTAMPTZ  NOT NULL,
    revoked_at          TIMESTAMPTZ,
    created_at          TIMESTAMPTZ,
    updated_at          TIMESTAMPTZ,
    CONSTRAINT devices_os_family_check CHECK (os_family IN ('windows', 'linux'))
);
CREATE INDEX devices_hostname_index        ON devices (hostname);
CREATE INDEX devices_device_group_id_index ON devices (device_group_id);
CREATE INDEX devices_last_seen_at_index    ON devices (last_seen_at);

CREATE TABLE device_heartbeats (
    id              BIGSERIAL PRIMARY KEY,
    device_id       BIGINT       NOT NULL REFERENCES devices (id) ON DELETE CASCADE,
    cpu_percent     NUMERIC(5,2) NOT NULL,
    memory_used_mb  INTEGER      NOT NULL,
    memory_total_mb INTEGER      NOT NULL,
    disk_used_gb    INTEGER      NOT NULL,
    disk_total_gb   INTEGER      NOT NULL,
    uptime_seconds  BIGINT       NOT NULL,
    logged_in_user  VARCHAR(255),
    recorded_at     TIMESTAMPTZ  NOT NULL
);
CREATE INDEX device_heartbeats_device_recorded_index ON device_heartbeats (device_id, recorded_at DESC);
CREATE INDEX device_heartbeats_recorded_at_index     ON device_heartbeats (recorded_at);

CREATE TABLE process_snapshots (
    id            BIGSERIAL PRIMARY KEY,
    device_id     BIGINT      NOT NULL REFERENCES devices (id) ON DELETE CASCADE,
    captured_at   TIMESTAMPTZ NOT NULL,
    process_count INTEGER     NOT NULL,
    processes     JSONB       NOT NULL
);
CREATE INDEX process_snapshots_device_captured_index ON process_snapshots (device_id, captured_at DESC);

CREATE TABLE device_commands (
    id            BIGSERIAL PRIMARY KEY,
    public_id     UUID        NOT NULL UNIQUE,
    device_id     BIGINT      NOT NULL REFERENCES devices (id) ON DELETE CASCADE,
    requested_by  BIGINT REFERENCES users (id) ON DELETE SET NULL,
    type          VARCHAR(40) NOT NULL,
    payload       JSONB,
    status        VARCHAR(20) NOT NULL DEFAULT 'pending',
    result        JSONB,
    error_message TEXT,
    expires_at    TIMESTAMPTZ NOT NULL,
    dispatched_at TIMESTAMPTZ,
    completed_at  TIMESTAMPTZ,
    created_at    TIMESTAMPTZ,
    updated_at    TIMESTAMPTZ,
    CONSTRAINT device_commands_status_check
        CHECK (status IN ('pending', 'dispatched', 'succeeded', 'failed', 'expired'))
);
CREATE INDEX device_commands_device_created_index ON device_commands (device_id, created_at DESC);
-- Antrean yang dibaca setiap heartbeat: indeks parsial menjaganya tetap kecil.
CREATE INDEX device_commands_pending_index ON device_commands (device_id, created_at) WHERE status = 'pending';

CREATE TABLE audit_logs (
    id         BIGSERIAL PRIMARY KEY,
    user_id    BIGINT REFERENCES users (id) ON DELETE SET NULL,
    device_id  BIGINT REFERENCES devices (id) ON DELETE SET NULL,
    action     VARCHAR(60) NOT NULL,
    ip_address INET,
    metadata   JSONB,
    created_at TIMESTAMPTZ NOT NULL
);
CREATE INDEX audit_logs_created_at_index ON audit_logs (created_at DESC);
CREATE INDEX audit_logs_action_index     ON audit_logs (action);
