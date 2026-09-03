<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Models\Board;
use Tests\Support\SquaresFixtures;
use Tests\TestCase;

/**
 * Self-check: can a second tenant read or claim on another tenant's board
 * through any route?
 */
class SquaresTenantIsolationFeatureTest extends TestCase
{
    use SquaresFixtures;

    public function testSecondTenantCannotReadOrMutateAnotherTenantsBoard(): void
    {
        $this->enableMultiTenancy();

        $dealerA = $this->createOrganization('Dealer A');
        $dealerB = $this->createOrganization('Dealer B');

        $campaign = $this->createCampaign((int) $dealerA['id'], ['name' => 'Dealer A Squares']);
        $game = $this->createGame();
        $board = $this->createBoard((int) $campaign['id'], (int) $game['id']);
        $this->createPrize((int) $board['id'], 'q1');

        $this->actingAs([
            'email' => 'b-owner@example.test',
            'organization_id' => (int) $dealerB['id'],
            'role' => 'owner',
        ]);

        $campaignId = (int) $campaign['id'];
        $boardId = (int) $board['id'];

        $reads = [
            '/admin/campaigns/' . $campaignId . '/edit',
            '/admin/boards/' . $boardId . '/edit',
            '/admin/boards/' . $boardId . '/claims',
            '/admin/boards/' . $boardId . '/claims.csv',
            '/admin/campaigns/' . $campaignId . '/boards/create',
        ];

        foreach ($reads as $uri) {
            $response = $this->get($uri);

            self::assertSame(302, $response->status, $uri . ' should not be readable');
            self::assertStringStartsWith('/admin/campaigns?error=', (string) $response->header('Location'), $uri);
            self::assertStringNotContainsString('Dealer A Squares', $response->body, $uri);
        }

        $writes = [
            '/admin/campaigns/' . $campaignId,
            '/admin/boards/' . $boardId,
            '/admin/boards/' . $boardId . '/lock',
            '/admin/boards/' . $boardId . '/scores',
            '/admin/boards/' . $boardId . '/prizes',
            '/admin/boards/' . $boardId . '/prizes/q1/delete',
        ];

        foreach ($writes as $uri) {
            $response = $this->post($uri, [
                '_csrf' => $this->csrfToken(),
                'name' => 'Hijacked',
                'label' => 'Hijacked prize',
                'scoring_period' => 'q1',
                'claim_limit' => 99,
                'status' => 'complete',
                'final_home_score' => 24,
                'final_away_score' => 17,
                'game_status' => 'final',
            ]);

            self::assertSame(302, $response->status, $uri . ' should not be writable');
            self::assertStringStartsWith('/admin/campaigns?error=', (string) $response->header('Location'), $uri);
        }

        // Nothing the other dealer sent through those routes touched the row.
        $reloadedBoard = Board::find($boardId);
        self::assertSame('open', (string) $reloadedBoard['status']);
        self::assertNull($reloadedBoard['locked_at']);
        self::assertSame(5, (int) $reloadedBoard['claim_limit']);
        self::assertSame(1, $this->countRows('prizes', 'board_id = ?', [$boardId]));

        // The winners list is scoped the same way.
        $winsResponse = $this->get('/admin/wins');
        self::assertSame(200, $winsResponse->status);
        self::assertStringNotContainsString('Dealer A Squares', $winsResponse->body);
    }

    public function testPublicSlugOnlyEverResolvesItsOwnCampaignBoard(): void
    {
        $dealerA = $this->createOrganization('Dealer A');
        $dealerB = $this->createOrganization('Dealer B');

        $campaignA = $this->createCampaign((int) $dealerA['id'], ['name' => 'A Game', 'public_slug' => 'dealer-a-game']);
        $campaignB = $this->createCampaign((int) $dealerB['id'], ['name' => 'B Game', 'public_slug' => 'dealer-b-game']);

        $gameA = $this->createGame(['home_team' => 'A Home', 'away_team' => 'A Away']);
        $gameB = $this->createGame(['home_team' => 'B Home', 'away_team' => 'B Away']);

        $boardA = $this->createBoard((int) $campaignA['id'], (int) $gameA['id']);
        $boardB = $this->createBoard((int) $campaignB['id'], (int) $gameB['id']);

        // Dealer A's slug with Dealer B's board id must not cross over.
        $response = $this->getJson('/p/dealer-a-game/board?board=' . (int) $boardB['id']);

        self::assertSame(404, $response->status);

        $ownBoard = $this->getJson('/p/dealer-a-game/board?board=' . (int) $boardA['id']);
        self::assertSame(200, $ownBoard->status);
        self::assertSame((int) $boardA['id'], $ownBoard->json()['board']['id']);
        self::assertSame('A Home', $ownBoard->json()['board']['home_team']);

        // And a claim posted against the wrong slug lands on the wrong board's
        // grid only if the board belongs to that slug - here it is rejected.
        $claimResponse = $this->post('/p/dealer-a-game/claim?board=' . (int) $boardB['id'], [
            '_csrf' => $this->csrfToken(),
            'first_name' => 'Pat',
            'last_name' => 'Quinn',
            'email' => 'pat@example.test',
            'phone' => '5551234567',
            'consent_email' => '1',
            'squares' => ['0-0'],
        ]);

        self::assertSame(404, $claimResponse->status);
        self::assertSame(0, $this->countRows('claims', 'board_id = ?', [(int) $boardB['id']]));
    }

    public function testDraftCampaignIsNotPubliclyReachable(): void
    {
        $dealer = $this->createOrganization('Dealer A');
        $campaign = $this->createCampaign((int) $dealer['id'], ['status' => 'draft', 'public_slug' => 'not-live-yet']);
        $game = $this->createGame();
        $this->createBoard((int) $campaign['id'], (int) $game['id']);

        self::assertSame(404, $this->get('/p/not-live-yet')->status);
        self::assertSame(404, $this->getJson('/p/not-live-yet/board')->status);
    }
}
