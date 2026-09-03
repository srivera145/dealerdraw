<?php

namespace Keel\App\Controllers\Admin;

use Keel\App\Models\Board;
use Keel\App\Models\Campaign;
use Keel\App\Models\Claim;
use Keel\App\Models\Game;
use Keel\App\Models\Prize;
use Keel\App\Models\PrizeLibrary;
use Keel\App\Models\Square;
use Keel\App\Models\Win;
use Keel\App\Services\BoardLockService;
use Keel\App\Services\ScoreSyncService;
use Keel\Core\Request;
use Keel\Core\Response;

class BoardController extends AdminController
{
    public function create(Request $request, string $campaignId): void
    {
        $tenantId = $this->tenantId();
        $campaign = Campaign::findForTenant((int) $campaignId, $tenantId);

        if ($campaign === null) {
            $this->backWith('/admin/campaigns', 'error', 'Campaign not found.');
        }

        $this->view('admin.boards.create', [
            'title' => 'New Board',
            'campaign' => $campaign,
            'games' => Game::upcoming(),
            'leagues' => Game::LEAGUES,
            'defaultClaimLimit' => Board::DEFAULT_CLAIM_LIMIT,
            'error' => (string) $request->input('error', ''),
        ]);
    }

    public function store(Request $request, string $campaignId): void
    {
        $tenantId = $this->tenantId();
        $campaign = Campaign::findForTenant((int) $campaignId, $tenantId);

        if ($campaign === null) {
            $this->backWith('/admin/campaigns', 'error', 'Campaign not found.');
        }

        $gameId = (int) $request->input('game_id', 0);

        if ($gameId === 0) {
            $gameId = $this->createGameFromInput($request, (int) $campaignId);
        }

        if (Game::find($gameId) === null) {
            $this->backWith('/admin/campaigns/' . (int) $campaignId . '/boards/create', 'error', 'Pick a game or enter a new one.');
        }

        $boardId = Board::create([
            'campaign_id' => (int) $campaignId,
            'game_id' => $gameId,
            'status' => 'open',
            'claim_limit' => $this->normalizeClaimLimit($request->input('claim_limit')),
        ]);

        Square::createGrid($boardId);

        $this->redirect('/admin/boards/' . $boardId . '/edit?notice=' . urlencode('Board created.'));
    }

    public function edit(Request $request, string $id): void
    {
        $tenantId = $this->tenantId();
        $board = Board::findForTenant((int) $id, $tenantId);

        if ($board === null) {
            $this->backWith('/admin/campaigns', 'error', 'Board not found.');
        }

        $squares = Square::forBoard((int) $board['id']);
        $digits = Board::digits($board);

        $this->view('admin.boards.edit', [
            'title' => $board['away_team'] . ' at ' . $board['home_team'],
            'board' => $board,
            'squares' => $squares,
            'digits' => $digits,
            'prizes' => Prize::forBoard((int) $board['id']),
            'periods' => Prize::SCORING_PERIODS,
            'periodLabels' => Prize::PERIOD_LABELS,
            'prizeLibrary' => PrizeLibrary::forTenant($tenantId),
            'wins' => Win::forTenant($tenantId, (int) $board['id']),
            'claimedCount' => count(array_filter($squares, static fn (array $square): bool => !empty($square['claim_id']))),
            'statuses' => Board::STATUSES,
            'gameStatuses' => Game::STATUSES,
            'notice' => (string) $request->input('notice', ''),
            'error' => (string) $request->input('error', ''),
        ]);
    }

    public function update(Request $request, string $id): void
    {
        $tenantId = $this->tenantId();
        $board = Board::findForTenant((int) $id, $tenantId);

        if ($board === null) {
            $this->backWith('/admin/campaigns', 'error', 'Board not found.');
        }

        $status = (string) $request->input('status', (string) $board['status']);

        if (!in_array($status, Board::STATUSES, true)) {
            $status = (string) $board['status'];
        }

        // Digits are assigned by the lock, never by a status dropdown.
        if ($status !== 'open' && empty($board['locked_at'])) {
            $status = 'open';
        }

        Board::updateSettings((int) $board['id'], $this->normalizeClaimLimit($request->input('claim_limit')), $status);

        $this->backWith('/admin/boards/' . (int) $id . '/edit', 'notice', 'Board settings saved.');
    }

    public function lock(Request $request, string $id): void
    {
        $tenantId = $this->tenantId();
        $board = Board::findForTenant((int) $id, $tenantId);

        if ($board === null) {
            $this->backWith('/admin/campaigns', 'error', 'Board not found.');
        }

        if (BoardLockService::lock((int) $board['id']) === null) {
            $this->backWith('/admin/boards/' . (int) $id . '/edit', 'error', 'Board is already locked.');
        }

        $this->backWith('/admin/boards/' . (int) $id . '/edit', 'notice', 'Board locked and numbers drawn.');
    }

