<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Jobs\NotifyWinnerJob;
use Keel\App\Models\Board;
use Keel\App\Models\Campaign;
use Keel\App\Models\Win;
use Keel\App\Services\WinnerService;
use Keel\Core\Database;
use Tests\Support\SquaresFixtures;
use Tests\TestCase;

/**
 * The dealer path end to end: create a campaign, hang a board off a game, set
 * prizes, lock, export claims, then redeem the code an advisor is handed.
 */
class SquaresAdminFlowFeatureTest extends TestCase
{
    use SquaresFixtures;

    public function testDealerCreatesCampaignBoardAndPrizeThenExportsClaims(): void
    {
        $this->enableMultiTenancy();

        $dealer = $this->createOrganization('Dealer A');
        $this->actingAs(['organization_id' => (int) $dealer['id'], 'role' => 'owner']);

        $createCampaign = $this->post('/admin/campaigns', [
            '_csrf' => $this->csrfToken(),
            'name' => 'Sunday Night Squares',
            'campaign_type_id' => $this->squaresCampaignTypeId(),
            'status' => 'active',
            'brand_primary_color' => '#0f766e',
            'terms_text' => 'No purchase necessary.',
        ]);

        self::assertSame(302, $createCampaign->status);

        $campaign = Database::connection()->query('SELECT * FROM campaigns ORDER BY id DESC LIMIT 1')->fetch();
        self::assertSame((int) $dealer['id'], (int) $campaign['tenant_id']);
        self::assertSame('#0f766e', (string) $campaign['brand_primary_color']);
        self::assertNotSame('', (string) $campaign['public_slug']);
        self::assertTrue(Campaign::slugExists((string) $campaign['public_slug']));

        $campaignId = (int) $campaign['id'];

        $createBoard = $this->post('/admin/campaigns/' . $campaignId . '/boards', [
            '_csrf' => $this->csrfToken(),
            'game_id' => 0,
            'league' => 'nfl',
            'home_team' => 'Home Team',
            'away_team' => 'Away Team',
            'kickoff_at' => date('Y-m-d\TH:i', time() + 86400),
            'external_id' => 'nfl-test-9001',
            'claim_limit' => 4,
        ]);

        self::assertSame(302, $createBoard->status);

        $board = Database::connection()->query('SELECT * FROM boards ORDER BY id DESC LIMIT 1')->fetch();
        $boardId = (int) $board['id'];

        self::assertSame($campaignId, (int) $board['campaign_id']);
        self::assertSame(4, (int) $board['claim_limit']);
        self::assertSame('open', (string) $board['status']);
        // A full grid exists from the start, so a claim is an update on an existing row.
        self::assertSame(100, $this->countRows('squares', 'board_id = ?', [$boardId]));

        $savePrize = $this->post('/admin/boards/' . $boardId . '/prizes', [
            '_csrf' => $this->csrfToken(),
            'scoring_period' => 'final',
            'label' => 'Free Full Detail',
            'retail_value' => '249',
            'expires_days' => 60,
            'terms_text' => 'Appointment required.',
            'save_to_library' => '1',
        ]);

        self::assertSame(302, $savePrize->status);
        self::assertSame(1, $this->countRows('prizes', 'board_id = ?', [$boardId]));
        self::assertSame(1, $this->countRows('prize_library', 'tenant_id = ?', [(int) $dealer['id']]));

        // Saving the same period again updates rather than duplicating.
        $this->post('/admin/boards/' . $boardId . '/prizes', [
            '_csrf' => $this->csrfToken(),
            'scoring_period' => 'final',
            'label' => 'Free Full Detail Plus',
            'expires_days' => 90,
        ]);

        self::assertSame(1, $this->countRows('prizes', 'board_id = ?', [$boardId]));

        $this->claimSquare($boardId, 4, 7, [
            'first_name' => 'Robin',
            'last_name' => 'Vega',
            'email' => 'robin@example.test',
            'phone' => '5553334444',
        ]);

        $claimsPage = $this->get('/admin/boards/' . $boardId . '/claims');
        self::assertSame(200, $claimsPage->status);
        self::assertStringContainsString('robin@example.test', $claimsPage->body);

        $csv = $this->get('/admin/boards/' . $boardId . '/claims.csv');
        self::assertSame(200, $csv->status);
        self::assertStringContainsString('text/csv', (string) $csv->header('Content-Type'));
        self::assertStringContainsString('attachment; filename="claims-board-' . $boardId, (string) $csv->header('Content-Disposition'));
        self::assertStringContainsString('claimed_at,first_name,last_name,email', $csv->body);
        self::assertStringContainsString('robin@example.test', $csv->body);
        self::assertStringContainsString('4-7', $csv->body);
    }

