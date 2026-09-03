<?php

namespace Keel\App\Jobs;

use Keel\App\Models\Game;
use Keel\App\Services\ScoreSyncService;
use Keel\Core\Database;
use Keel\Core\Queue;

/**
 * Polls the score feed on a 60 second cadence while any game window is open,
 * then stops re-queueing itself until something is scheduled again.
 *
 * Kick the chain off from cron (every few minutes is plenty - ensureScheduled()
 * is a no-op while a run is already pending):
 *   php database/dispatch-score-sync.php
 */
class SyncScoresJob implements Job
{
    public const CADENCE_SECONDS = 60;

    /** Minutes before kickoff to start polling; also the board auto-lock window. */
    public const PRE_KICKOFF_MINUTES = 15;

    public function handle(array $data): void
    {
        ScoreSyncService::syncActiveGames(self::PRE_KICKOFF_MINUTES);

        if (Game::hasActiveFeedGames(self::PRE_KICKOFF_MINUTES)) {
            Queue::push(self::class, [], 'default', self::CADENCE_SECONDS);
        }
    }

    /**
     * Queues a run unless one is already queued or running, so cron and an
     * in-flight chain cannot stack up two pollers against the same feed.
     */
    public static function ensureScheduled(int $delaySeconds = 0): bool
    {
        $statement = Database::connection()->prepare(
            'SELECT 1 FROM jobs WHERE job_class = ? LIMIT 1'
        );
        $statement->execute([self::class]);

        if ($statement->fetchColumn()) {
            return false;
        }

        Queue::push(self::class, [], 'default', $delaySeconds);

        return true;
    }
}
