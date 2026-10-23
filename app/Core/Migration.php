<?php

namespace App\Core;

/**
 * Migration Base Class
 * 
 * Abstract base class for database migrations.
 * All migrations must extend this class and implement up() and down() methods.
 */
abstract class Migration
{
    protected Database $db;

    public function __construct()
    {
        $this->db = new Database();
    }

    /**
     * Run the migration (apply changes)
     */
    abstract public function up(): void;

    /**
     * Reverse the migration (rollback changes)
     */
    abstract public function down(): void;

    /**
     * Get migration name
     */
    public function getName(): string
    {
        return static::class;
    }

    /**
     * Execute raw SQL
     */
    protected function execute(string $sql): void
    {
        Database::query($sql);
    }

    /**
     * Execute multiple SQL statements
     */
    protected function executeMultiple(string $sql): void
    {
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            fn($stmt) => !empty($stmt)
        );

        foreach ($statements as $statement) {
            if (!empty($statement)) {
                $this->execute($statement . ';');
            }
        }
    }

    /**
     * Check if table exists
     */
    protected function tableExists(string $table): bool
    {
        try {
            $result = Database::fetchOne(
                "SHOW TABLES LIKE ?",
                [$table]
            );
            return $result !== null;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Drop table if exists
     */
    protected function dropTable(string $table): void
    {
        $this->execute("DROP TABLE IF EXISTS `{$table}`");
    }
}
