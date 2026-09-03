<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Jobs\NotifyWinnerJob;
use Keel\App\Models\Board;
use Keel\App\Models\SmsOptOut;
use Keel\App\Models\Win;
use Keel\App\Services\Sms\PlivoClient;
use Keel\App\Services\WinnerService;
use Keel\Core\Database;
use Tests\Support\SquaresFixtures;
use Tests\TestCase;

/**
 * Winner notification: idempotency, tenant-wide opt-out, credential hygiene,
 * and the unclaimed-square path.
 */
class WinnerNotificationFeatureTest extends TestCase
{
    use SquaresFixtures;

    private const AUTH_ID = 'MATESTAUTHIDVALUE123';
    private const AUTH_TOKEN = 'super-secret-plivo-token-abc123';

    /** @var array<int, array{url: string, body: array, headers: array}> */
    private array $plivoCalls = [];

    private string $plivoResponse = '{"message_uuid":["uuid-abc-123"],"message":"message(s) queued"}';
    private int $plivoStatusCode = 202;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plivoCalls = [];
        $this->plivoResponse = '{"message_uuid":["uuid-abc-123"],"message":"message(s) queued"}';
        $this->plivoStatusCode = 202;

        $this->setEnv('PLIVO_AUTH_ID', self::AUTH_ID);
        $this->setEnv('PLIVO_AUTH_TOKEN', self::AUTH_TOKEN);
        $this->setEnv('PLIVO_SRC_NUMBER', '+15550001111');
        $this->setEnv('APP_URL', 'http://localhost');
    }

    public function testReRunningTheJobOnANotifiedWinNeverCallsPlivoAgain(): void
    {
        $context = $this->awardWin();

        $this->job()->handle(['win_id' => $context['win_id']]);

        self::assertCount(1, $this->plivoCalls);
        self::assertNotNull(Win::find($context['win_id'])['notified_at']);

        // The log writes each mail twice (text body then HTML body), so this is
        // a baseline to compare against rather than an absolute count.
        $mailAfterFirstRun = substr_count($this->latestMailLog(), $context['code']);
        self::assertGreaterThan(0, $mailAfterFirstRun);

        // Three more runs, including a fresh job instance as the queue would use.
        for ($run = 0; $run < 3; $run++) {
            $this->job()->handle(['win_id' => $context['win_id']]);
        }

        self::assertCount(1, $this->plivoCalls, 'notified_at is the guard; Plivo is not called again');
        self::assertSame($mailAfterFirstRun, substr_count($this->latestMailLog(), $context['code']));
    }

    public function testMessageBodyCarriesDealerPrizeCodeExpiryAndStopInstructions(): void
    {
        $context = $this->awardWin(['dealer' => 'Rivera Motors', 'prize' => 'Free Oil Change']);

        $this->job()->handle(['win_id' => $context['win_id']]);

        $text = (string) $this->plivoCalls[0]['body']['text'];

        self::assertStringContainsString('Rivera Motors', $text);
        self::assertStringContainsString('Free Oil Change', $text);
        self::assertStringContainsString($context['code'], $text);
        self::assertStringContainsString('Redeem by ' . Win::expiresAt(Win::notificationPayload($context['win_id'])), $text);
        self::assertStringContainsString('Reply STOP to opt out', $text);

        // Destination is normalised to E.164 before it leaves the app.
        self::assertSame('+15550102233', $this->plivoCalls[0]['body']['dst']);
    }

    public function testStopOnOneBoardSuppressesSendsOnThatTenantsOtherBoards(): void
    {
        $dealer = $this->createOrganization('Rivera Motors');
        $phone = '5550102233';

        $first = $this->awardWin(['organization' => $dealer, 'phone' => $phone]);
        $this->job()->handle(['win_id' => $first['win_id']]);
        self::assertCount(1, $this->plivoCalls);

        // The customer replies STOP to that message.
        $this->postPlivoWebhook('/webhooks/plivo/inbound', [
            'From' => '+15550102233',
            'To' => '+15550001111',
            'Text' => 'STOP',
            'MessageUUID' => 'inbound-1',
        ]);

        self::assertTrue(SmsOptOut::isOptedOut((int) $dealer['id'], '+15550102233'));

        // Consent is off on the claim rows too, not just the suppression list.
        $consent = Database::connection()->prepare('SELECT consent_sms FROM claims WHERE phone = ?');
        $consent->execute([$phone]);
        foreach ($consent->fetchAll(\PDO::FETCH_COLUMN) as $flag) {
            self::assertSame(0, (int) $flag);
        }

        // A completely different board and campaign for the same dealership.
        $second = $this->awardWin(['organization' => $dealer, 'phone' => $phone, 'campaign' => 'Second Campaign']);
        self::assertNotSame($first['board_id'], $second['board_id']);

        $this->job()->handle(['win_id' => $second['win_id']]);

        self::assertCount(1, $this->plivoCalls, 'the STOP suppresses the other board too');
        self::assertSame('skipped_opted_out', (string) Win::find($second['win_id'])['sms_status']);

        // Email is unaffected by an SMS opt-out.
        self::assertSame('sent', (string) Win::find($second['win_id'])['email_status']);
        self::assertStringContainsString($second['code'], $this->latestMailLog());
    }

    public function testStartPutsTheNumberBack(): void
    {
        $dealer = $this->createOrganization('Rivera Motors');
        $context = $this->awardWin(['organization' => $dealer]);
        $this->job()->handle(['win_id' => $context['win_id']]);

        $this->postPlivoWebhook('/webhooks/plivo/inbound', ['From' => '+15550102233', 'Text' => 'STOP']);
        self::assertTrue(SmsOptOut::isOptedOut((int) $dealer['id'], '+15550102233'));

        $this->postPlivoWebhook('/webhooks/plivo/inbound', ['From' => '+15550102233', 'Text' => 'START']);
        self::assertFalse(SmsOptOut::isOptedOut((int) $dealer['id'], '+15550102233'));
    }

    public function testOneTenantsStopDoesNotSuppressAnUnrelatedDealership(): void
    {
        $dealerA = $this->createOrganization('Dealer A');
        $dealerB = $this->createOrganization('Dealer B');

        // Only dealer A has ever messaged this number.
        $winA = $this->awardWin(['organization' => $dealerA, 'phone' => '5550102233']);
        $this->job()->handle(['win_id' => $winA['win_id']]);

        $this->postPlivoWebhook('/webhooks/plivo/inbound', ['From' => '+15550102233', 'Text' => 'stop']);

        self::assertTrue(SmsOptOut::isOptedOut((int) $dealerA['id'], '+15550102233'));
        self::assertFalse(SmsOptOut::isOptedOut((int) $dealerB['id'], '+15550102233'));
        self::assertFalse(SmsOptOut::isOptedOut((int) $dealerA['id'], '+15559998888'));
    }

    public function testUnclaimedWinningSquareIsFlaggedNotCrashed(): void
    {
        $dealer = $this->createOrganization('Rivera Motors');
        $campaign = $this->createCampaign((int) $dealer['id']);
        $game = $this->createGame();
        $board = $this->createBoard((int) $campaign['id'], (int) $game['id']);
        $boardId = (int) $board['id'];

        $this->lockBoardWithDigits($boardId, range(0, 9), range(0, 9));
        Board::updateStatus($boardId, 'locked');
        $this->createPrize($boardId, 'final', ['label' => 'Free Full Detail']);
        // Square 4-7 stays unclaimed.

        $this->setGameScores((int) $game['id'], ['final_home_score' => 24, 'final_away_score' => 17], 'final');

        $results = WinnerService::resolveBoard($boardId);
        self::assertSame('awarded_unclaimed', $results['final']['status']);

        $win = Win::findForBoardPeriod($boardId, 'final');
        self::assertIsArray($win, 'an unclaimed winning square still produces a win row');
        self::assertNull($win['claim_id']);
        self::assertSame(8, strlen((string) $win['redemption_code']));

        // Nothing was queued, because there is nobody to notify.
        self::assertSame(0, $this->countRows('jobs', 'job_class = ?', [NotifyWinnerJob::class]));

        // Running the job against it anyway must not blow up.
        $this->job()->handle(['win_id' => (int) $win['id']]);

        self::assertCount(0, $this->plivoCalls);
        self::assertSame('skipped_unclaimed', (string) Win::find((int) $win['id'])['sms_status']);

        // The dealer sees it flagged, with no redeem-by-winner details.
        $this->enableMultiTenancy();
        $this->actingAs(['organization_id' => (int) $dealer['id'], 'role' => 'owner']);
        $page = $this->get('/admin/wins');

        self::assertSame(200, $page->status);
        self::assertStringContainsString('Nobody claimed this square', $page->body);
        self::assertStringContainsString((string) $win['redemption_code'], $page->body);

        // Re-resolving stays idempotent for unclaimed rows too.
        WinnerService::resolveBoard($boardId);
        self::assertSame(1, $this->countRows('wins', 'board_id = ?', [$boardId]));
    }

    public function testEmailStillSendsWhenSmsFails(): void
    {
        $this->plivoStatusCode = 401;
        $this->plivoResponse = (string) json_encode([
            'error' => 'authentication failed for ' . self::AUTH_ID . ' token ' . self::AUTH_TOKEN,
        ]);

        $context = $this->awardWin();

        $this->job()->handle(['win_id' => $context['win_id']]);

        $win = Win::find($context['win_id']);
        self::assertSame('failed', (string) $win['sms_status']);
        self::assertSame('sent', (string) $win['email_status']);
        self::assertStringContainsString($context['code'], $this->latestMailLog());

        // Self-check: credentials must not survive into the stored error.
        self::assertStringNotContainsString(self::AUTH_TOKEN, (string) $win['notify_error']);
        self::assertStringNotContainsString(self::AUTH_ID, (string) $win['notify_error']);
        self::assertStringContainsString('[redacted]', (string) $win['notify_error']);

        // One channel succeeded, so the win stays notified rather than retrying.
        self::assertNotNull($win['notified_at']);
    }

    public function testAnUnusablePhoneNumberIsRefusedRatherThanSent(): void
    {
        $context = $this->awardWin(['phone' => '555']);

        $this->job()->handle(['win_id' => $context['win_id']]);

        self::assertCount(0, $this->plivoCalls);

        $win = Win::find($context['win_id']);
        self::assertSame('invalid_number', (string) $win['sms_status']);
        self::assertSame('sent', (string) $win['email_status']);
    }

    public function testWhenEveryChannelFailsTheNotificationIsReleasedForRetry(): void
    {
        $this->plivoStatusCode = 500;
        $this->plivoResponse = '{"error":"upstream unavailable"}';
        $this->setEnv('MAIL_MAILER', 'smtp');
        $this->setEnv('MAIL_HOST', '127.0.0.1');
        $this->setEnv('MAIL_PORT', '1');

        $context = $this->awardWin();

        try {
            $this->job()->handle(['win_id' => $context['win_id']]);
            self::fail('Expected the job to throw so the queue retries it.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Winner notification failed', $exception->getMessage());
            self::assertStringNotContainsString(self::AUTH_TOKEN, $exception->getMessage());
        }

        // Released, so a retry is possible rather than the code being lost.
        self::assertNull(Win::find($context['win_id'])['notified_at']);
    }

    public function testRedemptionCodesAvoidCharactersThatGetMisreadAloud(): void
    {
        $seen = '';

        for ($index = 0; $index < 400; $index++) {
            $code = Win::generateCode();
            self::assertSame(8, strlen($code));
            self::assertMatchesRegularExpression('/^[A-Z0-9]{8}$/', $code);
            $seen .= $code;
        }

        foreach (['0', 'O', '1', 'I', 'L'] as $banned) {
            self::assertStringNotContainsString($banned, $seen, "code alphabet must exclude {$banned}");
        }
    }

    public function testRedemptionCodesStayUniqueUnderCollision(): void
    {
        $context = $this->awardWin();
        $existing = (string) Win::find($context['win_id'])['redemption_code'];

        // A second win on the same board, different period.
        $boardId = $context['board_id'];
        $this->createPrize($boardId, 'q1', ['label' => 'Free Tire Rotation']);
        $this->claimSquare($boardId, 7, 3, ['first_name' => 'Q1', 'email' => 'q1@example.test']);
        $this->setGameScores($context['game_id'], [
            'q1_home_score' => 7,
            'q1_away_score' => 3,
            'final_home_score' => 24,
            'final_away_score' => 17,
        ], 'final');

        WinnerService::resolveBoard($boardId);

        $codes = Database::connection()->query('SELECT redemption_code FROM wins')->fetchAll(\PDO::FETCH_COLUMN);

        self::assertCount(2, $codes);
        self::assertSame($codes, array_unique($codes));
        self::assertContains($existing, $codes);
    }

    public function testStatusCallbackUpdatesDeliveryStateOnlyWhenSignedByPlivo(): void
    {
        $context = $this->awardWin();
        $this->job()->handle(['win_id' => $context['win_id']]);

        self::assertSame('uuid-abc-123', (string) Win::find($context['win_id'])['sms_message_uuid']);

        // Unsigned request is refused outright.
        $unsigned = $this->post('/webhooks/plivo/status', [
            'MessageUUID' => 'uuid-abc-123',
            'Status' => 'delivered',
        ]);

        self::assertSame(403, $unsigned->status);
        self::assertNotSame('delivered', (string) Win::find($context['win_id'])['sms_status']);

        // Forged signature is refused too.
        $forged = $this->post('/webhooks/plivo/status', [
            'MessageUUID' => 'uuid-abc-123',
            'Status' => 'delivered',
        ], [
            'X-Plivo-Signature-V2' => base64_encode(hash_hmac('sha256', 'whatever', 'guessed', true)),
            'X-Plivo-Signature-V2-Nonce' => 'nonce-1',
        ]);

        self::assertSame(403, $forged->status);
        self::assertNotSame('delivered', (string) Win::find($context['win_id'])['sms_status']);

        // Correctly signed request is applied.
        $signed = $this->postPlivoWebhook('/webhooks/plivo/status', [
            'MessageUUID' => 'uuid-abc-123',
            'Status' => 'delivered',
        ]);

        self::assertSame(200, $signed->status);

        $win = Win::find($context['win_id']);
        self::assertSame('delivered', (string) $win['sms_status']);
        self::assertNotNull($win['sms_status_at']);
    }

    public function testInboundStopIsIgnoredWithoutAValidSignature(): void
    {
        $dealer = $this->createOrganization('Rivera Motors');
        $context = $this->awardWin(['organization' => $dealer]);
        $this->job()->handle(['win_id' => $context['win_id']]);

        $response = $this->post('/webhooks/plivo/inbound', ['From' => '+15550102233', 'Text' => 'STOP']);

        self::assertSame(403, $response->status);
        self::assertFalse(SmsOptOut::isOptedOut((int) $dealer['id'], '+15550102233'));
    }

    /**
     * Awards a final-period win and returns its identifiers.
     *
     * @return array{win_id: int, board_id: int, game_id: int, code: string}
     */
    private function awardWin(array $options = []): array
    {
        $dealer = $options['organization'] ?? $this->createOrganization((string) ($options['dealer'] ?? 'Rivera Motors'));
        $campaign = $this->createCampaign((int) $dealer['id'], ['name' => (string) ($options['campaign'] ?? 'Sunday Squares')]);
        $game = $this->createGame();
        $board = $this->createBoard((int) $campaign['id'], (int) $game['id']);
        $boardId = (int) $board['id'];

        $this->lockBoardWithDigits($boardId, range(0, 9), range(0, 9));
        Board::updateStatus($boardId, 'locked');
        $this->createPrize($boardId, 'final', [
            'label' => (string) ($options['prize'] ?? 'Free Full Detail'),
            'expires_days' => 90,
        ]);

        $this->claimSquare($boardId, 4, 7, [
            'first_name' => 'Robin',
            'last_name' => 'Vega',
            'email' => 'robin_' . bin2hex(random_bytes(3)) . '@example.test',
            'phone' => (string) ($options['phone'] ?? '5550102233'),
            'consent_sms' => 1,
            'consent_email' => 1,
        ]);

        $this->setGameScores((int) $game['id'], ['final_home_score' => 24, 'final_away_score' => 17], 'final');

        WinnerService::resolveBoard($boardId);

        $win = Win::findForBoardPeriod($boardId, 'final');
        self::assertIsArray($win);

        return [
            'win_id' => (int) $win['id'],
            'board_id' => $boardId,
            'game_id' => (int) $game['id'],
            'code' => (string) $win['redemption_code'],
        ];
    }

    private function job(): NotifyWinnerJob
    {
        $client = new PlivoClient(function (string $url, string $body, array $headers): array {
            $this->plivoCalls[] = [
                'url' => $url,
                'body' => json_decode($body, true),
                'headers' => $headers,
            ];

            return ['status' => $this->plivoStatusCode, 'body' => $this->plivoResponse];
        });

        return new NotifyWinnerJob($client);
    }

    /** Posts a webhook with a genuine Plivo V2 signature over the called URL. */
    private function postPlivoWebhook(string $uri, array $data): \Tests\Support\TestResponse
    {
        $url = rtrim((string) ($_ENV['APP_URL'] ?? 'http://localhost'), '/') . $uri;
        $nonce = '1234567890';

        return $this->post($uri, $data, [
            'X-Plivo-Signature-V2' => base64_encode(hash_hmac('sha256', $url . $nonce, self::AUTH_TOKEN, true)),
            'X-Plivo-Signature-V2-Nonce' => $nonce,
        ]);
    }

    private function setEnv(string $key, string $value): void
    {
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
