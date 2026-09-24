<?php
// src/migrations/versions/008_AddAISettings.php
// ============================================
// Adds six rows to the settings table that configure the AI
// subsystem. All reads go through the existing SQL pattern —
// no new code needed to fetch these.
//
//   ai_provider_priority              – comma-separated provider list
//   ai_calls_daily_limit              – soft cap on AI calls per day
//   ai_chat_enabled                   – toggle for Phase 8 chat
//   ai_last_refresh                   – timestamp of last model refresh
//   ai_blacklist_ttl_minutes          – how long a dead provider stays skipped
//   ai_health_check_interval_minutes  – cron frequency for health checks

require_once __DIR__ . '/../Migration.php';

class Migration_008_AddAISettings extends Migration
{
    public function up()
    {
        $rows = [
            'ai_provider_priority'             => 'openrouter',
            'ai_calls_daily_limit'             => '500',
            'ai_chat_enabled'                  => '0',
            'ai_last_refresh'                  => '',
            'ai_blacklist_ttl_minutes'         => '15',
            'ai_health_check_interval_minutes' => '30',
        ];

        foreach ($rows as $key => $value) {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = ?");
            $stmt->execute([$key]);
            if ((int) $stmt->fetchColumn() === 0) {
                $stmt = $this->pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)");
                $stmt->execute([$key, $value]);
            }
        }
    }

    public function down()
    {
        $keys = [
            'ai_provider_priority',
            'ai_calls_daily_limit',
            'ai_chat_enabled',
            'ai_last_refresh',
            'ai_blacklist_ttl_minutes',
            'ai_health_check_interval_minutes',
        ];
        foreach ($keys as $k) {
            $stmt = $this->pdo->prepare("DELETE FROM settings WHERE setting_key = ?");
            $stmt->execute([$k]);
        }
    }
}