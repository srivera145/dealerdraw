<?php

namespace Keel\App\Console\Commands;

use Keel\App\Models\Board;
use Keel\App\Models\Game;
use Keel\App\Models\Prize;
use Keel\App\Models\Win;
use Keel\App\Services\BoardLockService;
use Keel\App\Services\WinnerService;
use Keel\Core\Database;

/**
 * Drives a board by hand, so the whole game lifecycle can be exercised in July
 * with no live football anywhere.
 *
 *   php scripts/board.php list
 *   php scripts/board.php show    --board=1
 *   php scripts/board.php lock    --board=1
 *   php scripts/board.php score   --board=1 --period=q1 --home=7 --away=3
 *   php scripts/board.php score   --board=1 --period=final --home=24 --away=17 --final
 *   php scripts/board.php resolve --board=1
 *
 * `score` writes through the manual override path, which is also the path an
 * advisor uses in admin - so this exercises real code rather than a test-only
 * back door.
 */
class BoardCommand
{
    /** @var callable(string): void */
    private $output;

    /** @var callable(string): void */
    private $errorOutput;

    public function __construct(?callable $output = null, ?callable $errorOutput = null)
    {
        $this->output = $output ?? static function (string $line): void {
            fwrite(STDOUT, $line . "\n");
        };

        $this->errorOutput = $errorOutput ?? static function (string $line): void {
            fwrite(STDERR, $line . "\n");
        };
    }

    /**
     * @param array<int, string> $arguments Raw argv, script name included.
     */
    public function handle(array $arguments): int
    {
        $action = $arguments[1] ?? '';
        $options = $this->parse($arguments);

        return match ($action) {
            'list' => $this->listBoards(),
            'show' => $this->show($options),
            'lock' => $this->lock($options),
            'score' => $this->score($options),
            'resolve' => $this->resolve($options),
            default => $this->usage(),
        };
    }

    private function listBoards(): int
    {
        $rows = Database::connection()->query(
            "SELECT b.id, b.status, b.locked_at, c.name AS campaign, c.public_slug,
                    g.away_team, g.home_team, g.status AS game_status, g.kickoff_at,
                    (SELECT COUNT(*) FROM squares s WHERE s.board_id = b.id AND s.claim_id IS NOT NULL) AS claimed
             FROM boards b
             INNER JOIN campaigns c ON c.id = b.campaign_id
             INNER JOIN games g ON g.id = b.game_id
             ORDER BY b.id"
        )->fetchAll();

        if ($rows === []) {
            ($this->output)('No boards yet. Run: php scripts/seed-demo.php');

            return 0;
        }

        ($this->output)(sprintf('%-4s %-9s %-9s %-8s %s', 'ID', 'BOARD', 'GAME', 'CLAIMED', 'MATCHUP'));

        foreach ($rows as $row) {
            ($this->output)(sprintf(
                '%-4d %-9s %-9s %-8s %s at %s  (/p/%s)',
                (int) $row['id'],
                (string) $row['status'],
                (string) $row['game_status'],
                $row['claimed'] . '/100',
                (string) $row['away_team'],
                (string) $row['home_team'],
                (string) $row['public_slug']
            ));
        }

        return 0;
    }

    private function show(array $options): int
    {
        $board = $this->board($options);

        if ($board === null) {
            return 1;
        }

        $game = Game::find((int) $board['game_id']);
        $digits = Board::digits($board);

        ($this->output)('Board ' . $board['id'] . '  status=' . $board['status']
            . '  locked_at=' . ($board['locked_at'] ?? 'null'));
        ($this->output)('Game  ' . $game['away_team'] . ' at ' . $game['home_team']
            . '  status=' . $game['status'] . '  source=' . $game['scores_source']);
        ($this->output)('Digits rows: ' . ($digits === null ? 'hidden until lock' : implode(' ', $digits['row'])));
        ($this->output)('Digits cols: ' . ($digits === null ? 'hidden until lock' : implode(' ', $digits['col'])));
        ($this->output)('');
        ($this->output)('Scores');

        foreach (Game::PERIOD_COLUMNS as $period => [$homeColumn, $awayColumn]) {
            $home = $game[$homeColumn];
            $away = $game[$awayColumn];
            ($this->output)(sprintf(
                '  %-6s %s',
                $period,
                $home === null || $away === null ? '-' : $home . ' home / ' . $away . ' away'
            ));
        }

        ($this->output)('');
        ($this->output)('Prizes and winners');

        $prizes = Prize::forBoard((int) $board['id']);

        foreach (Prize::SCORING_PERIODS as $period) {
            $win = Win::findForBoardPeriod((int) $board['id'], $period);
            $label = $prizes[$period]['label'] ?? 'no prize set';
            $result = $win === null
                ? 'not resolved'
                : ($win['claim_id'] === null
                    ? 'UNCLAIMED square, code ' . $win['redemption_code']
                    : 'won, code ' . $win['redemption_code'] . ', notified=' . ($win['notified_at'] ?? 'no'));

            ($this->output)(sprintf('  %-6s %-26s %s', $period, $label, $result));
        }

        return 0;
    }