    /**
     * Manual score override. This is a one-way door for the game: scores_source
     * flips to manual and the feed stops writing to it for good.
     */
    public function updateScores(Request $request, string $id): void
    {
        $tenantId = $this->tenantId();
        $board = Board::findForTenant((int) $id, $tenantId);

        if ($board === null) {
            $this->backWith('/admin/campaigns', 'error', 'Board not found.');
        }

        $scores = [];

        foreach (Game::PERIOD_COLUMNS as [$homeColumn, $awayColumn]) {
            $scores[$homeColumn] = $this->normalizeScore($request->input($homeColumn));
            $scores[$awayColumn] = $this->normalizeScore($request->input($awayColumn));
        }

        $gameStatus = (string) $request->input('game_status', (string) $board['game_status']);

        if (!in_array($gameStatus, Game::STATUSES, true)) {
            $gameStatus = (string) $board['game_status'];
        }

        Game::applyManualScores((int) $board['game_id'], $scores, $gameStatus);
        ScoreSyncService::resolveBoardsForGame((int) $board['game_id']);

        $this->backWith('/admin/boards/' . (int) $id . '/edit', 'notice', 'Scores overridden. This game no longer accepts feed updates.');
    }

    public function claims(Request $request, string $id): void
    {
        $tenantId = $this->tenantId();
        $board = Board::findForTenant((int) $id, $tenantId);

        if ($board === null) {
            $this->backWith('/admin/campaigns', 'error', 'Board not found.');
        }

        $this->view('admin.boards.claims', [
            'title' => 'Claims',
            'board' => $board,
            'claims' => Claim::forBoard((int) $board['id']),
        ]);
    }

    public function claimsCsv(Request $request, string $id): never
    {
        $tenantId = $this->tenantId();
        $board = Board::findForTenant((int) $id, $tenantId);

        if ($board === null) {
            $this->backWith('/admin/campaigns', 'error', 'Board not found.');
        }

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['claimed_at', 'first_name', 'last_name', 'email', 'phone', 'consent_sms', 'consent_email', 'squares', 'square_cells', 'ip']);

        foreach (Claim::forBoard((int) $board['id']) as $claim) {
            fputcsv($handle, [
                $claim['created_at'],
                $claim['first_name'],
                $claim['last_name'],
                $claim['email'],
                $claim['phone'],
                (int) $claim['consent_sms'] === 1 ? 'yes' : 'no',
                (int) $claim['consent_email'] === 1 ? 'yes' : 'no',
                (int) $claim['square_count'],
                (string) ($claim['square_cells'] ?? ''),
                (string) ($claim['ip'] ?? ''),
            ]);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        $filename = 'claims-board-' . (int) $board['id'] . '-' . date('Ymd') . '.csv';

        Response::raw($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    private function createGameFromInput(Request $request, int $campaignId): int
    {
        $league = (string) $request->input('league', '');
        $homeTeam = trim((string) $request->input('home_team', ''));
        $awayTeam = trim((string) $request->input('away_team', ''));
        $kickoffAt = trim((string) $request->input('kickoff_at', ''));
        $kickoffTimestamp = $kickoffAt === '' ? false : strtotime($kickoffAt);

        if (!in_array($league, Game::LEAGUES, true) || $homeTeam === '' || $awayTeam === '' || $kickoffTimestamp === false) {
            $this->backWith('/admin/campaigns/' . $campaignId . '/boards/create', 'error', 'Enter league, both teams, and kickoff time.');
        }

        $externalId = trim((string) $request->input('external_id', ''));

        if ($externalId !== '') {
            $existing = Game::findByExternalId($league, $externalId);

            if ($existing !== null) {
                return (int) $existing['id'];
            }
        }

        return Game::create([
            'league' => $league,
            'external_id' => $externalId === '' ? null : $externalId,
            'home_team' => $homeTeam,
            'away_team' => $awayTeam,
            'kickoff_at' => date('Y-m-d H:i:s', $kickoffTimestamp),
            'status' => 'scheduled',
            'scores_source' => $externalId === '' ? 'manual' : 'feed',
        ]);
    }

    private function normalizeClaimLimit(mixed $value): int
    {
        $limit = (int) $value;

        if ($limit < 1) {
            return Board::DEFAULT_CLAIM_LIMIT;
        }

        return min($limit, Square::GRID_SIZE * Square::GRID_SIZE);
    }

    private function normalizeScore(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return max(0, (int) $value);
    }
}
