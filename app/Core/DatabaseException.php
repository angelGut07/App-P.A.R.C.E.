<?php

namespace App\Core;

use Exception;
use Throwable;

/**
 * Database Exception
 * 
 * Custom exception for database-related errors.
 * Provides additional context for debugging and logging.
 */
class DatabaseException extends Exception
{
    private ?string $query = null;
    private array $params = [];

    public function __construct(
        string $message = "",
        int $code = 0,
        ?Throwable $previous = null,
        ?string $query = null,
        array $params = []
    ) {
        parent::__construct($message, $code, $previous);
        $this->query = $query;
        $this->params = $params;
    }

    /**
     * Get the SQL query that caused the exception
     */
    public function getQuery(): ?string
    {
        return $this->query;
    }

    /**
     * Get the query parameters
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * Get formatted error details
     */
    public function getDetails(): array
    {
        return [
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'file' => $this->getFile(),
            'line' => $this->getLine(),
            'query' => $this->query,
            'params' => $this->params,
        ];
    }
}
