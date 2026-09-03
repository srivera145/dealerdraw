<?php

namespace Keel\App\Console\Commands;

use Keel\App\Models\Board;
use Keel\App\Models\Campaign;
use Keel\App\Models\CampaignType;
use Keel\App\Models\Claim;
use Keel\App\Models\Game;
use Keel\App\Models\Prize;
use Keel\App\Models\Square;
use Keel\Core\Database;
use Keel\Core\Env;

/**
 * Builds a complete, working tenant so a fresh clone has something to look at:
 * a dealership, a signed-in-able user, a campaign, a board on a fake game a few
 * hours out, four prizes and a scattering of claims.
 *
 *   php scripts/seed-demo.php
 *   php scripts/seed-demo.php --fresh   (deletes the previous demo tenant first)
 *   php scripts/seed-demo.php --fresh --full   (claims all 100 squares)
 *
 * Refuses to run against a production APP_ENV, because it writes fictional
 * customer records.
 */
class SeedDemoCommand
{
    private const ORG_SLUG = 'demo-motors';
    private const CAMPAIGN_SLUG = 'demo-squares';
    private const USER_EMAIL = 'owner@demo.test';

    /** @var callable(string): void */
    private $output;

    public function __construct(?callable $output = null)
    {
        $this->output = $output ?? static function (string $line): void {
            fwrite(STDOUT, $line . "\n");
        };
    }

    /**
     * @param array<int, string> $arguments
     */
    public function handle(array $arguments): int
    {
        if (Env::get('APP_ENV') === 'production') {
            fwrite(STDERR, "Refusing to seed demo data with APP_ENV=production.\n");

            return 1;
        }

        $fresh = in_array('--fresh', $arguments, true);
        $connection = Database::connection();

        if ($fresh) {
            // Campaigns, boards, squares, claims and wins all cascade from the org.
            $connection->prepare('DELETE FROM organizations WHERE slug = ?')->execute([self::ORG_SLUG]);
            $connection->prepare('DELETE FROM users WHERE email = ?')->execute([self::USER_EMAIL]);
            $connection->prepare('DELETE FROM games WHERE external_id = ?')->execute(['demo-game-1']);
            ($this->output)('Removed the previous demo tenant.');
        }

        $existing = $connection->prepare('SELECT id FROM organizations WHERE slug = ? LIMIT 1');
        $existing->execute([self::ORG_SLUG]);

        if ($existing->fetchColumn()) {
            ($this->output)('Demo tenant already exists. Re-run with --fresh to rebuild it.');

            return 0;
        }

        $tenantId = $this->createOrganization();
        $this->createUser($tenantId);

        $campaignType = CampaignType::findBySlug('squares');

        if ($campaignType === null) {
            fwrite(STDERR, "The 'squares' campaign type is missing. Run migrations first.\n");

            return 1;
        }

        $campaignId = Campaign::create([
            'tenant_id' => $tenantId,
            'campaign_type_id' => (int) $campaignType['id'],
            'name' => 'Demo Sunday Squares',
            'status' => 'active',
            'starts_at' => null,
            'ends_at' => null,
            'brand_primary_color' => '#0f766e',
            'brand_logo_path' => null,
            'public_slug' => self::CAMPAIGN_SLUG,
            'terms_text' => "No purchase necessary. Free to enter. Open to legal residents 18 and over.\n"
                . 'Prizes are dealer-supplied service offers with no cash value. Demo data only.',
        ]);

        // Kickoff a few hours out, so the board is open and claimable.
        $gameId = Game::create([
            'league' => 'nfl',
            'external_id' => 'demo-game-1',
            'home_team' => 'Demo City Home',
            'away_team' => 'Sample Town Away',
            'kickoff_at' => date('Y-m-d H:i:s', time() + (4 * 3600)),
            'status' => 'scheduled',
            // No real feed id, so the poller never tries to sync this one.
            'scores_source' => 'manual',
        ]);

        $boardId = Board::create([
            'campaign_id' => $campaignId,
            'game_id' => $gameId,
            'status' => 'open',
            'claim_limit' => 5,
        ]);

        Square::createGrid($boardId);

        foreach ($this->prizes() as $period => $prize) {
            Prize::save($boardId, $period, $prize);
        }

        $claimed = $this->createClaims($boardId, in_array('--full', $arguments, true));

        $appUrl = rtrim((string) Env::get('APP_URL', 'http://localhost'), '/');

        ($this->output)('');
        ($this->output)('Demo tenant created.');
        ($this->output)('  Dealership     Demo Motors (tenant ' . $tenantId . ')');
        ($this->output)('  Sign in as     ' . self::USER_EMAIL);
        ($this->output)('  Board id       ' . $boardId . ' (' . $claimed . ' squares claimed)');
        ($this->output)('  Claim page     ' . $appUrl . '/p/' . self::CAMPAIGN_SLUG);
        ($this->output)('  Board admin    ' . $appUrl . '/admin/boards/' . $boardId . '/edit');
        ($this->output)('');
        ($this->output)('Sign-in codes are written to storage/logs/mail.log while MAIL_MAILER=log.');
        ($this->output)('Drive the game with: php scripts/board.php show --board=' . $boardId);

        return 0;
    }

