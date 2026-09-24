<?php
// src/migrations/versions/009_CreateMenuItemsArchive.php
// ======================================================
// Archive table for soft-deleted menu items.
//
// When an admin deletes an item, its row is copied here before
// being removed from menu_items. Reports UNION both tables so
// historical orders can always resolve the item name.
//
// Keep the same primary key (id) so a future "restore" is a
// single INSERT ... SELECT back into menu_items.

require_once __DIR__ . '/../Migration.php';

class Migration_009_CreateMenuItemsArchive extends Migration
{
    public function up()
    {
        $this->exec("
            CREATE TABLE IF NOT EXISTS menu_items_deleted (
                id                  INT PRIMARY KEY,
                name                VARCHAR(100) NOT NULL,
                price               DECIMAL(10,0) NOT NULL,
                category            VARCHAR(50) NOT NULL,
                description         TEXT NULL,
                image_path          VARCHAR(255) NULL,
                sort_order          INT DEFAULT 0,
                available           TINYINT(1) DEFAULT 0,
                original_created_at TIMESTAMP NULL,
                deleted_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_deleted_at (deleted_at),
                INDEX idx_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down()
    {
        $this->exec("DROP TABLE IF EXISTS menu_items_deleted");
    }
}
