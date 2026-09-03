<?php

declare(strict_types=1);

use Keel\App\Console\Commands\BoardCommand;
use Keel\Core\Env;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

Env::load(dirname(__DIR__));

// Drives a board by hand so the game lifecycle can be exercised out of season.
//   php scripts/board.php list
//   php scripts/board.php score --board=1 --period=q1 --home=7 --away=3
try {
    exit((new BoardCommand())->handle($argv));
} catch (\Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
