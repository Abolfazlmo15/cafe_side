<?php
// src/migrations/versions/005_AddDataVersionSetting.php
// =====================================================
// Adds the data_version setting used by the JSON API's
// version-cursor polling system.

require_once __DIR__ . '/../Migration.php';

class Migration_005_AddDataVersionSetting extends Migration {

    public function up() {
        // Insert only if it doesn't already exist
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = 'data_version'");
        $stmt->execute();
        if ($stmt->fetchColumn() == 0) {
            $this->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('data_version', '1')");
        }
    }

    public function down() {
        $this->exec("DELETE FROM settings WHERE setting_key = 'data_version'");
    }
}