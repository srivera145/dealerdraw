<?php

namespace Keel\App\Services\Providers;

use DateTimeImmutable;

/**
 * A source of football schedules and live scores.
 *
 * Implementations normalise their vendor's payload into the shape described
 * below, so swapping ESPN for a paid feed is a one-line change in
 * ScoreSyncService::provider() and touches nothing else.
 *
 * A normalised game:
 *
 *   [
 *     'external_id'    => string,   // stable vendor id, matched to games.external_id
 *     'home_team'      => string,
 *     'away_team'      => string,
 *     'kickoff_at'     => string,   // 'Y-m-d H:i:s' in the app's timezone
 *     'status'         => string,   // scheduled | in_progress | final
 *     'current_period' => int,      // 0 before kickoff, 1-4 in regulation, 5+ in overtime
 *     'completed'      => bool,     // the vendor says the game is over
 *     'period_points'  => array,    // ['q1' => ['home' => 7, 'away' => 0], ...]
 *     'total'          => array|null, // ['home' => 24, 'away' => 17], overtime included
 *   ]
 *
 * IMPORTANT: 'period_points' are points scored WITHIN each period, not running
 * totals. ScoreSyncService is what converts them to the cumulative end-of-period
 * scores the squares board pays out on. A provider must never pre-accumulate.
 *
 * A period key is present only when the vendor gave a usable number for it; a
 * missing key means "unknown", never "zero".
 */
interface ScoreProvider
{
    /** Short identifier used in log lines. */
    public function name(): string;

    /**
     * Every tracked game the vendor lists for one league on one calendar date.
     * This is the batching primitive: a 40-game Saturday is one call, not 40.
     *
     * @return array<string, array> Normalised games keyed by external_id.
     *
     * @throws ScoreFeedException When the request or the payload cannot be trusted.
     */
    public function scoreboardForDate(string $league, DateTimeImmutable $date): array;

    /**
     * Schedule for one week, for the import command.
     *
     * @return array<int, array> Normalised games.
     *
     * @throws ScoreFeedException
     */
    public function scheduleForWeek(string $league, int $season, int $week, int $seasonType = 2): array;
}
