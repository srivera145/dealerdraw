<?php

declare(strict_types=1);

namespace Tests\Support;

use Keel\App\Models\Board;
use Keel\App\Models\Campaign;
use Keel\App\Models\CampaignType;
use Keel\App\Models\Claim;
use Keel\App\Models\Game;
use Keel\App\Models\Prize;
use Keel\App\Models\Square;
use Keel\Core\Database;

/**
 * Fixture builders for squares boards. Kept out of TestCase so the framework
 * base class stays game-type agnostic.
 */
trait SquaresFixtures
{
    protected function squaresCampaignTypeId(): int
    {
        $campaignType = CampaignType::findBySlug('squares');

        if ($campaignType === null) {
            throw new \RuntimeException('The squares campaign type seed is missing.');
        }

        return (int) $campaignType['id'];
    }

    protected function createCampaign(int $tenantId, array $overrides = []): array
    {
        $name = (string) ($overrides['name'] ?? 'Sunday Squares');

        $id = Campaign::create([
            'tenant_id' => $tenantId,
            'campaign_type_id' => $this->squaresCampaignTypeId(),
            'name' => $name,
            'status' => (string) ($overrides['status'] ?? 'active'),
            'starts_at' => $overrides['starts_at'] ?? null,
            'ends_at' => $overrides['ends_at'] ?? null,
            'brand_primary_color' => $overrides['brand_primary_color'] ?? null,
            'brand_logo_path' => $overrides['brand_logo_path'] ?? null,
            'public_slug' => (string) ($overrides['public_slug'] ?? Campaign::generateSlug($name)),
            'terms_text' => $overrides['terms_text'] ?? null,
        ]);

        return Campaign::findForTenant($id, $tenantId) ?? [];
    }

    protected function createGame(array $overrides = []): array
    {
        $id = Game::create([
            'league' => (string) ($overrides['league'] ?? 'nfl'),
            'external_id' => $overrides['external_id'] ?? ('ext-' . bin2hex(random_bytes(4))),
            'home_team' => (string) ($overrides['home_team'] ?? 'Home Team'),
            'away_team' => (string) ($overrides['away_team'] ?? 'Away Team'),
            'kickoff_at' => (string) ($overrides['kickoff_at'] ?? date('Y-m-d H:i:s', time() + 86400)),
            'status' => (string) ($overrides['status'] ?? 'scheduled'),
            'scores_source' => (string) ($overrides['scores_source'] ?? 'feed'),
        ]);

        return Game::find($id) ?? [];
    }

    protected function createBoard(int $campaignId, int $gameId, array $overrides = []): array
    {
        $id = Board::create([
            'campaign_id' => $campaignId,
            'game_id' => $gameId,
            'status' => (string) ($overrides['status'] ?? 'open'),
            'claim_limit' => (int) ($overrides['claim_limit'] ?? Board::DEFAULT_CLAIM_LIMIT),
        ]);

        Square::createGrid($id);

        return Board::find($id) ?? [];
    }

    /**
     * Locks a board with known digits so a test can aim at an exact cell.
     *
     * @param int[] $rowDigits
     * @param int[] $colDigits
     */
    protected function lockBoardWithDigits(int $boardId, array $rowDigits, array $colDigits): void
    {
        Board::applyLock($boardId, $rowDigits, $colDigits);
    }

    protected function claimSquare(int $boardId, int $rowIndex, int $colIndex, array $overrides = []): int
    {
        $claimId = Claim::create([
            'board_id' => $boardId,
            'first_name' => (string) ($overrides['first_name'] ?? 'Casey'),
            'last_name' => (string) ($overrides['last_name'] ?? 'Rivera'),
            'email' => (string) ($overrides['email'] ?? 'casey_' . bin2hex(random_bytes(3)) . '@example.test'),
            'phone' => (string) ($overrides['phone'] ?? '555' . random_int(1000000, 9999999)),
            'consent_sms' => (int) ($overrides['consent_sms'] ?? 0),
            'consent_email' => (int) ($overrides['consent_email'] ?? 1),
            'ip' => (string) ($overrides['ip'] ?? '127.0.0.1'),
        ]);

        Square::assign($boardId, $rowIndex, $colIndex, $claimId);

        return $claimId;
    }

    protected function createPrize(int $boardId, string $period, array $overrides = []): int
    {
        return Prize::save($boardId, $period, [
            'label' => (string) ($overrides['label'] ?? 'Free Oil Change'),
            'retail_value' => $overrides['retail_value'] ?? '79.95',
            'terms_text' => $overrides['terms_text'] ?? null,
            'expires_days' => (int) ($overrides['expires_days'] ?? 90),
        ]);
    }

    protected function setGameScores(int $gameId, array $columns, string $status): void
    {
        $assignments = [];
        $bindings = ['id' => $gameId, 'status' => $status];

        foreach ($columns as $column => $value) {
            $assignments[] = "{$column} = :{$column}";
            $bindings[$column] = $value;
        }

        $assignments[] = 'status = :status';

        $statement = Database::connection()->prepare(
            'UPDATE games SET ' . implode(', ', $assignments) . ' WHERE id = :id'
        );
        $statement->execute($bindings);
    }

    protected function countRows(string $table, string $where = '1', array $bindings = []): int
    {
        $statement = Database::connection()->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where}");
        $statement->execute($bindings);

        return (int) $statement->fetchColumn();
    }
}
