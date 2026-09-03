<?php

namespace Keel\App\Services;

use Keel\App\Models\Game;
use Keel\Core\Database;
use Keel\Core\Env;

/**
 * Pulls period scores for games that are about to start or already running.
 *
 * Games whose scores_source is 'manual' are never selected and never written:
 * Game::pendingFeedSync() filters them out and Game::applyFeedScores() carries
 * the same condition in its WHERE clause, so an in-flight job that was queued
 * before the override still cannot clobber it.
 */
class ScoreSyncService
{
    /** Feed responses are quarter-end cumulative scores unless the payload says otherwise. */
    private const PERIOD_KEYS = ['q1', 'q2', 'q3', 'q4'];

    /** @var null|callable(array): ?array */
    private static $fetcher = null;

    /** Lets tests and alternative feed vendors swap the transport out. */
    public static function setFetcher(?callable $fetcher): void
    {
        self::$fetcher = $fetcher;
    }

    /**
     * @return array{checked: int, updated: int, resolved: int}
     */
    public static function syncActiveGames(int $preKickoffMinutes = 15): array
    {
        $summary = ['locked' => self::lockBoardsAtKickoff(), 'checked' => 0, 'updated' => 0, 'resolved' => 0];

        foreach (Game::pendingFeedSync($preKickoffMinutes) as $game) {
            $summary['checked']++;

            $payload = self::fetch($game);

            if ($payload === null) {
                continue;
            }

            $normalized = self::normalize($payload);

            if ($normalized === null) {
                continue;
            }

            if (!Game::applyFeedScores((int) $game['id'], $normalized['scores'], $normalized['status'])) {
                // Manual override landed between the select and the write.
                continue;
            }

            $summary['updated']++;
            $summary['resolved'] += count(self::resolveBoardsForGame((int) $game['id']));
        }

        return $summary;
    }

    /**
     * Locks every open board whose game has kicked off. Digits are assigned here
     * and nowhere else, which is why they cannot exist before kickoff.
     *
     * @return int Boards locked on this pass.
     */
    public static function lockBoardsAtKickoff(): int
    {
        $statement = Database::connection()->query(
            "SELECT b.id
             FROM boards b
             INNER JOIN games g ON g.id = b.game_id
             WHERE b.status = 'open' AND b.locked_at IS NULL AND g.kickoff_at <= NOW()"
        );

        $locked = 0;

        foreach ($statement->fetchAll() as $board) {
            if (BoardLockService::lock((int) $board['id']) !== null) {
                $locked++;
            }
        }

        return $locked;
    }

    /**
     * Runs winner resolution for every locked board attached to a game. Called
     * after both feed syncs and manual overrides; idempotent either way.
     *
     * @return array<int, array<string, array{status: string}>>
     */
    public static function resolveBoardsForGame(int $gameId): array
    {
        $statement = Database::connection()->prepare(
            "SELECT id FROM boards WHERE game_id = ? AND status IN ('locked', 'scoring')"
        );
        $statement->execute([$gameId]);

        $results = [];

        foreach ($statement->fetchAll() as $board) {
            $results[(int) $board['id']] = WinnerService::resolveBoard((int) $board['id']);
        }

        return $results;
    }

    /**
     * Feed contract (JSON):
     *   {
     *     "status": "scheduled|in_progress|final",
     *     "periods": { "q1": {"home": 7, "away": 3}, "q2": {...}, ... },
     *     "final":   {"home": 24, "away": 17},
     *     "cumulative": true
     *   }
     * `periods` are the running totals at the end of each quarter. Set
     * "cumulative": false when the vendor reports points scored per quarter.
     *
     * @return array{status: string, scores: array<string, int|null>}|null
     */
    public static function normalize(array $payload): ?array
    {
        $status = (string) ($payload['status'] ?? '');

        if (!in_array($status, Game::STATUSES, true)) {
            return null;
        }

        $cumulative = (bool) ($payload['cumulative'] ?? true);
        $periods = is_array($payload['periods'] ?? null) ? $payload['periods'] : [];

        $scores = [];
        $runningHome = 0;
        $runningAway = 0;

        foreach (self::PERIOD_KEYS as $period) {
            $periodScores = is_array($periods[$period] ?? null) ? $periods[$period] : null;

            if ($periodScores === null
                || !isset($periodScores['home'], $periodScores['away'])
                || !is_numeric($periodScores['home'])
                || !is_numeric($periodScores['away'])) {
                $scores[$period . '_home_score'] = null;
                $scores[$period . '_away_score'] = null;
                continue;
            }

            $home = (int) $periodScores['home'];
            $away = (int) $periodScores['away'];

            if ($cumulative) {
                $runningHome = $home;
                $runningAway = $away;
            } else {
                $runningHome += $home;
                $runningAway += $away;
            }

            $scores[$period . '_home_score'] = $runningHome;
            $scores[$period . '_away_score'] = $runningAway;
        }

        $final = is_array($payload['final'] ?? null) ? $payload['final'] : null;

        if ($status === 'final' && $final !== null && isset($final['home'], $final['away'])
            && is_numeric($final['home']) && is_numeric($final['away'])) {
            $scores['final_home_score'] = (int) $final['home'];
            $scores['final_away_score'] = (int) $final['away'];
        }

        return ['status' => $status, 'scores' => $scores];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function fetch(array $game): ?array
    {
        if (self::$fetcher !== null) {
            return (self::$fetcher)($game);
        }

        $feedUrl = trim((string) Env::get('SCORES_FEED_URL', ''));
        $externalId = trim((string) ($game['external_id'] ?? ''));

        if ($feedUrl === '' || $externalId === '') {
            return null;
        }

        $url = $feedUrl
            . (str_contains($feedUrl, '?') ? '&' : '?')
            . http_build_query(['league' => $game['league'], 'game' => $externalId]);

        $headers = ['Accept: application/json'];
        $apiKey = trim((string) Env::get('SCORES_FEED_KEY', ''));

        if ($apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'timeout' => 8,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            error_log('[DealerDraw] Score feed request failed for game ' . $game['id']);

            return null;
        }

        $decoded = json_decode($response, true);

        return is_array($decoded) ? $decoded : null;
    }
}
