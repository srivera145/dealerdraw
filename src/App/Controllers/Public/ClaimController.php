<?php

namespace Keel\App\Controllers\Public;

use Keel\App\Models\Board;
use Keel\App\Models\Campaign;
use Keel\App\Models\Claim;
use Keel\App\Models\Game;
use Keel\App\Models\Prize;
use Keel\App\Models\Square;
use Keel\Core\Controller;
use Keel\Core\Database;
use Keel\Core\ErrorHandler;
use Keel\Core\RateLimiter;
use Keel\Core\Request;
use Keel\Core\Storage;

/**
 * The public claim page. Tenancy is resolved from the campaign slug and nothing
 * else - there is no tenant id in any public URL, header, or payload, so a slug
 * only ever reaches its own campaign's board.
 */
class ClaimController extends Controller
{
    private const MAX_CLAIM_POSTS_PER_IP = 10;
    private const CLAIM_DECAY_MINUTES = 60;

    public function show(Request $request, string $slug): void
    {
        $context = $this->resolveContext($slug, (int) $request->input('board', 0));

        if ($context === null) {
            ErrorHandler::render(404);

            return;
        }

        [$campaign, $board] = $context;
        $squares = Square::forBoard((int) $board['id']);
        $brandColor = $this->brandColor($campaign);

        $this->view('public.claim', [
            'title' => $campaign['name'],
            'metaDescription' => 'Claim a free square. No purchase necessary.',
            'campaign' => $campaign,
            'board' => $board,
            'claimsOpen' => $this->claimsOpen($campaign, $board),
            'squares' => $this->indexSquares($squares),
            'digits' => Board::digits($board),
            'prizes' => Prize::forBoard((int) $board['id']),
            'periods' => Prize::SCORING_PERIODS,
            'periodLabels' => Prize::PERIOD_LABELS,
            'scores' => $this->scores($board),
            'winners' => $this->winnersByPeriod((int) $board['id']),
            'availableCount' => count(array_filter($squares, static fn (array $square): bool => empty($square['claim_id']))),
            'logoUrl' => $campaign['brand_logo_path'] ? Storage::url((string) $campaign['brand_logo_path']) : null,
            'brandColor' => $brandColor,
            'brandContrast' => $this->contrastInk($brandColor),
            'notice' => (string) $request->input('notice', ''),
            'error' => (string) $request->input('error', ''),
            // Cells this visitor just claimed, plus - on a conflict bounce - the
            // ones that were taken and the ones still worth re-submitting.
            'claimedCells' => $this->cellList($request->input('cells')),
            'takenCells' => $this->cellList($request->input('taken')),
            'selectedCells' => $this->cellList($request->input('sel')),
        ]);
    }

    /**
     * Live grid payload for the poller. Digits stay null until the board locks.
     */
    public function boardState(Request $request, string $slug): never
    {
        $context = $this->resolveContext($slug, (int) $request->input('board', 0));

        if ($context === null) {
            $this->json(['error' => 'Not found.'], 404);
        }

        [$campaign, $board] = $context;

        $squares = [];

        foreach (Square::forBoard((int) $board['id']) as $square) {
            $squares[] = [
                'row' => (int) $square['row_index'],
                'col' => (int) $square['col_index'],
                'taken' => !empty($square['claim_id']),
                'name' => $this->displayName($square),
            ];
        }

        $this->json([
            'campaign' => [
                'name' => $campaign['name'],
                'slug' => $campaign['public_slug'],
            ],
            'board' => [
                'id' => (int) $board['id'],
                'status' => $board['status'],
                'locked' => Board::isLocked($board),
                'claims_open' => $this->claimsOpen($campaign, $board),
                'claim_limit' => (int) $board['claim_limit'],
                'home_team' => $board['home_team'],
                'away_team' => $board['away_team'],
                'kickoff_at' => $board['kickoff_at'],
                'game_status' => $board['game_status'],
            ],
            // Board::digits() returns null unless locked_at is set, and the
            // columns themselves are null until then, so there is nothing here
            // to leak before the draw.
            'digits' => Board::digits($board),
            'scores' => $this->scores($board),
            'squares' => $squares,
            'available' => count(array_filter($squares, static fn (array $square): bool => $square['taken'] === false)),
            'winners' => $this->winnersByPeriod((int) $board['id']),
        ]);
    }

