<?php

namespace App\Core;

/**
 * Seeder Base Class
 * 
 * Abstract base class for database seeders.
 * All seeders must extend this class and implement run() method.
 * Provides helper methods for common seeding operations.
 */
abstract class Seeder
{
    protected Database $db;

    public function __construct()
    {
        $this->db = new Database();
    }

    /**
     * Run the seeder
     */
    abstract public function run(): void;

    /**
     * Get seeder name
     */
    public function getName(): string
    {
        return static::class;
    }

    /**
     * Execute raw SQL
     */
    protected function execute(string $sql, array $params = []): void
    {
        Database::query($sql, $params);
    }

    /**
     * Insert record and return last insert ID
     */
    protected function insert(string $table, array $data): int
    {
        return Database::insert($table, $data);
    }

    /**
     * Check if record exists
     */
    protected function exists(string $table, string $where, array $params = []): bool
    {
        $result = Database::fetchOne(
            "SELECT 1 FROM {$table} WHERE {$where} LIMIT 1",
            $params
        );
        return $result !== null;
    }

    /**
     * Get record by condition
     */
    protected function findOne(string $table, string $where, array $params = []): ?array
    {
        return Database::fetchOne(
            "SELECT * FROM {$table} WHERE {$where} LIMIT 1",
            $params
        );
    }

    /**
     * Get all records by condition
     */
    protected function findAll(string $table, string $where = '1=1', array $params = []): array
    {
        return Database::fetchAll(
            "SELECT * FROM {$table} WHERE {$where}",
            $params
        );
    }

    /**
     * Update record
     */
    protected function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        return Database::update($table, $data, $where, $whereParams);
    }

    /**
     * Delete record
     */
    protected function delete(string $table, string $where, array $whereParams = []): int
    {
        return Database::delete($table, $where, $whereParams);
    }

    /**
     * Begin transaction
     */
    protected function beginTransaction(): bool
    {
        return Database::beginTransaction();
    }

    /**
     * Commit transaction
     */
    protected function commit(): bool
    {
        return Database::commit();
    }

    /**
     * Rollback transaction
     */
    protected function rollback(): bool
    {
        return Database::rollback();
    }

    /**
     * Log seeder message
     */
    protected function log(string $message): void
    {
        echo "[" . date('Y-m-d H:i:s') . "] " . $message . "\n";
    }
}
