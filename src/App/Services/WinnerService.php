<?php

namespace Keel\App\Services;

use Keel\App\Jobs\NotifyWinnerJob;
use Keel\App\Models\Board;
use Keel\App\Models\Game;
use Keel\App\Models\Prize;
use Keel\App\Models\Square;
use Keel\App\Models\Win;
use Keel\Core\Queue;

/**
 * Resolves scoring periods to winning squares.
 *
 * Orientation, fixed everywhere: rows carry the HOME team digit, columns carry
 * the AWAY team digit. A 24-17 home-away final resolves to the square whose row
 * digit is 4 and whose column digit is 7. The public grid is rendered with the
 * same orientation, so what the customer sees is what the resolver reads.
 */
class WinnerService
{
    /**
     * Safe to run as often as you like: a period that already has a win row is
     * reported as 'already_awarded' and nothing is written.
     *
     * @return array<string, array{status: string, win_id?: int, code?: string}>
     */
    public static function resolveBoard(int $boardId): array
    {
        $board = Board::find($boardId);

        if ($board === null) {
            return [];
        }

        $game = Game::find((int) $board['game_id']);

        if ($game === null) {
            return [];
        }

        $results = [];

        foreach (Prize::SCORING_PERIODS as $period) {
            $results[$period] = self::resolvePeriod($board, $game, $period);
        }

        self::syncBoardStatus($board, $game, $results);

        return $results;
    }

    /**
     * @return array{status: string, win_id?: int, code?: string, row_index?: int, col_index?: int}
     */
    public static function resolvePeriod(array $board, array $game, string $period): array
    {
        if (!in_array($period, Prize::SCORING_PERIODS, true)) {
            return ['status' => 'invalid_period'];
        }

        $existing = Win::findForBoardPeriod((int) $board['id'], $period);

        if ($existing !== null) {
            // Re-runs report the same shape as the original award, including
            // whether the winning square had a claimant.
            return [
                'status' => empty($existing['claim_id']) ? 'already_awarded_unclaimed' : 'already_awarded',
                'win_id' => (int) $existing['id'],
                'code' => (string) $existing['redemption_code'],
                'claimed' => !empty($existing['claim_id']),
            ];
        }

        $digits = Board::digits($board);

        if ($digits === null) {
            return ['status' => 'not_locked'];
        }

        // The final only pays once the game is actually over; a live 24-17 is not a final.
        if ($period === 'final' && $game['status'] !== 'final') {
            return ['status' => 'period_not_final'];
        }

        $score = Game::periodScore($game, $period);

        if ($score === null) {
            return ['status' => 'no_score'];
        }

        [$homeScore, $awayScore] = $score;

        $rowIndex = array_search($homeScore % 10, $digits['row'], true);
        $colIndex = array_search($awayScore % 10, $digits['col'], true);

        if ($rowIndex === false || $colIndex === false) {
            return ['status' => 'digits_incomplete'];
        }

        $square = Square::findByCell((int) $board['id'], (int) $rowIndex, (int) $colIndex);

        if ($square === null) {
            return ['status' => 'square_missing'];
        }

        $prize = Prize::findForBoardPeriod((int) $board['id'], $period);

        if ($prize === null) {
            return ['status' => 'no_prize', 'row_index' => (int) $rowIndex, 'col_index' => (int) $colIndex];
        }

        // An unclaimed winning square still gets a win row, with a null claimant.
        // The dealer sees it flagged in admin and decides what to do with the
        // prize; nothing is notified because there is nobody to notify.
        $claimId = empty($square['claim_id']) ? null : (int) $square['claim_id'];

        $awarded = Win::award([
            'board_id' => (int) $board['id'],
            'prize_id' => (int) $prize['id'],
            'square_id' => (int) $square['id'],
            'claim_id' => $claimId,
            'scoring_period' => $period,
        ]);

        if ($awarded === null) {
            return ['status' => 'award_failed'];
        }

        if ($awarded['created'] && $claimId !== null) {
            Queue::push(NotifyWinnerJob::class, ['win_id' => (int) $awarded['win']['id']]);
        }

        $status = $claimId === null
            ? ($awarded['created'] ? 'awarded_unclaimed' : 'already_awarded_unclaimed')
            : ($awarded['created'] ? 'awarded' : 'already_awarded');

        return [
            'status' => $status,
            'win_id' => (int) $awarded['win']['id'],
            'code' => (string) $awarded['win']['redemption_code'],
            'claimed' => $claimId !== null,
            'row_index' => (int) $rowIndex,
            'col_index' => (int) $colIndex,
        ];
    }

    /**
     * @param array<string, array{status: string}> $results
     */
    private static function syncBoardStatus(array $board, array $game, array $results): void
    {
        $currentStatus = (string) $board['status'];

        if (in_array($currentStatus, ['open', 'complete'], true)) {
            return;
        }

        $finalStatus = $results['final']['status'] ?? '';
        $finalSettled = $game['status'] === 'final'
            && in_array($finalStatus, [
                'awarded',
                'already_awarded',
                'awarded_unclaimed',
                'already_awarded_unclaimed',
                'no_prize',
            ], true);

        $nextStatus = $finalSettled ? 'complete' : 'scoring';

        if ($nextStatus !== $currentStatus) {
            Board::updateStatus((int) $board['id'], $nextStatus);
        }
    }
}