    public function claim(Request $request, string $slug): void
    {
        $context = $this->resolveContext($slug, (int) $request->input('board', 0));

        if ($context === null) {
            ErrorHandler::render(404);

            return;
        }

        [$campaign, $board] = $context;
        $returnPath = '/p/' . rawurlencode($slug) . '?board=' . (int) $board['id'];

        if (!RateLimiter::attempt('claim|' . $this->clientIp(), self::MAX_CLAIM_POSTS_PER_IP, self::CLAIM_DECAY_MINUTES)) {
            $this->fail($request, $returnPath, 'Too many claim attempts from this connection. Try again later.', 429);
        }

        if (!$this->claimsOpen($campaign, $board)) {
            $this->fail($request, $returnPath, 'This board is closed for entries.', 409);
        }

        $firstName = trim((string) $request->input('first_name', ''));
        $lastName = trim((string) $request->input('last_name', ''));
        $email = strtolower(trim((string) $request->input('email', '')));
        $phone = $this->normalizePhone((string) $request->input('phone', ''));
        $consentSms = $this->checked($request->input('consent_sms'));
        $consentEmail = $this->checked($request->input('consent_email'));

        $cells = $this->parseCells($request->input('squares'));
        $selection = $this->cellsToString($cells);

        if ($firstName === '' || $lastName === '') {
            $this->fail($request, $returnPath, 'Enter your first and last name.', 422, [], $selection);
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->fail($request, $returnPath, 'Enter a valid email address.', 422, [], $selection);
        }

        if (strlen($phone) < 10) {
            $this->fail($request, $returnPath, 'Enter a valid phone number.', 422, [], $selection);
        }

        if (!$consentSms && !$consentEmail) {
            $this->fail($request, $returnPath, 'Pick at least one way for us to reach you if you win.', 422, [], $selection);
        }

        if ($cells === []) {
            $this->fail($request, $returnPath, 'Pick at least one square.', 422);
        }

        $claimLimit = (int) $board['claim_limit'];
        $alreadyHeld = Claim::squaresHeldByPerson((int) $board['id'], $email, $phone);

        if ($alreadyHeld + count($cells) > $claimLimit) {
            $remaining = max(0, $claimLimit - $alreadyHeld);
            $this->fail(
                $request,
                $returnPath,
                $remaining === 0
                    ? 'You already hold the maximum of ' . $claimLimit . ' squares on this board.'
                    : 'You can claim ' . $remaining . ' more square(s) on this board.',
                422,
                [],
                $selection
            );
        }

        $connection = Database::connection();
        $connection->beginTransaction();

        try {
            $claimId = Claim::create([
                'board_id' => (int) $board['id'],
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'phone' => $phone,
                'consent_sms' => $consentSms ? 1 : 0,
                'consent_email' => $consentEmail ? 1 : 0,
                'ip' => $this->clientIp(),
            ]);

            // Square::assign() only matches rows where claim_id IS NULL, so of
            // two visitors submitting the same square, exactly one update lands
            // and the other collects it as a conflict.
            $failed = [];

            foreach ($cells as $cell) {
                if (!Square::assign((int) $board['id'], $cell[0], $cell[1], $claimId)) {
                    $failed[] = $cell[0] . '-' . $cell[1];
                }
            }

            if ($failed !== []) {
                // All or nothing: the visitor gets their untaken picks back to
                // resubmit rather than a partial claim they did not agree to.
                $connection->rollBack();

                $keep = array_values(array_diff(
                    array_map(static fn (array $cell): string => $cell[0] . '-' . $cell[1], $cells),
                    $failed
                ));

                $this->fail(
                    $request,
                    $returnPath,
                    count($failed) === 1
                        ? 'Square ' . $failed[0] . ' was just claimed by someone else.'
                        : count($failed) . ' of your squares were just claimed by someone else.',
                    409,
                    $failed,
                    implode(' ', $keep)
                );
            }

            $connection->commit();
        } catch (\Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $exception;
        }

        $claimedCells = array_map(static fn (array $cell): string => $cell[0] . '-' . $cell[1], $cells);

        if ($request->wantsJson()) {
            $this->json(['ok' => true, 'claimed' => count($claimedCells), 'cells' => $claimedCells], 201);
        }

        $this->redirect(
            $returnPath
            . '&notice=' . urlencode('You are in. ' . count($claimedCells) . ' square(s) claimed.')
            . '&cells=' . urlencode(implode(' ', $claimedCells))
        );
    }

    /**
     * @return array{0: array, 1: array}|null
     */
    private function resolveContext(string $slug, int $boardId): ?array
    {
        $campaign = Campaign::findByPublicSlug($slug);

        if ($campaign === null || $campaign['status'] === 'draft') {
            return null;
        }

        // The board id is filtered by campaign id, so passing another campaign's
        // board id simply resolves to nothing.
        $board = Board::publicBoardForCampaign((int) $campaign['id'], $boardId > 0 ? $boardId : null);

        if ($board === null) {
            return null;
        }

        return [$campaign, $board];
    }

    private function claimsOpen(array $campaign, array $board): bool
    {
        if ($campaign['status'] !== 'active' || $board['status'] !== 'open') {
            return false;
        }

        if (!empty($campaign['starts_at']) && strtotime((string) $campaign['starts_at']) > time()) {
            return false;
        }

        if (!empty($campaign['ends_at']) && strtotime((string) $campaign['ends_at']) < time()) {
            return false;
        }

        return strtotime((string) $board['kickoff_at']) > time();
    }

    /**
     * @return array<string, array{home: int, away: int}|null>
     */
    private function scores(array $board): array
    {
        $scores = [];

        foreach (array_keys(Game::PERIOD_COLUMNS) as $period) {
            $score = Game::periodScore($board, $period);
            $scores[$period] = $score === null ? null : ['home' => $score[0], 'away' => $score[1]];
        }

        return $scores;
    }

