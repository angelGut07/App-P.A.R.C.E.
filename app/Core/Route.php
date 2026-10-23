<?php

namespace App\Core;

/**
 * Route Class
 * 
 * Represents a single route with method, URI pattern, action, and middleware.
 */
class Route
{
    private string $method;
    private string $uri;
    private mixed $action;
    private array $middleware = [];
    private ?string $name = null;

    public function __construct(string $method, string $uri, callable|array $action)
    {
        $this->method = $method;
        $this->uri = $uri;
        $this->action = $action;
    }

    /**
     * Add middleware to route
     */
    public function middleware(string|array $middleware): self
    {
        $middleware = is_array($middleware) ? $middleware : [$middleware];
        $this->middleware = array_merge($this->middleware, $middleware);
        return $this;
    }

    /**
     * Set route name
     */
    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Check if route matches request
     */
    public function matches(string $method, string $uri): bool
    {
        if ($this->method !== 'ANY' && $this->method !== $method) {
            return false;
        }

        $pattern = $this->convertToRegex($this->uri);
        return preg_match($pattern, $uri) === 1;
    }

    /**
     * Extract parameters from URI
     */
    public function extractParams(string $uri): array
    {
        $pattern = $this->convertToRegex($this->uri);
        preg_match($pattern, $uri, $matches);
        
        // Remove full match
        array_shift($matches);
        
        return $matches;
    }

    /**
     * Convert URI pattern to regex
     */
    private function convertToRegex(string $uri): string
    {
        // Replace {param} with regex capture group
        $pattern = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '([^/]+)', $uri);
        
        // Escape forward slashes
        $pattern = str_replace('/', '\/', $pattern);
        
        return '/^' . $pattern . '$/';
    }

    /**
     * Get route action
     */
    public function getAction(): callable|array
    {
        return $this->action;
    }

    /**
     * Get route middleware
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    /**
     * Get route name
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * Get route URI
     */
    public function getUri(): string
    {
        return $this->uri;
    }

    /**
     * Get route method
     */
    public function getMethod(): string
    {
        return $this->method;
    }
}
