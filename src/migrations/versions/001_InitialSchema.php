<?php
// src/migrations/versions/001_InitialSchema.php
// ===============================================

require_once __DIR__ . '/../Migration.php';

class Migration_001_InitialSchema extends Migration {

    public function up() {
        // Create orders table
        $this->exec("CREATE TABLE IF NOT EXISTS orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            table_number INT NOT NULL,
            items JSON NOT NULL,
            customer_note TEXT DEFAULT NULL,
            is_ready BOOLEAN DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_ready_created (is_ready, created_at)
        ) ENGINE=InnoDB");

        // Create menu_items table
        $this->exec("CREATE TABLE IF NOT EXISTS menu_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL UNIQUE,
            price DECIMAL(10,0) NOT NULL,
            category VARCHAR(50) NOT NULL,
            available BOOLEAN DEFAULT 1,
            image_path VARCHAR(255) DEFAULT NULL,
            sort_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_sort_name (sort_order, name),
            INDEX idx_available (available)
        ) ENGINE=InnoDB");

        // Create tables registry
        $this->exec("CREATE TABLE IF NOT EXISTS tables (
            id INT AUTO_INCREMENT PRIMARY KEY,
            table_number INT NOT NULL UNIQUE,
            qr_code_url VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_table_num (table_number)
        ) ENGINE=InnoDB");

        // Create settings table
        $this->exec("CREATE TABLE IF NOT EXISTS settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(50) NOT NULL UNIQUE,
            setting_value TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");

        // Insert default settings
        $this->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
            ('base_url', 'http://localhost/cafe-qr/public'),
            ('max_tables', '20'),
            ('admin_password', 'C@f3_M@n4g3r!'),
            ('site_name', 'QR Café')");

        // Insert sample menu items (skip if already present)
        $this->exec("INSERT IGNORE INTO menu_items (name, price, category, image_path, sort_order) VALUES
            ('Espresso', 35000, 'Hot Drinks', '/assets/images/espresso.jpg', 1),
            ('Latte', 45000, 'Hot Drinks', '/assets/images/latte.jpg', 2),
            ('Cappuccino', 48000, 'Hot Drinks', '/assets/images/cappuccino.jpg', 3),
            ('Cold Brew', 40000, 'Cold Drinks', '/assets/images/coldbrew.jpg', 0),
            ('Lemonade', 32000, 'Cold Drinks', '/assets/images/lemonade.jpg', 0),
            ('Croissant', 28000, 'Pastry', '/assets/images/croissant.jpg', 0),
            ('Muffin', 25000, 'Pastry', '/assets/images/muffin.jpg', 0),
            ('Chocolate Cake', 38000, 'Pastry', '/assets/images/choc_cake.jpg', 0)");
    }

    public function down() {
        // Rollback in reverse order (drop tables)
        $this->exec("DROP TABLE IF EXISTS settings");
        $this->exec("DROP TABLE IF EXISTS tables");
        $this->exec("DROP TABLE IF EXISTS menu_items");
        $this->exec("DROP TABLE IF EXISTS orders");
    }
}