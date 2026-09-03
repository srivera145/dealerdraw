<?php

namespace Keel\Core;

class ErrorHandler
{
    public static function render(int $status, ?\Throwable $e = null): never
    {
        if ($e !== null) {
            error_log('[Keel] Uncaught exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        }

        $status = $status === 404 ? 404 : 500;
        http_response_code($status);

        $debug = (bool) Env::get('APP_DEBUG', false);
        $exception = $debug ? $e : null;
        $title = $status === 404 ? 'Page Not Found' : 'Server Error';
        $template = $status === 404 ? 'errors.404' : 'errors.500';

        // Buffered and handed to Response so error pages honour capture mode the
        // same way every other response does.
        $bufferLevel = ob_get_level();

        try {
            ob_start();
            View::render($template, [
                'title' => $title,
                'exception' => $exception,
            ]);
            $body = (string) ob_get_clean();
        } catch (\Throwable $viewException) {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }

            $body = $status === 404 ? 'Page not found' : 'Server error';
        }

        Response::raw($body, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}