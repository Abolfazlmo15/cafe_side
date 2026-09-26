<?php
// src/migrations/versions/010_CreateProxyPool.php
// =====================================================
// Phase 7 — proxy pool for network-blocked AI providers.

require_once __DIR__ . '/../Migration.php';

class Migration_010_CreateProxyPool extends Migration
{
    public function up()
    {
        $this->exec("
            CREATE TABLE IF NOT EXISTS proxy_pool (
                id                     INT AUTO_INCREMENT PRIMARY KEY,
                host                   VARCHAR(100) NOT NULL,
                port                   INT NOT NULL,
                protocol               VARCHAR(10) NOT NULL DEFAULT 'http',
                last_tested            DATETIME NULL,
                last_success           DATETIME NULL,
                latency_ms             INT DEFAULT 0,
                consecutive_failures   INT DEFAULT 0,
                total_successes        INT DEFAULT 0,
                total_failures         INT DEFAULT 0,
                last_error             VARCHAR(255) NULL,
                active                 TINYINT(1) DEFAULT 0,
                seen_ip                VARCHAR(64) NULL,
                created_at             TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_proxy (host, port, protocol),
                INDEX idx_active_latency (active, latency_ms),
                INDEX idx_last_tested (last_tested)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Two settings that control the subsystem without code changes.
        $rows = [
            'ai_proxy_enabled'      => '0',   // off by default
            'ai_proxy_last_refresh' => '',
        ];
        foreach ($rows as $key => $value) {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = ?");
            $stmt->execute([$key]);
            if ((int) $stmt->fetchColumn() === 0) {
                $this->exec(
                    "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)",
                    [$key, $value]
                );
            }
        }
    }

    public function down()
    {
        $this->exec("DROP TABLE IF EXISTS proxy_pool");
        $this->exec("DELETE FROM settings WHERE setting_key IN ('ai_proxy_enabled', 'ai_proxy_last_refresh')");
    }
}