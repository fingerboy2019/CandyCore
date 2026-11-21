-- 邮件收发系统 · 基建表结构（PostgreSQL）
-- 用法：psql -U candy -d okr_candy -f Doc/邮件/Mail表结构.sql
-- 原则：只建表，不动任何已有数据

CREATE TABLE IF NOT EXISTS sa_mail_accounts (
    id              serial PRIMARY KEY,
    name            varchar(80)  NOT NULL DEFAULT '',
    email           varchar(160) NOT NULL,
    imap_host       varchar(160) NOT NULL DEFAULT '',
    imap_port       int          NOT NULL DEFAULT 993,
    imap_secure     varchar(8)   NOT NULL DEFAULT 'ssl',
    username        varchar(160) NOT NULL DEFAULT '',
    secret          text         NOT NULL DEFAULT '',
    smtp_host       varchar(160) NOT NULL DEFAULT '',
    smtp_port       int          NOT NULL DEFAULT 465,
    smtp_secure     varchar(8)   NOT NULL DEFAULT 'ssl',
    from_name       varchar(80)  NOT NULL DEFAULT '',
    status          smallint     NOT NULL DEFAULT 1,
    created_at      timestamp    NULL,
    updated_at      timestamp    NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_mail_accounts_email ON sa_mail_accounts (email);

CREATE TABLE IF NOT EXISTS sa_mail_folders (
    id              serial PRIMARY KEY,
    account_id      int          NOT NULL,
    name            varchar(160) NOT NULL,
    uidvalidity     bigint       NOT NULL DEFAULT 0,
    last_uid        bigint       NOT NULL DEFAULT 0,
    last_sync_at    timestamp    NULL,
    error           text         NOT NULL DEFAULT '',
    created_at      timestamp    NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_mail_folders ON sa_mail_folders (account_id, name);

CREATE TABLE IF NOT EXISTS sa_mail_threads (
    id              serial PRIMARY KEY,
    account_id      int          NOT NULL,
    thread_key      varchar(190) NOT NULL,
    subject_norm    varchar(255) NOT NULL DEFAULT '',
    message_count   int          NOT NULL DEFAULT 0,
    created_at      timestamp    NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_mail_threads ON sa_mail_threads (account_id, thread_key);

CREATE TABLE IF NOT EXISTS sa_mail_messages (
    id              serial PRIMARY KEY,
    account_id      int          NOT NULL,
    folder_id       int          NOT NULL,
    uid             bigint       NOT NULL,
    thread_id       int          NULL,
    message_id      varchar(255) NOT NULL DEFAULT '',
    in_reply_to     varchar(255) NOT NULL DEFAULT '',
    refs            text         NOT NULL DEFAULT '',
    subject         text         NOT NULL DEFAULT '',
    from_name       varchar(255) NOT NULL DEFAULT '',
    from_addr       varchar(190) NOT NULL DEFAULT '',
    to_addrs        text         NOT NULL DEFAULT '',
    cc_addrs        text         NOT NULL DEFAULT '',
    sent_at         timestamp    NULL,
    received_at     timestamp    NULL,
    size            int          NOT NULL DEFAULT 0,
    has_attachment  boolean      NOT NULL DEFAULT false,
    body_text       text         NOT NULL DEFAULT '',
    body_html       text         NOT NULL DEFAULT '',
    raw_path        varchar(255) NOT NULL DEFAULT '',
    direction       varchar(8)   NOT NULL DEFAULT 'in',
    analysis_status smallint     NOT NULL DEFAULT 0,
    created_at      timestamp    NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_mail_messages ON sa_mail_messages (account_id, folder_id, uid);
CREATE INDEX IF NOT EXISTS ix_mail_messages_from   ON sa_mail_messages (from_addr);
CREATE INDEX IF NOT EXISTS ix_mail_messages_thread ON sa_mail_messages (thread_id);
CREATE INDEX IF NOT EXISTS ix_mail_messages_sent   ON sa_mail_messages (sent_at);

CREATE TABLE IF NOT EXISTS sa_mail_attachments (
    id              serial PRIMARY KEY,
    message_id      int          NOT NULL,
    filename        varchar(255) NOT NULL DEFAULT '',
    mime            varchar(120) NOT NULL DEFAULT '',
    size            int          NOT NULL DEFAULT 0,
    cid             varchar(190) NOT NULL DEFAULT '',
    oss_path        varchar(255) NOT NULL DEFAULT '',
    storage         varchar(16)  NOT NULL DEFAULT 'local',
    created_at      timestamp    NULL
);
CREATE INDEX IF NOT EXISTS ix_mail_attachments_msg ON sa_mail_attachments (message_id);
