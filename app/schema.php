<?php
declare(strict_types=1);

/**
 * SQLite 表结构（幂等执行）。
 */
return [
    // 管理员
    'CREATE TABLE IF NOT EXISTS admins (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        username   TEXT NOT NULL UNIQUE,
        password   TEXT NOT NULL,
        email      TEXT NOT NULL DEFAULT "",
        created_at INTEGER NOT NULL
    )',

    // 兑换码
    'CREATE TABLE IF NOT EXISTS codes (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        code         TEXT NOT NULL UNIQUE,
        package      TEXT NOT NULL,
        total_times  INTEGER NOT NULL DEFAULT 1,
        used_times   INTEGER NOT NULL DEFAULT 0,
        bound_phone  TEXT,
        status       TEXT NOT NULL DEFAULT "active",        -- active 正常 / disabled 停用
        expires_at   INTEGER,                                -- 过期时间戳，NULL 永久
        created_at   INTEGER NOT NULL,
        updated_at   INTEGER NOT NULL
    )',
    'CREATE INDEX IF NOT EXISTS idx_codes_status ON codes(status)',
    'CREATE INDEX IF NOT EXISTS idx_codes_bound_phone ON codes(bound_phone)',

    // 查询日志（用户查询 + 后台敏感操作共用）
    'CREATE TABLE IF NOT EXISTS query_logs (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        code       TEXT NOT NULL,
        phone      TEXT NOT NULL DEFAULT "",
        result     TEXT NOT NULL DEFAULT "",
        ip         TEXT NOT NULL DEFAULT "",
        user_agent TEXT NOT NULL DEFAULT "",
        admin_id   INTEGER,
        action     TEXT NOT NULL DEFAULT "query",            -- query / admin_create / admin_disable / admin_enable / admin_rebind
        created_at INTEGER NOT NULL
    )',
    'CREATE INDEX IF NOT EXISTS idx_logs_created ON query_logs(created_at)',
    'CREATE INDEX IF NOT EXISTS idx_logs_code ON query_logs(code)',

    // 邮箱验证码（用于修改绑定手机号）
    'CREATE TABLE IF NOT EXISTS email_verifications (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        code_id    INTEGER NOT NULL,
        email      TEXT NOT NULL,
        code       TEXT NOT NULL,
        new_phone  TEXT NOT NULL,
        consumed   INTEGER NOT NULL DEFAULT 0,
        expires_at INTEGER NOT NULL,
        created_at INTEGER NOT NULL,
        FOREIGN KEY(code_id) REFERENCES codes(id)
    )',
    'CREATE INDEX IF NOT EXISTS idx_emailv_pending ON email_verifications(code_id, consumed, expires_at)',
];
