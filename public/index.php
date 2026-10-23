<?php

/**
 * P.A.R.C.E Platform - Front Controller
 * 
 * All requests are routed through this file.
 * This is the single entry point for the application.
 */

// Define base path
define('BASE_PATH', dirname(__DIR__));

// Load Composer autoloader
require_once BASE_PATH . '/vendor/autoload.php';

// Create application instance
$app = new \App\Core\App();

// Load routes
require_once BASE_PATH . '/config/routes.php';

// Run application
$app->run();
