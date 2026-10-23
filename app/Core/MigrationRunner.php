<?php

namespace App\Core;

/**
 * Migration Runner
 * 
 * Manages database migrations: running, rolling back, and tracking status.
 */
class MigrationRunner
{
    private string $migrationsPath;
    private string $migrationsTable = 'migrations';
    private array $output = [];

    public function __construct(string $migrationsPath = null)
    {
        $this->migrationsPath = $migrationsPath ?? __DIR__ . '/../../database/migrations';
    }

    /**
     * Run all pending migrations
     */
    public function migrate(): array
    {
        $this->output = [];
        $this->ensureMigrationsTableExists();

        $pending = $this->getPendingMigrations();

        if (empty($pending)) {
            $this->log('No pending migrations.');
            return $this->output;
        }

        $this->log("Found " . count($pending) . " pending migration(s).");

        foreach ($pending as $migrationFile) {
            $this->runMigration($migrationFile);
        }

        $this->log("Migration completed successfully.");
        return $this->output;
    }

    /**
     * Rollback the last batch of migrations
     */
    public function rollback(int $steps = 1): array
    {
        $this->output = [];
        $this->ensureMigrationsTableExists();

        $migrations = $this->getLastBatch($steps);

        if (empty($migrations)) {
            $this->log('No migrations to rollback.');
            return $this->output;
        }

        $this->log("Rolling back " . count($migrations) . " migration(s).");

        foreach ($migrations as $migration) {
            $this->rollbackMigration($migration);
        }

        $this->log("Rollback completed successfully.");
        return $this->output;
    }

    /**
     * Get migration status
     */
    public function status(): array
    {
        $this->output = [];
        $this->ensureMigrationsTableExists();

        $ran = $this->getRanMigrations();
        $all = $this->getAllMigrationFiles();

        $status = [];

        foreach ($all as $file) {
            $name = $this->getMigrationName($file);
            $status[] = [
                'migration' => $name,
                'ran' => in_array($name, $ran),
                'batch' => $this->getMigrationBatch($name),
            ];
        }

        return $status;
    }

    /**
     * Reset all migrations (rollback all and re-run)
     */
    public function reset(): array
    {
        $this->output = [];
        
        // Rollback all
        while (!empty($this->getLastBatch(1))) {
            $this->rollback(1);
        }

        // Run all
        $this->migrate();

        return $this->output;
    }

