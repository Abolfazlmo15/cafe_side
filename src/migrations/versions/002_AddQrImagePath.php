<?php
// src/migrations/versions/002_AddQrImagePath.php
// ===============================================

require_once __DIR__ . '/../Migration.php';

class Migration_002_AddQrImagePath extends Migration {

    public function up() {
        // Add qr_image_path column to tables table if it doesn't exist
        if (!$this->columnExists('tables', 'qr_image_path')) {
            $this->exec("ALTER TABLE tables ADD COLUMN qr_image_path VARCHAR(255) DEFAULT NULL");
        }
    }

    public function down() {
        if ($this->columnExists('tables', 'qr_image_path')) {
            $this->exec("ALTER TABLE tables DROP COLUMN qr_image_path");
        }
    }
}