<?php
// src/migrations/versions/007_CreateAITables.php
// =============================================
// Creates the six AI-related tables:
//
//   ai_models         – live model catalog per provider, refreshed
//                       by a cron job in Phase 2
//   ai_health_log     – every provider ping, one row per check
//   ai_blacklist      – providers marked dead, with a TTL
//   ai_call_log       – every AI call, for usage tracking + rate limit
//   report_memory     – last N AI-generated summaries (Phase 5)
//   ai_chat_history   – admin chat messages (Phase 8)
//
// All six are created now so later phases don't need new migrations
// for the base schema.

require_once __DIR__ . '/../Migration.php';

class Migration_007_CreateAITables extends Migration
{
    public function up()
    {
        // ───── ai_models ─────
        $this->exec("
            CREATE TABLE IF NOT EXISTS ai_models (
                id              INT AUTO_INCREMENT PRIMARY KEY,
                provider        VARCHAR(50) NOT NULL,
                model_id        VARCHAR(200) NOT NULL,
                display_name    VARCHAR(200) NULL,
                is_free         TINYINT(1) DEFAULT 0,
                context_length  INT DEFAULT 0,
                description     TEXT NULL,
                last_seen       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_provider_model (provider, model_id),
                INDEX idx_provider_free (provider, is_free)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ───── ai_health_log ─────
        $this->exec("
            CREATE TABLE IF NOT EXISTS ai_health_log (
                id              BIGINT AUTO_INCREMENT PRIMARY KEY,
                provider        VARCHAR(50) NOT NULL,
                status          VARCHAR(20) NOT NULL,
                latency_ms      INT DEFAULT 0,
                error_message   TEXT NULL,
                checked_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_provider_time (provider, checked_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ───── ai_blacklist ─────
        $this->exec("
            CREATE TABLE IF NOT EXISTS ai_blacklist (
                id              INT AUTO_INCREMENT PRIMARY KEY,
                provider        VARCHAR(50) NOT NULL,
                reason          VARCHAR(255) NULL,
                expires_at      DATETIME NOT NULL,
                created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_provider_expiry (provider, expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ───── ai_call_log ─────
        $this->exec("
            CREATE TABLE IF NOT EXISTS ai_call_log (
                id              BIGINT AUTO_INCREMENT PRIMARY KEY,
                user_id         BIGINT NULL,
                provider        VARCHAR(50) NOT NULL,
                model_id        VARCHAR(200) NULL,
                prompt_key      VARCHAR(100) NULL,
                tokens_in       INT DEFAULT 0,
                tokens_out      INT DEFAULT 0,
                success         TINYINT(1) DEFAULT 1,
                latency_ms      INT DEFAULT 0,
                error_message   TEXT NULL,
                called_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_time (user_id, called_at),
                INDEX idx_provider_time (provider, called_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ───── report_memory ─────
        $this->exec("
            CREATE TABLE IF NOT EXISTS report_memory (
                id              INT AUTO_INCREMENT PRIMARY KEY,
                report_key      VARCHAR(150) NOT NULL,
                summary         TEXT NOT NULL,
                provider        VARCHAR(50) NULL,
                model_id        VARCHAR(200) NULL,
                generated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_report_time (report_key, generated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ───── ai_chat_history (empty until Phase 8) ─────
        $this->exec("
            CREATE TABLE IF NOT EXISTS ai_chat_history (
                id              BIGINT AUTO_INCREMENT PRIMARY KEY,
                user_id         BIGINT NOT NULL,
                role            VARCHAR(20) NOT NULL,
                content         TEXT NOT NULL,
                metadata        JSON NULL,
                created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_time (user_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down()
    {
        $this->exec("DROP TABLE IF EXISTS ai_chat_history");
        $this->exec("DROP TABLE IF EXISTS report_memory");
        $this->exec("DROP TABLE IF EXISTS ai_call_log");
        $this->exec("DROP TABLE IF EXISTS ai_blacklist");
        $this->exec("DROP TABLE IF EXISTS ai_health_log");
        $this->exec("DROP TABLE IF EXISTS ai_models");
    }
}