<?php

declare(strict_types=1);

/**
 * Application entry point.
 *
 * Front controller: all requests are routed through this file.
 * It bootstraps the framework and runs the application.
 */

// Current directory is the framework root
define('ROOT_PATH', __DIR__ . '/..');

// Load Composer's autoloader (PSR-4)
require ROOT_PATH . '/vendor/autoload.php';

// Bootstrap helpers
require ROOT_PATH . '/src/Core/Helpers.php';

// Load configuration
Antimonial\Core\Config::load('app');
Antimonial\Core\Config::load('database');

// Enable debug mode from the environment (set APP_DEBUG=true to turn on)
Antimonial\Core\ErrorHandler::enableDebug((bool) env('APP_DEBUG', false));

// Run the application
$app = new Antimonial\Core\App();
$app->run();
