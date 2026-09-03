<?php

declare(strict_types=1);

namespace Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Keel\App\Services\Providers\EspnScoreProvider;
use Keel\App\Services\Providers\ScoreFeedException;
use PHPUnit\Framework\TestCase;

/**
 * Parsing of ESPN's scoreboard payload. Everything vendor-shaped is asserted
 * here so the service can stay in normalised terms.
 */
class EspnScoreProviderTest extends TestCase
{
    public function testScoreboardIsFetchedOncePerLeagueDayAndKeyedByExternalId(): void
    {
        $requested = [];
        $provider = new EspnScoreProvider(function (string $url) use (&$requested): string {
            $requested[] = $url;

            return $this->scoreboardFixture();
        });

        $games = $provider->scoreboardForDate('nfl', new DateTimeImmutable('2026-09-13 18:00:00', new DateTimeZone('UTC')));

        self::assertCount(1, $requested);
        self::assertStringContainsString('/football/nfl/scoreboard?', $requested[0]);
        // 18:00 UTC is still the 13th in Eastern, which is how ESPN files it.
        self::assertStringContainsString('dates=20260913', $requested[0]);

        // ESPN ids are numeric strings, which PHP normalises to int array keys.
        // Lookups by string still resolve, so the service is unaffected.
        self::assertSame(['401671001', '401671002'], array_map('strval', array_keys($games)));
        self::assertArrayHasKey('401671001', $games);
    }

    public function testCollegeRequestsAskForFbsWithARaisedPageSize(): void
    {
        $requested = '';
        $provider = new EspnScoreProvider(function (string $url) use (&$requested): string {
            $requested = $url;

            return '{"events": []}';
        });

        $provider->scoreboardForDate('ncaaf', new DateTimeImmutable('2026-09-12 16:00:00'));

        self::assertStringContainsString('/football/college-football/scoreboard?', $requested);
        self::assertStringContainsString('groups=80', $requested);
        self::assertStringContainsString('limit=400', $requested);
    }

    public function testLateKickoffIsFiledUnderTheEasternScoreboardDay(): void
    {
        $requested = '';
        $provider = new EspnScoreProvider(function (string $url) use (&$requested): string {
            $requested = $url;

            return '{"events": []}';
        });

        // 01:20 UTC Monday is 21:20 Sunday in Eastern - a Sunday night game.
        $provider->scoreboardForDate('nfl', new DateTimeImmutable('2026-09-14 01:20:00', new DateTimeZone('UTC')));

        self::assertStringContainsString('dates=20260913', $requested);
    }

    public function testInProgressGameReportsPerPeriodPointsNotRunningTotals(): void
    {
        $provider = new EspnScoreProvider(fn (): string => $this->scoreboardFixture());
        $games = $provider->scoreboardForDate('nfl', new DateTimeImmutable('2026-09-13 18:00:00'));

        $live = $games['401671001'];

        self::assertSame('in_progress', $live['status']);
        self::assertSame(3, $live['current_period']);
        self::assertFalse($live['completed']);
        self::assertSame('Kansas City Chiefs', $live['home_team']);
        self::assertSame('Buffalo Bills', $live['away_team']);

        // ESPN linescores are points within the quarter; they are passed through
        // untouched so the service alone owns accumulation.
        self::assertSame(['home' => 7, 'away' => 3], $live['period_points']['q1']);
        self::assertSame(['home' => 7, 'away' => 7], $live['period_points']['q2']);
        self::assertSame(['home' => 3, 'away' => 0], $live['period_points']['q3']);
        self::assertArrayNotHasKey('q4', $live['period_points']);
        self::assertSame(['home' => 17, 'away' => 10], $live['total']);
    }

    public function testCompletedGameIsMarkedFinal(): void
    {
        $provider = new EspnScoreProvider(fn (): string => $this->scoreboardFixture());
        $games = $provider->scoreboardForDate('nfl', new DateTimeImmutable('2026-09-13 18:00:00'));

        $done = $games['401671002'];

        self::assertSame('final', $done['status']);
        self::assertTrue($done['completed']);
        self::assertSame(['home' => 24, 'away' => 17], $done['total']);
    }

