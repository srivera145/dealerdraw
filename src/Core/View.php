<?php

namespace Keel\Core;

class View
{
    private static string $viewsPath = '';

    public static function setPath(string $path): void
    {
        self::$viewsPath = rtrim($path, '/');
    }

    /**
     * The web entry point calls setPath(), but CLI entry points - the queue
     * worker in particular - do not, and jobs render views (the winner email).
     * Defaulting here means a background job cannot fail on an unset path.
     */
    public static function path(): string
    {
        return self::$viewsPath !== '' ? self::$viewsPath : dirname(__DIR__, 2) . '/views';
    }

    public static function render(string $template, array $data = []): void
    {
        extract($data, EXTR_SKIP);
        $file = self::path() . '/' . str_replace('.', '/', $template) . '.php';

        if (!file_exists($file)) {
            throw new \RuntimeException("View not found: {$template}");
        }

        require $file;
    }
}
