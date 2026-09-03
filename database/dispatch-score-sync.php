<?php

declare(strict_types=1);

use Keel\App\Jobs\SyncScoresJob;
use Keel\Core\Env;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

Env::load(dirname(__DIR__));

// Cron entry point. Safe to run every minute: while a poll chain is already
// pending this queues nothing, and the chain stops itself when no game is live.
//   * * * * * php /path/to/database/dispatch-score-sync.php
try {
    $queued = SyncScoresJob::ensureScheduled();

    fwrite(STDOUT, $queued ? "Score sync queued.\n" : "Score sync already pending.\n");
} catch (\Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