    public function testEventsMissingRequiredFieldsAreSkippedRatherThanGuessed(): void
    {
        $payload = json_encode(['events' => [
            ['id' => 'no-competition'],
            ['competitions' => [['competitors' => []]]],
            ['id' => 'no-home', 'date' => '2026-09-13T17:00Z', 'competitions' => [[
                'competitors' => [['homeAway' => 'away', 'team' => ['displayName' => 'A']]],
            ]]],
            ['id' => 'ok', 'date' => '2026-09-13T17:00Z', 'status' => ['period' => 1, 'type' => ['state' => 'in']], 'competitions' => [[
                'competitors' => [
                    ['homeAway' => 'home', 'team' => ['displayName' => 'H'], 'score' => '0'],
                    ['homeAway' => 'away', 'team' => ['displayName' => 'A'], 'score' => '0'],
                ],
            ]]],
        ]]);

        $provider = new EspnScoreProvider(fn (): string => (string) $payload);
        $games = $provider->scoreboardForDate('nfl', new DateTimeImmutable('2026-09-13 18:00:00'));

        self::assertSame(['ok'], array_keys($games));
    }

    public function testNonJsonAndMissingEventsKeyBothRaiseAFeedException(): void
    {
        $garbage = new EspnScoreProvider(fn (): string => '<html>502 Bad Gateway</html>');

        $this->expectException(ScoreFeedException::class);
        $garbage->scoreboardForDate('nfl', new DateTimeImmutable('2026-09-13 18:00:00'));
    }

    public function testPayloadWithoutEventsKeyIsRejected(): void
    {
        $provider = new EspnScoreProvider(fn (): string => '{"leagues": []}');

        $this->expectException(ScoreFeedException::class);
        $provider->scoreboardForDate('nfl', new DateTimeImmutable('2026-09-13 18:00:00'));
    }

    public function testEmptyScoreboardIsValidAndNotAFailure(): void
    {
        $provider = new EspnScoreProvider(fn (): string => '{"events": []}');

        self::assertSame([], $provider->scoreboardForDate('nfl', new DateTimeImmutable('2026-09-13 18:00:00')));
    }

    public function testUnsupportedLeagueIsRejectedBeforeAnyRequest(): void
    {
        $called = false;
        $provider = new EspnScoreProvider(function () use (&$called): string {
            $called = true;

            return '{"events": []}';
        });

        try {
            $provider->scoreboardForDate('nba', new DateTimeImmutable('2026-09-13 18:00:00'));
            self::fail('Expected a ScoreFeedException.');
        } catch (ScoreFeedException $exception) {
            self::assertStringContainsString("Unsupported league 'nba'", $exception->getMessage());
        }

        self::assertFalse($called);
    }

    public function testScheduleRequestCarriesSeasonWeekAndType(): void
    {
        $requested = '';
        $provider = new EspnScoreProvider(function (string $url) use (&$requested): string {
            $requested = $url;

            return $this->scoreboardFixture();
        });

        $games = $provider->scheduleForWeek('nfl', 2026, 3, 2);

        self::assertStringContainsString('dates=2026', $requested);
        self::assertStringContainsString('week=3', $requested);
        self::assertStringContainsString('seasontype=2', $requested);
        self::assertCount(2, $games);
        self::assertSame('401671001', $games[0]['external_id']);
    }

    private function scoreboardFixture(): string
    {
        return (string) json_encode(['events' => [
            [
                'id' => '401671001',
                'date' => '2026-09-13T17:00Z',
                'status' => ['period' => 3, 'type' => ['state' => 'in', 'completed' => false]],
                'competitions' => [[
                    'competitors' => [
                        [
                            'homeAway' => 'home',
                            'score' => '17',
                            'team' => ['displayName' => 'Kansas City Chiefs'],
                            'linescores' => [['value' => 7], ['value' => 7], ['value' => 3]],
                        ],
                        [
                            'homeAway' => 'away',
                            'score' => '10',
                            'team' => ['displayName' => 'Buffalo Bills'],
                            'linescores' => [['value' => 3], ['value' => 7], ['value' => 0]],
                        ],
                    ],
                ]],
            ],
            [
                'id' => '401671002',
                'date' => '2026-09-13T20:25Z',
                'status' => ['period' => 4, 'type' => ['state' => 'post', 'completed' => true]],
                'competitions' => [[
                    'competitors' => [
                        [
                            'homeAway' => 'home',
                            'score' => '24',
                            'team' => ['displayName' => 'Dallas Cowboys'],
                            'linescores' => [['value' => 7], ['value' => 7], ['value' => 3], ['value' => 7]],
                        ],
                        [
                            'homeAway' => 'away',
                            'score' => '17',
                            'team' => ['displayName' => 'Philadelphia Eagles'],
                            'linescores' => [['value' => 3], ['value' => 7], ['value' => 0], ['value' => 7]],
                        ],
                    ],
                ]],
            ],
        ]]);
    }
}
