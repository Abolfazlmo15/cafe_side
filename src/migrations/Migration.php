<?php
// src/migrations/Migration.php – Abstract base class for migrations
// =================================================================

abstract class Migration {
    /** @var PDO */
    protected $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Apply the migration (up)
     */
    abstract public function up();

    /**
     * Rollback the migration (down)
     */
    abstract public function down();

    /**
     * Helper to run raw SQL (with optional parameters)
     */
    protected function exec($sql, $params = []) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Helper to check if a table exists
     */
    protected function tableExists($table) {
        $stmt = $this->pdo->query("SHOW TABLES LIKE '$table'");
        return $stmt->rowCount() > 0;
    }

    /**
     * Helper to check if a column exists in a table
     */
    protected function columnExists($table, $column) {
        $stmt = $this->pdo->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        return $stmt->rowCount() > 0;
    }
}