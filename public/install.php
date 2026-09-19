<?php
// public/install.php – One‑time installation script
// ==================================================

require_once __DIR__ . '/../src/config.php';

try {
    $pdo = new PDO("mysql:host=" . DB_HOST, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("CREATE DATABASE IF NOT EXISTS " . DB_NAME . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "✅ Database " . DB_NAME . " created or already exists.\n";

    $_SERVER['argv'] = ['migrate.php', '--force'];
    include __DIR__ . '/../utils/migrate.php';

    echo "\n🎉 Installation complete. You can now delete this file.\n";
} catch (PDOException $e) {
    echo "❌ Installation failed: " . $e->getMessage() . "\n";
}