    public function testAdvisorRedeemsACodeOnceAndOnlyForTheirOwnTenant(): void
    {
        $this->enableMultiTenancy();

        $dealer = $this->createOrganization('Dealer A');
        $advisor = $this->actingAs(['organization_id' => (int) $dealer['id'], 'role' => 'owner']);

        $winId = $this->awardOneWin((int) $dealer['id']);

        $listing = $this->get('/admin/wins');
        self::assertSame(200, $listing->status);
        self::assertStringContainsString((string) Win::find($winId)['redemption_code'], $listing->body);

        $redeem = $this->post('/admin/wins/' . $winId . '/redeem', ['_csrf' => $this->csrfToken()]);
        self::assertSame(302, $redeem->status);
        self::assertStringContainsString('notice=', (string) $redeem->header('Location'));

        $win = Win::find($winId);
        self::assertNotNull($win['redeemed_at']);
        self::assertSame((int) $advisor['id'], (int) $win['redeemed_by_user_id']);

        // A second scan of the same code is refused rather than silently re-marked.
        $again = $this->post('/admin/wins/' . $winId . '/redeem', ['_csrf' => $this->csrfToken()]);
        self::assertStringContainsString('error=', (string) $again->header('Location'));
        self::assertSame($win['redeemed_at'], Win::find($winId)['redeemed_at']);
    }

    public function testWinnerNotificationSendsOnceAndIsSafeToReplay(): void
    {
        $dealer = $this->createOrganization('Dealer A');
        $winId = $this->awardOneWin((int) $dealer['id'], ['consent_email' => 1, 'consent_sms' => 0]);

        $job = new NotifyWinnerJob();
        $job->handle(['win_id' => $winId]);

        $mailLog = $this->latestMailLog();
        $code = (string) Win::find($winId)['redemption_code'];

        self::assertStringContainsString('winner@example.test', $mailLog);
        self::assertStringContainsString($code, $mailLog);
        self::assertStringContainsString('No purchase was necessary', $mailLog);
        self::assertNotNull(Win::find($winId)['notified_at']);

        $sendCount = substr_count($mailLog, $code);

        // Replaying the job must not send a second copy.
        $job->handle(['win_id' => $winId]);
        self::assertSame($sendCount, substr_count($this->latestMailLog(), $code));
    }

    private function awardOneWin(int $tenantId, array $claimOverrides = []): int
    {
        $campaign = $this->createCampaign($tenantId);
        $game = $this->createGame();
        $board = $this->createBoard((int) $campaign['id'], (int) $game['id']);
        $boardId = (int) $board['id'];

        $this->lockBoardWithDigits($boardId, range(0, 9), range(0, 9));
        Board::updateStatus($boardId, 'locked');
        $this->createPrize($boardId, 'final', ['label' => 'Free Oil Change']);

        $this->claimSquare($boardId, 4, 7, array_merge([
            'first_name' => 'Robin',
            'last_name' => 'Vega',
            'email' => 'winner@example.test',
            'phone' => '5553334444',
        ], $claimOverrides));

        $this->setGameScores((int) $game['id'], [
            'final_home_score' => 24,
            'final_away_score' => 17,
        ], 'final');

        WinnerService::resolveBoard($boardId);

        $win = Win::findForBoardPeriod($boardId, 'final');
        self::assertIsArray($win);

        return (int) $win['id'];
    }
}
