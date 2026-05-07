<?php
/**
 * Shared-hosting bootstrap shim.
 *
 * On DirectAdmin (and most cPanel-style shared hosts), the document root is
 * `public_html/`, but Laravel expects the document root to be `public/`
 * inside the repo. This file lives at the repo root (which equals
 * `public_html/` on production) and forwards every request to Laravel's
 * front controller in `public/index.php`.
 *
 * Local development with `php artisan serve` does NOT execute this file —
 * artisan binds to `public/index.php` directly.
 */

require __DIR__ . '/public/index.php';
