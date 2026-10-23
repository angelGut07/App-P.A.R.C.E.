<?php

namespace App\Core;

/**
 * Configuration Validator
 * 
 * Validates environment configuration and database settings.
 * Provides clear error messages for missing or invalid configuration.
 */
class ConfigValidator
{
    private array $errors = [];
    private array $warnings = [];

    /**
     * Validate all configuration
     */
    public function validate(array $config): bool
    {
        $this->errors = [];
        $this->warnings = [];

        $this->validateAppConfig($config['app'] ?? []);
        $this->validateDatabaseConfig($config['database'] ?? []);
        $this->validateSessionConfig($config['session'] ?? []);

        return empty($this->errors);
    }

    /**
     * Validate application configuration
     */
    private function validateAppConfig(array $config): void
    {
        $required = ['name', 'env', 'url'];
        
        foreach ($required as $key) {
            if (empty($config[$key])) {
                $this->errors[] = "APP configuration missing required key: {$key}";
            }
        }

        // Validate environment
        $validEnvs = ['local', 'development', 'staging', 'production'];
        if (!empty($config['env']) && !in_array($config['env'], $validEnvs)) {
            $this->warnings[] = "APP_ENV '{$config['env']}' is not standard. Expected: " . implode(', ', $validEnvs);
        }

        // Validate URL format
        if (!empty($config['url']) && !filter_var($config['url'], FILTER_VALIDATE_URL)) {
            $this->errors[] = "APP_URL is not a valid URL: {$config['url']}";
        }

        // Warn if debug is enabled in production
        if (($config['env'] ?? '') === 'production' && ($config['debug'] ?? false)) {
            $this->warnings[] = "APP_DEBUG is enabled in production environment. This is a security risk.";
        }
    }

    /**
     * Validate database configuration
     */
    private function validateDatabaseConfig(array $config): void
    {
        $required = ['driver', 'host', 'database', 'username'];
        
        foreach ($required as $key) {
            if (!isset($config[$key]) || $config[$key] === '') {
                $this->errors[] = "Database configuration missing required key: {$key}";
            }
        }

        // Validate driver
        $supportedDrivers = ['mysql', 'pgsql', 'sqlite'];
        if (!empty($config['driver']) && !in_array($config['driver'], $supportedDrivers)) {
            $this->errors[] = "Unsupported database driver: {$config['driver']}. Supported: " . implode(', ', $supportedDrivers);
        }

        // Validate port
        if (isset($config['port'])) {
            $port = (int) $config['port'];
            if ($port < 1 || $port > 65535) {
                $this->errors[] = "Invalid database port: {$config['port']}. Must be between 1 and 65535.";
            }
        }

        // Warn about empty password in production
        if (($config['password'] ?? '') === '' && !empty($config['username'])) {
            $this->warnings[] = "Database password is empty. This may be a security risk.";
        }
    }

    /**
     * Validate session configuration
     */
    private function validateSessionConfig(array $config): void
    {
        // Validate lifetime
        if (isset($config['lifetime'])) {
            $lifetime = (int) $config['lifetime'];
            if ($lifetime < 1) {
                $this->errors[] = "Invalid session lifetime: {$config['lifetime']}. Must be greater than 0.";
            }
        }

        // Validate driver
        $validDrivers = ['file', 'database'];
        if (!empty($config['driver']) && !in_array($config['driver'], $validDrivers)) {
            $this->errors[] = "Invalid session driver: {$config['driver']}. Supported: " . implode(', ', $validDrivers);
        }
    }

    /**
     * Get validation errors
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get validation warnings
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Check if .env file exists
     */
    public static function envFileExists(string $path): bool
    {
        return file_exists($path);
    }

    /**
     * Get formatted error message
     */
    public function getErrorMessage(): string
    {
        $message = "Configuration validation failed:\n\n";
        
        if (!empty($this->errors)) {
            $message .= "ERRORS:\n";
            foreach ($this->errors as $error) {
                $message .= "  - {$error}\n";
            }
        }
        
        if (!empty($this->warnings)) {
            $message .= "\nWARNINGS:\n";
            foreach ($this->warnings as $warning) {
                $message .= "  - {$warning}\n";
            }
        }
        
        return $message;
    }
}