    private function lock(array $options): int
    {
        $board = $this->board($options);

        if ($board === null) {
            return 1;
        }

        $digits = BoardLockService::lock((int) $board['id']);

        if ($digits === null) {
            ($this->errorOutput)('Board ' . $board['id'] . ' is already locked. Digits are never redrawn.');

            return 1;
        }

        ($this->output)('Locked board ' . $board['id'] . '.');
        ($this->output)('  Row digits (home): ' . implode(' ', $digits['row']));
        ($this->output)('  Col digits (away): ' . implode(' ', $digits['col']));

        return 0;
    }

    private function score(array $options): int
    {
        $board = $this->board($options);

        if ($board === null) {
            return 1;
        }

        $period = (string) ($options['period'] ?? '');

        if (!isset(Game::PERIOD_COLUMNS[$period])) {
            ($this->errorOutput)('--period must be one of: ' . implode(', ', array_keys(Game::PERIOD_COLUMNS)));

            return 1;
        }

        if (!isset($options['home'], $options['away']) || !is_numeric($options['home']) || !is_numeric($options['away'])) {
            ($this->errorOutput)('--home and --away are required and must be numbers.');

            return 1;
        }

        $game = Game::find((int) $board['game_id']);

        // Carry the existing periods through: applyManualScores writes every
        // column, so anything not merged here would be nulled.
        $scores = [];

        foreach (Game::PERIOD_COLUMNS as [$homeColumn, $awayColumn]) {
            $scores[$homeColumn] = $game[$homeColumn];
            $scores[$awayColumn] = $game[$awayColumn];
        }

        [$homeColumn, $awayColumn] = Game::PERIOD_COLUMNS[$period];
        $scores[$homeColumn] = (int) $options['home'];
        $scores[$awayColumn] = (int) $options['away'];

        // The final period only pays out once the game itself is final, so
        // scoring it implies that. --final forces it for any other period.
        $status = ($period === 'final' || isset($options['final'])) ? 'final' : 'in_progress';

        Game::applyManualScores((int) $board['game_id'], $scores, $status);

        ($this->output)('Set ' . $period . ' to ' . $options['home'] . '-' . $options['away']
            . ' (home-away); game status is now ' . $status . '.');
        ($this->output)('This game is on manual scoring from here - the feed will not overwrite it.');

        return $this->resolve($options);
    }

    private function resolve(array $options): int
    {
        $board = $this->board($options);

        if ($board === null) {
            return 1;
        }

        $results = WinnerService::resolveBoard((int) $board['id']);

        if ($results === []) {
            ($this->errorOutput)('Nothing to resolve - the board or its game is missing.');

            return 1;
        }

        foreach ($results as $period => $result) {
            $detail = '';

            if (isset($result['code'])) {
                $detail = '  code=' . $result['code'];
            }

            ($this->output)(sprintf('  %-6s %s%s', $period, $result['status'], $detail));
        }

        ($this->output)('');
        ($this->output)('Notifications are queued. Deliver them with: php database/queue-work.php --once');

        return 0;
    }

    private function board(array $options): ?array
    {
        $boardId = (int) ($options['board'] ?? 0);

        if ($boardId <= 0) {
            ($this->errorOutput)('--board=ID is required. List them with: php scripts/board.php list');

            return null;
        }

        $board = Board::find($boardId);

        if ($board === null) {
            ($this->errorOutput)('No board with id ' . $boardId . '.');

            return null;
        }

        return $board;
    }

    /**
     * @param array<int, string> $arguments
     * @return array<string, string>
     */
    private function parse(array $arguments): array
    {
        $options = [];

        foreach (array_slice($arguments, 2) as $argument) {
            if (preg_match('/^--([a-z][a-z0-9_-]*)(?:=(.*))?$/i', (string) $argument, $matches) === 1) {
                $options[strtolower($matches[1])] = $matches[2] ?? '1';
            }
        }

        return $options;
    }

    private function usage(): int
    {
        ($this->errorOutput)('Usage: php scripts/board.php <list|show|lock|score|resolve> [options]');
        ($this->errorOutput)('');
        ($this->errorOutput)('  list                                          every board with its status');
        ($this->errorOutput)('  show    --board=1                             digits, scores, prizes, winners');
        ($this->errorOutput)('  lock    --board=1                             draw the digits and close entries');
        ($this->errorOutput)('  score   --board=1 --period=q1 --home=7 --away=3');
        ($this->errorOutput)('  score   --board=1 --period=final --home=24 --away=17 --final');
        ($this->errorOutput)('  resolve --board=1                             re-run winner resolution');

        return 1;
    }
}
