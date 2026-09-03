<?php

declare(strict_types=1);

/**
 * Router script for PHP's built-in server, for local use only.
 *
 *   php -S 127.0.0.1:8000 -t public_html scripts/dev-server.php
 *
 * public_html/index.php handles every request it is given, so with it used
 * directly as the router the built-in server never serves a static file and
 * /assets/... 404s. Returning false here hands existing files back to the
 * server, which is what nginx and Apache do in production.
 */

$path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
$publicRoot = dirname(__DIR__) . '/public_html';
$candidate = realpath($publicRoot . $path);

if ($path !== '/'
    && $candidate !== false
    && is_file($candidate)
    && str_starts_with($candidate, (string) realpath($publicRoot))) {
    return false;
}

require $publicRoot . '/index.php';
