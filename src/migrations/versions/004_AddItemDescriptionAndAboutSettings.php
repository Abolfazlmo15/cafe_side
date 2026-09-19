<?php
// src/migrations/versions/004_AddItemDescriptionAndAboutSettings.php
// ===================================================================

require_once __DIR__ . '/../Migration.php';

class Migration_004_AddItemDescriptionAndAboutSettings extends Migration {

    public function up() {
        // Add description column to menu_items
        if (!$this->columnExists('menu_items', 'description')) {
            $this->exec("ALTER TABLE menu_items ADD COLUMN description TEXT DEFAULT 'Served with love'");
        }

        // Insert default about content into settings if not exists
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = 'about_content'");
        $stmt->execute();
        if ($stmt->fetchColumn() == 0) {
            $this->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('about_content', '<p><strong>Welcome to BrewVerse</strong> — where every cup tells a story.</p><p>We are a cozy café dedicated to serving the finest specialty coffee, crafted with passion and care. Our beans are ethically sourced from small‑batch farms, and we roast them in‑house to bring out the unique flavours of each origin.</p><h3>☕ Our Offerings</h3><ul><li>Hand‑crafted espresso drinks</li><li>Freshly brewed pour‑overs</li><li>Artisan pastries and snacks</li><li>Plant‑based milk alternatives</li></ul><h3>📍 Visit Us</h3><p><i class=\"fas fa-map-pin\"></i> 123 Coffee Lane, Brewtown</p><p><i class=\"fas fa-clock\"></i> Mon – Sun: 7:00 AM – 10:00 PM</p><p><i class=\"fas fa-phone\"></i> +1 (555) 123‑4567</p><p><i class=\"fas fa-envelope\"></i> hello@brewverse.cafe</p>')");
        }
    }

    public function down() {
        if ($this->columnExists('menu_items', 'description')) {
            $this->exec("ALTER TABLE menu_items DROP COLUMN description");
        }
        $this->exec("DELETE FROM settings WHERE setting_key = 'about_content'");
    }
}