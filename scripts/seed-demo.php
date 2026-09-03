<?php

declare(strict_types=1);

use Keel\App\Console\Commands\SeedDemoCommand;
use Keel\Core\Env;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

Env::load(dirname(__DIR__));

// Builds a complete demo tenant for local development.
//   php scripts/seed-demo.php
//   php scripts/seed-demo.php --fresh
try {
    exit((new SeedDemoCommand())->handle($argv));
} catch (\Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
