<?php

declare(strict_types=1);

use Keel\App\Console\Commands\SyncGamesCommand;
use Keel\Core\Env;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

Env::load(dirname(__DIR__));

// Schedule import. Run once per week per league, ahead of the games:
//   php scripts/sync-games.php --league=nfl --week=1
try {
    exit((new SyncGamesCommand())->handle($argv));
} catch (\Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