    /**
     * Run a single migration
     */
    private function runMigration(string $file): void
    {
        $name = $this->getMigrationName($file);
        
        try {
            // Ensure connection exists
            $connection = Database::getConnection();
            
            $this->log("Migrating: {$name}");

            // Run migration (no transaction for DDL statements)
            // MySQL implicitly commits on DDL, so transactions don't help here
            $migration = $this->loadMigration($file);
            $migration->up();

            // Record migration in tracking table
            $this->recordMigration($name);

            $this->log("Migrated: {$name}");

        } catch (\Throwable $e) {
            $this->log("Failed: {$name}");
            $this->log("Error: " . $e->getMessage());
            throw new DatabaseException("Migration failed: {$name} - " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Rollback a single migration
     */
    private function rollbackMigration(array $migration): void
    {
        $name = $migration['migration'];
        $file = $this->findMigrationFile($name);

        if (!$file) {
            $this->log("Migration file not found: {$name}");
            return;
        }

        try {
            // Ensure connection exists
            $connection = Database::getConnection();
            
            $this->log("Rolling back: {$name}");

            // Run rollback (no transaction for DDL statements)
            // MySQL implicitly commits on DDL, so transactions don't help here
            $migrationInstance = $this->loadMigration($file);
            $migrationInstance->down();

            // Remove migration record
            $this->removeMigration($name);

            $this->log("Rolled back: {$name}");

        } catch (\Throwable $e) {
            $this->log("Rollback failed: {$name}");
            $this->log("Error: " . $e->getMessage());
            throw new DatabaseException("Rollback failed: {$name} - " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Load migration instance from file
     */
    private function loadMigration(string $file): Migration
    {
        require_once $file;

        $className = $this->getClassNameFromFile($file);

        if (!class_exists($className)) {
            throw new \RuntimeException("Migration class not found: {$className}");
        }

        return new $className();
    }

    /**
     * Get class name from migration file
     */
    private function getClassNameFromFile(string $file): string
    {
        $name = $this->getMigrationName($file);
        
        // Remove timestamp prefix (e.g., "2024_01_01_000000_")
        $parts = explode('_', $name);
        if (count($parts) >= 5) {
            array_shift($parts); // year
            array_shift($parts); // month
            array_shift($parts); // day
            array_shift($parts); // time
        }
        
        $className = implode('_', $parts);
        $className = str_replace(' ', '', ucwords(str_replace('_', ' ', $className)));
        
        return $className;
    }

    /**
     * Ensure migrations tracking table exists
     */
    private function ensureMigrationsTableExists(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS `{$this->migrationsTable}` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `migration` VARCHAR(255) NOT NULL,
            `batch` INT NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `unique_migration` (`migration`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        Database::query($sql);
    }

    /**
     * Get all migration files
     */
    private function getAllMigrationFiles(): array
    {
        if (!is_dir($this->migrationsPath)) {
            return [];
        }

        $files = glob($this->migrationsPath . '/*.php');
        sort($files);
        return $files;
    }

    /**
     * Get pending migrations
     */
    private function getPendingMigrations(): array
    {
        $all = $this->getAllMigrationFiles();
        $ran = $this->getRanMigrations();

        return array_filter($all, function ($file) use ($ran) {
            return !in_array($this->getMigrationName($file), $ran);
        });
    }

    /**
     * Get migrations that have been run
     */
    private function getRanMigrations(): array
    {
        try {
            $results = Database::fetchAll(
                "SELECT migration FROM `{$this->migrationsTable}` ORDER BY batch, id"
            );
            return array_column($results, 'migration');
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get last batch of migrations
     */
    private function getLastBatch(int $steps = 1): array
    {
        try {
            $lastBatch = Database::fetchOne(
                "SELECT MAX(batch) as batch FROM `{$this->migrationsTable}`"
            );

            if (!$lastBatch || $lastBatch['batch'] === null) {
                return [];
            }

            $batch = (int) $lastBatch['batch'];
            $startBatch = max(1, $batch - $steps + 1);

            $results = Database::fetchAll(
                "SELECT migration, batch FROM `{$this->migrationsTable}` 
                 WHERE batch >= ? AND batch <= ?
                 ORDER BY batch DESC, id DESC",
                [$startBatch, $batch]
            );

            return $results;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get migration batch number
     */
    private function getMigrationBatch(string $name): ?int
    {
        try {
            $result = Database::fetchOne(
                "SELECT batch FROM `{$this->migrationsTable}` WHERE migration = ?",
                [$name]
            );
            return $result ? (int) $result['batch'] : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Record migration as run
     */
    private function recordMigration(string $name): void
    {
        $batch = $this->getNextBatchNumber();
        
        Database::query(
            "INSERT INTO `{$this->migrationsTable}` (migration, batch) VALUES (?, ?)",
            [$name, $batch]
        );
    }

    /**
     * Remove migration record
     */
    private function removeMigration(string $name): void
    {
        Database::query(
            "DELETE FROM `{$this->migrationsTable}` WHERE migration = ?",
            [$name]
        );
    }

    /**
     * Get next batch number
     */
    private function getNextBatchNumber(): int
    {
        $result = Database::fetchOne(
            "SELECT MAX(batch) as batch FROM `{$this->migrationsTable}`"
        );

        return $result && $result['batch'] !== null ? (int) $result['batch'] + 1 : 1;
    }

    /**
     * Get migration name from file path
     */
    private function getMigrationName(string $file): string
    {
        return pathinfo($file, PATHINFO_FILENAME);
    }

    /**
     * Find migration file by name
     */
    private function findMigrationFile(string $name): ?string
    {
        $file = $this->migrationsPath . '/' . $name . '.php';
        return file_exists($file) ? $file : null;
    }

    /**
     * Log message
     */
    private function log(string $message): void
    {
        $this->output[] = $message;
    }

    /**
     * Get output messages
     */
    public function getOutput(): array
    {
        return $this->output;
    }
}
