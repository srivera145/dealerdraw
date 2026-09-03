<?php

namespace Keel\App\Services\Providers;

use DateTimeImmutable;
use DateTimeZone;
use Keel\App\Models\Game;
use Keel\Core\Env;

/**
 * ESPN's public scoreboard endpoints.
 *
 * One request covers a whole league-day, which is what keeps a 40-game college
 * Saturday to a single call. Everything vendor-specific - URL shapes, the
 * pre/in/post status vocabulary, linescore arrays - stops here.
 */
class EspnScoreProvider implements ScoreProvider
{
    private const BASE_URL = 'https://site.api.espn.com/apis/site/v2/sports/football';

    private const LEAGUE_PATHS = [
        'nfl' => 'nfl',
        'ncaaf' => 'college-football',
    ];

    /** FBS only. Without this college returns every division. */
    private const NCAAF_GROUP = '80';

    /** ESPN buckets a scoreboard day in US Eastern time. */
    public const FEED_TIMEZONE = 'America/New_York';

    private const MAX_ATTEMPTS = 3;
    private const RETRY_BACKOFF_MICROSECONDS = [200000, 600000];
    private const TIMEOUT_SECONDS = 8;

    /** @var callable(string): string */
    private $transport;

    /**
     * @param null|callable(string): string $transport Returns a response body or throws
     *                                                 ScoreFeedException. Injected by tests.
     */
    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport ?? [$this, 'request'];
    }

    public function name(): string
    {
        return 'espn';
    }

    public function scoreboardForDate(string $league, DateTimeImmutable $date): array
    {
        $query = [
            'dates' => $date->setTimezone(new DateTimeZone(self::FEED_TIMEZONE))->format('Ymd'),
        ];

        if ($league === 'ncaaf') {
            $query['groups'] = self::NCAAF_GROUP;
            // A full Saturday slate runs past the default page size.
            $query['limit'] = '400';
        }

        $games = [];

        foreach ($this->events($league, $query) as $event) {
            $game = $this->normalizeEvent($league, $event);

            if ($game !== null) {
                $games[$game['external_id']] = $game;
            }
        }

        return $games;
    }

    public function scheduleForWeek(string $league, int $season, int $week, int $seasonType = 2): array
    {
        $query = [
            'dates' => (string) $season,
            'seasontype' => (string) $seasonType,
            'week' => (string) $week,
        ];

        if ($league === 'ncaaf') {
            $query['groups'] = self::NCAAF_GROUP;
            $query['limit'] = '400';
        }

        $games = [];

        foreach ($this->events($league, $query) as $event) {
            $game = $this->normalizeEvent($league, $event);

            if ($game !== null) {
                $games[] = $game;
            }
        }

        return $games;
    }

    /**
     * @param array<string, string> $query
     * @return array<int, array>
     */
    private function events(string $league, array $query): array
    {
        if (!isset(self::LEAGUE_PATHS[$league])) {
            throw new ScoreFeedException("Unsupported league '{$league}'.");
        }

        $url = self::BASE_URL . '/' . self::LEAGUE_PATHS[$league] . '/scoreboard?' . http_build_query($query);
        $body = ($this->transport)($url);
        $payload = json_decode($body, true);

        if (!is_array($payload)) {
            throw new ScoreFeedException('Scoreboard response was not JSON.');
        }

        // A day with no games is a valid empty scoreboard; a missing events key
        // on a malformed body is not, so the two are told apart explicitly.
        if (!array_key_exists('events', $payload)) {
            throw new ScoreFeedException('Scoreboard response had no events key.');
        }

        return is_array($payload['events']) ? $payload['events'] : [];
    }

    /**
     * Returns null for any event we cannot read with confidence. A skipped event
     * leaves the stored game untouched rather than writing a guess.
     */
    private function normalizeEvent(string $league, mixed $event): ?array
    {
        if (!is_array($event)) {
            return null;
        }

        $externalId = trim((string) ($event['id'] ?? ''));
        $competition = is_array($event['competitions'][0] ?? null) ? $event['competitions'][0] : null;

        if ($externalId === '' || $competition === null) {
            return null;
        }

        $competitors = is_array($competition['competitors'] ?? null) ? $competition['competitors'] : [];
        $home = $this->competitor($competitors, 'home');
        $away = $this->competitor($competitors, 'away');

        if ($home === null || $away === null) {
            return null;
        }

        $kickoff = $this->kickoff((string) ($event['date'] ?? $competition['date'] ?? ''));

        if ($kickoff === null) {
            return null;
        }

        $status = is_array($event['status'] ?? null) ? $event['status'] : ($competition['status'] ?? []);
        $statusType = is_array($status['type'] ?? null) ? $status['type'] : [];
        $state = (string) ($statusType['state'] ?? '');
        $completed = (bool) ($statusType['completed'] ?? false);

        return [
            'external_id' => $externalId,
            'league' => $league,
            'home_team' => $this->teamName($home),
            'away_team' => $this->teamName($away),
            'kickoff_at' => $kickoff,
            'status' => $this->status($state, $completed),
            'current_period' => max(0, (int) ($status['period'] ?? 0)),
            'completed' => $completed,
            // Per-period points exactly as ESPN reports them. Accumulating is
            // the service's job, not the provider's.
            'period_points' => $this->periodPoints($home, $away),
            'total' => $this->total($home, $away),
        ];
    }

    /**
     * @param array<int, mixed> $competitors
     */
    private function competitor(array $competitors, string $side): ?array
    {
        foreach ($competitors as $competitor) {
            if (is_array($competitor) && ($competitor['homeAway'] ?? '') === $side) {
                return $competitor;
            }
        }

        return null;
    }

    private function teamName(array $competitor): string
    {
        $team = is_array($competitor['team'] ?? null) ? $competitor['team'] : [];

        foreach (['displayName', 'shortDisplayName', 'name', 'abbreviation'] as $key) {
            $value = trim((string) ($team[$key] ?? ''));

            if ($value !== '') {
                return substr($value, 0, 100);
            }
        }

        return 'Unknown';
    }

    private function kickoff(string $isoDate): ?string
    {
        if (trim($isoDate) === '') {
            return null;
        }

        try {
            $kickoff = new DateTimeImmutable($isoDate);
        } catch (\Exception) {
            return null;
        }

        // Stored in the app's timezone, which is what the DB session is pinned to.
        return $kickoff->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
    }

    private function status(string $state, bool $completed): string
    {
        if ($completed || $state === 'post') {
            return 'final';
        }

        return $state === 'in' ? 'in_progress' : 'scheduled';
    }

    /**
     * @return array<string, array{home: int, away: int}> Keys present only where
     *                                                    both sides gave a number.
     */
    private function periodPoints(array $home, array $away): array
    {
        $homeLines = $this->lineScores($home);
        $awayLines = $this->lineScores($away);
        $points = [];

        foreach (array_keys(Game::PERIOD_COLUMNS) as $period) {
            if ($period === 'final') {
                continue;
            }

            $index = (int) substr($period, 1) - 1;

            if (!isset($homeLines[$index], $awayLines[$index])) {
                continue;
            }

            $points[$period] = ['home' => $homeLines[$index], 'away' => $awayLines[$index]];
        }

        return $points;
    }

    /**
     * @return array<int, int>
     */
    private function lineScores(array $competitor): array
    {
        $lineScores = is_array($competitor['linescores'] ?? null) ? $competitor['linescores'] : [];
        $values = [];

        foreach (array_values($lineScores) as $index => $lineScore) {
            $value = is_array($lineScore) ? ($lineScore['value'] ?? null) : $lineScore;

            if (!is_numeric($value)) {
                continue;
            }

            $values[$index] = (int) $value;
        }

        return $values;
    }

    /**
     * @return array{home: int, away: int}|null
     */
    private function total(array $home, array $away): ?array
    {
        if (!is_numeric($home['score'] ?? null) || !is_numeric($away['score'] ?? null)) {
            return null;
        }

        return ['home' => (int) $home['score'], 'away' => (int) $away['score']];
    }

    /**
     * Bounded retry for transient transport failures. Anything still failing
     * after this is the service's problem to count and back off from.
     */
    private function request(string $url): string
    {
        // ESPN's edge 403s unrecognised and browser-impersonating user agents.
        // It accepts known HTTP-client tokens, so the string leads with one and
        // appends who we actually are. Verified against the live endpoint; if
        // they tighten the rule, override it without a deploy.
        // An env var that is present but blank must fall through to the default,
        // otherwise the header goes out empty and ESPN 403s every request.
        $userAgent = trim((string) Env::get('SCORES_FEED_USER_AGENT', ''));

        if ($userAgent === '') {
            $appName = str_replace(' ', '-', trim((string) Env::get('APP_NAME', 'DealerDraw')));
            $userAgent = 'curl/8.4.0 ' . ($appName !== '' ? $appName : 'DealerDraw') . '/1.0';
        }

        $headers = [
            'Accept: application/json',
            'User-Agent: ' . $userAgent,
        ];

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'timeout' => self::TIMEOUT_SECONDS,
                'ignore_errors' => true,
            ],
        ]);

        $lastError = 'unknown error';

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $body = @file_get_contents($url, false, $context);
            $statusCode = $this->statusCode($http_response_header ?? []);

            if ($body !== false && $statusCode >= 200 && $statusCode < 300) {
                return $body;
            }

            $lastError = $body === false
                ? 'request failed'
                : 'HTTP ' . $statusCode;

            if ($attempt < self::MAX_ATTEMPTS) {
                usleep(self::RETRY_BACKOFF_MICROSECONDS[$attempt - 1] ?? 600000);
            }
        }

        throw new ScoreFeedException(
            'ESPN scoreboard request failed after ' . self::MAX_ATTEMPTS . ' attempts: ' . $lastError
        );
    }

    /**
     * @param array<int, string> $responseHeaders
     */
    private function statusCode(array $responseHeaders): int
    {
        foreach ($responseHeaders as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return 0;
    }
}