    private function createOrganization(): int
    {
        $statement = Database::connection()->prepare(
            'INSERT INTO organizations (name, slug) VALUES (?, ?)'
        );
        $statement->execute(['Demo Motors', self::ORG_SLUG]);

        return (int) Database::connection()->lastInsertId();
    }

    private function createUser(int $tenantId): void
    {
        $statement = Database::connection()->prepare(
            'INSERT INTO users (name, email, organization_id, role, created_at)
             VALUES (?, ?, ?, ?, NOW())'
        );
        $statement->execute(['Demo Owner', self::USER_EMAIL, $tenantId, 'owner']);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function prizes(): array
    {
        return [
            'q1' => ['label' => 'Free Oil Change', 'retail_value' => '79.95', 'terms_text' => 'Most vehicles. Demo data.', 'expires_days' => 90],
            'q2' => ['label' => 'Free Tire Rotation', 'retail_value' => '49.95', 'terms_text' => 'Most vehicles. Demo data.', 'expires_days' => 90],
            'q3' => ['label' => 'Free Interior Detail', 'retail_value' => '149.00', 'terms_text' => 'Appointment required. Demo data.', 'expires_days' => 90],
            'final' => ['label' => 'Free Full Detail', 'retail_value' => '249.00', 'terms_text' => 'Appointment required. Demo data.', 'expires_days' => 90],
        ];
    }

    /**
     * Fictional entrants.
     *
     * The digits are shuffled at lock time, so which cell wins is not knowable
     * in advance. On a partly-claimed board most periods therefore resolve to
     * an unclaimed square - correct behaviour, but a poor demonstration of the
     * notification path. Pass --full to claim all 100 squares, which makes every
     * period resolve to a real winner no matter how the digits fall.
     */
    private function createClaims(int $boardId, bool $full = false): int
    {
        $names = [
            ['Dana', 'Lopez'], ['Marcus', 'Tran'], ['Priya', 'Shah'], ['Jordan', 'Blake'],
            ['Ellis', 'Ward'], ['Renee', 'Castillo'], ['Tomas', 'Guzman'], ['Aisha', 'Khan'],
            ['Bobby', 'Reyes'], ['Sam', 'Ortega'],
        ];

        $entrants = [];

        if ($full) {
            for ($row = 0; $row < 10; $row++) {
                for ($col = 0; $col < 10; $col++) {
                    [$firstName, $lastName] = $names[($row + $col) % count($names)];
                    $entrants[] = [$row, $col, $firstName, $lastName];
                }
            }
        } else {
            foreach ([[7, 3], [4, 7], [0, 0], [2, 5], [9, 1], [6, 8], [1, 4], [8, 2], [3, 6], [5, 9]] as $index => [$row, $col]) {
                $entrants[] = [$row, $col, $names[$index][0], $names[$index][1]];
            }
        }

        foreach ($entrants as $index => [$row, $col, $firstName, $lastName]) {
            $claimId = Claim::create([
                'board_id' => $boardId,
                'first_name' => $firstName,
                'last_name' => $lastName,
                // Reserved example.com domain and a 555 number, so nothing here
                // can ever reach a real person.
                'email' => strtolower($firstName) . $index . '@example.com',
                'phone' => '555010' . str_pad((string) (1000 + $index), 4, '0', STR_PAD_LEFT),
                'consent_sms' => 1,
                'consent_email' => 1,
                'ip' => '127.0.0.1',
            ]);

            Square::assign($boardId, $row, $col, $claimId);
        }

        return count($entrants);
    }
}