    /**
     * @return array<int, array<int, array{taken: bool, name: string}>>
     */
    private function indexSquares(array $squares): array
    {
        $grid = [];

        foreach ($squares as $square) {
            $grid[(int) $square['row_index']][(int) $square['col_index']] = [
                'taken' => !empty($square['claim_id']),
                'name' => $this->displayName($square),
            ];
        }

        return $grid;
    }

    /** Public grids show a first name and last initial, never full contact details. */
    private function displayName(array $square): string
    {
        if (empty($square['claim_id'])) {
            return '';
        }

        $firstName = trim((string) ($square['first_name'] ?? ''));
        $lastInitial = strtoupper(substr(trim((string) ($square['last_name'] ?? '')), 0, 1));

        return trim($firstName . ($lastInitial !== '' ? ' ' . $lastInitial . '.' : ''));
    }

    /**
     * @return array<string, array{row: int, col: int, name: string, prize: string}>
     */
    private function winnersByPeriod(int $boardId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT w.scoring_period, s.row_index, s.col_index, c.first_name, c.last_name, p.label
             FROM wins w
             INNER JOIN squares s ON s.id = w.square_id
             INNER JOIN claims c ON c.id = w.claim_id
             INNER JOIN prizes p ON p.id = w.prize_id
             WHERE w.board_id = ?'
        );
        $statement->execute([$boardId]);

        $winners = [];

        foreach ($statement->fetchAll() as $row) {
            $winners[(string) $row['scoring_period']] = [
                'row' => (int) $row['row_index'],
                'col' => (int) $row['col_index'],
                'name' => $this->displayName(['claim_id' => 1, 'first_name' => $row['first_name'], 'last_name' => $row['last_name']]),
                'prize' => (string) $row['label'],
            ];
        }

        return $winners;
    }

    /**
     * @return array<int, array{0: int, 1: int}>
     */
    private function parseCells(mixed $input): array
    {
        $values = is_array($input) ? $input : ($input === null ? [] : [$input]);
        $cells = [];

        foreach ($values as $value) {
            if (!is_string($value) && !is_numeric($value)) {
                continue;
            }

            if (preg_match('/^([0-9])-([0-9])$/', trim((string) $value), $matches) !== 1) {
                continue;
            }

            $cells[$matches[1] . '-' . $matches[2]] = [(int) $matches[1], (int) $matches[2]];
        }

        return array_values($cells);
    }

    /**
     * Space-separated cell keys from a query string, validated the same way as
     * a submitted selection so nothing arbitrary reaches the view.
     *
     * @return string[]
     */
    private function cellList(mixed $input): array
    {
        $values = is_string($input) ? preg_split('/\s+/', trim($input)) : [];

        return array_map(
            static fn (array $cell): string => $cell[0] . '-' . $cell[1],
            $this->parseCells($values === false ? [] : $values)
        );
    }

    /**
     * @param array<int, array{0: int, 1: int}> $cells
     */
    private function cellsToString(array $cells): string
    {
        return implode(' ', array_map(static fn (array $cell): string => $cell[0] . '-' . $cell[1], $cells));
    }

    private function brandColor(array $campaign): string
    {
        $color = trim((string) ($campaign['brand_primary_color'] ?? ''));

        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1 ? strtolower($color) : '#111827';
    }

    /**
     * Picks readable text for whatever colour the dealer chose, so a pale brand
     * does not produce white-on-white buttons.
     */
    private function contrastInk(string $hexColor): string
    {
        $red = (int) hexdec(substr($hexColor, 1, 2));
        $green = (int) hexdec(substr($hexColor, 3, 2));
        $blue = (int) hexdec(substr($hexColor, 5, 2));

        // Rec. 601 luma is close enough for a two-way light/dark decision.
        $luma = (0.299 * $red + 0.587 * $green + 0.114 * $blue) / 255;

        return $luma > 0.6 ? '#111827' : '#ffffff';
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    private function checked(mixed $value): bool
    {
        return in_array((string) $value, ['1', 'on', 'true', 'yes'], true);
    }

    private function clientIp(): string
    {
        return trim((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')) ?: 'unknown';
    }

    /**
     * @param string[] $failedCells Squares that were taken between poll and submit.
     * @param string   $keepSelected Cells worth re-submitting, for the no-JS bounce.
     */
    private function fail(
        Request $request,
        string $returnPath,
        string $message,
        int $status,
        array $failedCells = [],
        string $keepSelected = ''
    ): never {
        if ($request->wantsJson()) {
            $this->json(['ok' => false, 'error' => $message, 'failed' => $failedCells], $status);
        }

        $query = '&error=' . urlencode($message);

        if ($failedCells !== []) {
            $query .= '&taken=' . urlencode(implode(' ', $failedCells));
        }

        if ($keepSelected !== '') {
            $query .= '&sel=' . urlencode($keepSelected);
        }

        $this->redirect($returnPath . $query);
    }
}
