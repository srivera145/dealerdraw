<?php

namespace Keel\App\Controllers\Admin;

use Keel\App\Models\Board;
use Keel\App\Models\Prize;
use Keel\App\Models\PrizeLibrary;
use Keel\Core\Request;

class PrizeController extends AdminController
{
    /**
     * Upsert of the one prize attached to a scoring period on a board.
     */
    public function store(Request $request, string $boardId): void
    {
        $board = $this->authorizedBoard((int) $boardId);
        $returnPath = '/admin/boards/' . (int) $board['id'] . '/edit';

        $period = (string) $request->input('scoring_period', '');

        if (!in_array($period, Prize::SCORING_PERIODS, true)) {
            $this->backWith($returnPath, 'error', 'Unknown scoring period.');
        }

        $label = trim((string) $request->input('label', ''));

        if ($label === '') {
            $this->backWith($returnPath, 'error', 'Prize label is required.');
        }

        Prize::save((int) $board['id'], $period, [
            'label' => $label,
            'retail_value' => $this->normalizeValue($request->input('retail_value')),
            'terms_text' => $this->normalizeText((string) $request->input('terms_text', '')),
            'expires_days' => $this->normalizeExpiresDays($request->input('expires_days')),
        ]);

        if ((string) $request->input('save_to_library', '') === '1') {
            PrizeLibrary::create($this->tenantId(), [
                'label' => $label,
                'retail_value' => $this->normalizeValue($request->input('retail_value')),
                'terms_text' => $this->normalizeText((string) $request->input('terms_text', '')),
                'expires_days' => $this->normalizeExpiresDays($request->input('expires_days')),
            ]);
        }

        $this->backWith($returnPath, 'notice', 'Prize saved.');
    }

    public function destroy(Request $request, string $boardId, string $period): void
    {
        $board = $this->authorizedBoard((int) $boardId);
        $returnPath = '/admin/boards/' . (int) $board['id'] . '/edit';

        if (!in_array($period, Prize::SCORING_PERIODS, true)) {
            $this->backWith($returnPath, 'error', 'Unknown scoring period.');
        }

        if (!Prize::delete((int) $board['id'], $period)) {
            $this->backWith($returnPath, 'error', 'That prize has already been awarded and cannot be removed.');
        }

        $this->backWith($returnPath, 'notice', 'Prize removed.');
    }

    public function destroyLibraryItem(Request $request, string $id): void
    {
        $tenantId = $this->tenantId();
        $returnPath = (string) $request->input('return_to', '/admin/campaigns');

        if (!str_starts_with($returnPath, '/admin/')) {
            $returnPath = '/admin/campaigns';
        }

        if (!PrizeLibrary::deleteForTenant((int) $id, $tenantId)) {
            $this->backWith($returnPath, 'error', 'Only your own saved prizes can be removed.');
        }

        $this->backWith($returnPath, 'notice', 'Saved prize removed.');
    }

    private function authorizedBoard(int $boardId): array
    {
        $board = Board::findForTenant($boardId, $this->tenantId());

        if ($board === null) {
            $this->backWith('/admin/campaigns', 'error', 'Board not found.');
        }

        return $board;
    }

    private function normalizeValue(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return number_format(max(0, (float) $value), 2, '.', '');
    }

    private function normalizeExpiresDays(mixed $value): int
    {
        $days = (int) $value;

        return $days > 0 ? min($days, 3650) : 30;
    }

    private function normalizeText(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
