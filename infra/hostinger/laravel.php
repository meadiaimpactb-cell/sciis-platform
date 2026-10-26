<?php

/*
 * SCIIS API front controller for shared hosting.
 * The Laravel app lives outside the web root (../sciis-api); .htaccess routes /api/* and /sanctum/* here,
 * everything else is the prerendered Nuxt site in this folder.
 */

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$base = __DIR__.'/../sciis-api';

if (file_exists($maintenance = $base.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $base.'/vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require_once $base.'/bootstrap/app.php';
$app->usePublicPath(__DIR__);

$app->handleRequest(Request::capture());
