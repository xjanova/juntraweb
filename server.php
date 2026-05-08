<?php

/**
 * Laravel - A PHP Framework For Web Artisans
 *
 * Router script for the PHP built-in dev server. Returns FALSE for any
 * physical file under /public so static assets (build/, images/, etc.) are
 * served by PHP directly instead of going through the Laravel router.
 *
 *   php -S 127.0.0.1:8000 server.php
 */
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// This file allows us to emulate Apache's "mod_rewrite" functionality from the
// built-in PHP web server. This provides a convenient way to test a Laravel
// application without having installed a "real" web server software here.
if ($uri !== '/' && file_exists(__DIR__.'/public'.$uri)) {
    return false;
}

require_once __DIR__.'/public/index.php';
