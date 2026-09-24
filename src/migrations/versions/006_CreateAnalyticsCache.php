<?php
// src/migrations/versions/006_CreateAnalyticsCache.php
// =====================================================
// Creates the analytics_cache table.
//
// Holds precomputed report payloads and AI-generated summaries.
// Every report has a key (e.g. "top_items") plus an optional date
// range. The composite unique key ensures we only store one row
// per report+range.
//
// Nothing writes to this table in Phase 1 — it just needs to exist
// so Phases 3 and 5 have their storage ready.

require_once __DIR__ . '/../Migration.php';

class Migration_006_CreateAnalyticsCache extends Migration
{
    public function up()
    {
        $this->exec("
            CREATE TABLE IF NOT EXISTS analytics_cache (
                id                    INT AUTO_INCREMENT PRIMARY KEY,
                report_key            VARCHAR(150) NOT NULL,
                date_start            DATE NULL,
                date_end              DATE NULL,
                data                  JSON NULL,
                ai_summary            TEXT NULL,
                ai_summary_provider   VARCHAR(50) NULL,
                generated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                expires_at            DATETIME NULL,
                UNIQUE KEY uniq_report_range (report_key, date_start, date_end),
                INDEX idx_report_key (report_key),
                INDEX idx_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down()
    {
        $this->exec("DROP TABLE IF EXISTS analytics_cache");
    }
}