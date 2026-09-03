<?php

namespace Keel\App\Services;

use Keel\App\Models\Board;

class BoardLockService
{
    /**
     * Locks a board and assigns its digits. Returns null when the board was
     * already locked - re-locking would reshuffle digits under claims that are
     * already public, so it is refused rather than repeated.
     *
     * @return array{row: int[], col: int[]}|null
     */
    public static function lock(int $boardId): ?array
    {
        $board = Board::find($boardId);

        if ($board === null || $board['status'] !== 'open' || !empty($board['locked_at'])) {
            return null;
        }

        $rowDigits = self::shuffledDigits();
        $colDigits = self::shuffledDigits();

        if (!Board::applyLock($boardId, $rowDigits, $colDigits)) {
            return null;
        }

        return ['row' => $rowDigits, 'col' => $colDigits];
    }

    /**
     * Fisher-Yates over 0-9 driven by random_int. shuffle() uses the Mt19937
     * sequence, which is not acceptable for deciding who wins a prize.
     *
     * @return int[]
     */
    public static function shuffledDigits(): array
    {
        $digits = range(0, 9);

        for ($index = count($digits) - 1; $index > 0; $index--) {
            $swapWith = random_int(0, $index);
            [$digits[$index], $digits[$swapWith]] = [$digits[$swapWith], $digits[$index]];
        }

        return $digits;
    }
}
