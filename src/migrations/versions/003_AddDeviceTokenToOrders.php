<?php
// src/migrations/versions/003_AddDeviceTokenToOrders.php
// =======================================================

require_once __DIR__ . '/../Migration.php';

class Migration_003_AddDeviceTokenToOrders extends Migration {

    public function up() {
        // Add device_token column to orders if it doesn't exist
        if (!$this->columnExists('orders', 'device_token')) {
            $this->exec("ALTER TABLE orders ADD COLUMN device_token VARCHAR(255) NOT NULL DEFAULT ''");
        }
        // Add index on device_token (if not already present)
        // Check if index exists – we'll try to create it; if it already exists, MySQL will ignore it with IF NOT EXISTS
        $this->exec("CREATE INDEX idx_device_token ON orders(device_token)");
    }

    public function down() {
        // Drop the index first, then the column
        // MySQL doesn't have "DROP INDEX IF EXISTS" in older versions; we'll check existence via a query
        try {
            // Check if index exists
            $stmt = $this->pdo->query("SHOW INDEX FROM orders WHERE Key_name = 'idx_device_token'");
            if ($stmt->rowCount() > 0) {
                $this->exec("ALTER TABLE orders DROP INDEX idx_device_token");
            }
        } catch (PDOException $e) {
            // Index might not exist; ignore
        }
        if ($this->columnExists('orders', 'device_token')) {
            $this->exec("ALTER TABLE orders DROP COLUMN device_token");
        }
    }
}