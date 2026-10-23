<?php
/**
 * Router script for PHP built-in server
 * 
 * This ensures all requests go through index.php
 */

// If the requested file exists and is not a directory, serve it directly
if (php_sapi_name() === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $file = __DIR__ . $path;
    
    // Serve static files directly
    if (is_file($file)) {
        return false;
    }
}

// Otherwise, route through index.php
require_once __DIR__ . '/index.php';
