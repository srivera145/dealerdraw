<?php
$board = $board ?? [];
$squares = $squares ?? [];
$digits = $digits ?? null;
$prizes = $prizes ?? [];
$periods = $periods ?? [];
$periodLabels = $periodLabels ?? [];
$prizeLibrary = $prizeLibrary ?? [];
$wins = $wins ?? [];
$claimedCount = (int) ($claimedCount ?? 0);
$statuses = $statuses ?? [];
$gameStatuses = $gameStatuses ?? [];
$notice = (string) ($notice ?? '');
$error = (string) ($error ?? '');
$boardId = (int) ($board['id'] ?? 0);
$isLocked = !empty($board['locked_at']);
$isManual = ($board['scores_source'] ?? '') === 'manual';

$grid = [];
foreach ($squares as $square) {
    $grid[(int) $square['row_index']][(int) $square['col_index']] = $square;
}

$winnerCells = [];
foreach ($wins as $win) {
    $winnerCells[(int) $win['row_index'] . '-' . (int) $win['col_index']] = (string) $win['scoring_period'];
}
?>
<!DOCTYPE html>
<html lang="en"<?= \Keel\Core\Theme::htmlAttribute() ?>>
<head>
<?php require __DIR__ . '/../../partials/head.php'; ?>
<style>
    .squares-grid { border-collapse: collapse; font-size: 11px; }
    .squares-grid th, .squares-grid td {
        border: 1px solid rgba(148, 163, 184, 0.35);
        width: 46px; height: 40px; text-align: center; vertical-align: middle; padding: 2px;
    }
    .squares-grid td.is-taken { background: rgba(16, 185, 129, 0.14); }
    .squares-grid td.is-winner { background: rgba(245, 158, 11, 0.28); font-weight: 700; }
</style>
</head>
<body class="bg-gray-50 min-h-screen">
    <div class="mx-auto max-w-6xl px-4 py-10">
        <?php require __DIR__ . '/../_nav.php'; ?>

        <div class="mb-8 flex flex-wrap items-start justify-between gap-4">
            <div>
                <a href="/admin/campaigns/<?= (int) $board['campaign_id'] ?>/edit" class="text-sm text-gray-500 hover:text-gray-900">Back to campaign</a>
                <h1 class="mt-2 text-3xl font-bold text-gray-900">
                    <?= htmlspecialchars((string) $board['away_team']) ?> at <?= htmlspecialchars((string) $board['home_team']) ?>
                </h1>
                <p class="mt-1 text-sm text-gray-500">
                    <?= htmlspecialchars(strtoupper((string) $board['league'])) ?>
                    &middot; kickoff <?= htmlspecialchars(date('M j, Y g:i a', strtotime((string) $board['kickoff_at']))) ?>
                    &middot; <?= $claimedCount ?>/100 claimed
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span class="badge badge-neutral">board: <?= htmlspecialchars((string) $board['status']) ?></span>
                <span class="badge badge-neutral">game: <?= htmlspecialchars((string) $board['game_status']) ?></span>
                <span class="badge <?= $isManual ? 'badge-neutral' : 'badge-success' ?>">scores: <?= htmlspecialchars((string) $board['scores_source']) ?></span>
            </div>
        </div>

        <?php if ($notice !== ''): ?>
        <div class="alert alert-success mb-6 px-4 py-3"><?= htmlspecialchars($notice) ?></div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
        <div class="alert alert-error mb-6 px-4 py-3"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(340px,0.85fr)]">
            <div class="space-y-6">
                <div class="card">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 class="text-lg font-semibold text-gray-900">Grid</h2>
                        <div class="flex flex-wrap gap-2">
                            <a href="/admin/boards/<?= $boardId ?>/claims" class="btn btn-secondary btn-sm">Claims</a>
                            <a href="/admin/boards/<?= $boardId ?>/claims.csv" class="btn btn-secondary btn-sm">Export CSV</a>
                        </div>
                    </div>

                    <?php if (!$isLocked): ?>
                    <p class="mt-3 text-sm text-gray-500">
                        Numbers are hidden until the board locks. They are drawn at kickoff automatically, or lock it now.
                    </p>
                    <form method="POST" action="/admin/boards/<?= $boardId ?>/lock" class="mt-3">
                        <?= \Keel\Core\Csrf::field() ?>
                        <button type="submit" class="btn btn-primary btn-sm">Lock board and draw numbers</button>
                    </form>
                    <?php else: ?>
                    <p class="mt-3 text-sm text-gray-500">
                        Locked <?= htmlspecialchars(date('M j, Y g:i a', strtotime((string) $board['locked_at']))) ?>.
                        Rows are <?= htmlspecialchars((string) $board['home_team']) ?> (home), columns are <?= htmlspecialchars((string) $board['away_team']) ?> (away).
                    </p>
                    <?php endif; ?>

                    <div class="mt-4 overflow-x-auto">
                        <table class="squares-grid">
                            <thead>
                                <tr>
                                    <th class="bg-gray-100">H \ A</th>
                                    <?php for ($col = 0; $col < 10; $col++): ?>
                                    <th class="bg-gray-100"><?= $digits === null ? '?' : (int) $digits['col'][$col] ?></th>
                                    <?php endfor; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php for ($row = 0; $row < 10; $row++): ?>
                                <tr>
                                    <th class="bg-gray-100"><?= $digits === null ? '?' : (int) $digits['row'][$row] ?></th>
                                    <?php for ($col = 0; $col < 10; $col++): ?>
                                    <?php
                                    $square = $grid[$row][$col] ?? null;
                                    $taken = $square !== null && !empty($square['claim_id']);
                                    $winnerPeriod = $winnerCells[$row . '-' . $col] ?? null;
                                    $classes = $winnerPeriod !== null ? 'is-winner' : ($taken ? 'is-taken' : '');
                                    ?>
                                    <td class="<?= $classes ?>">
                                        <?php if ($winnerPeriod !== null): ?>
                                        <?= htmlspecialchars(strtoupper($winnerPeriod)) ?>
                                        <?php elseif ($taken): ?>
                                        <?= htmlspecialchars(substr((string) $square['first_name'], 0, 6)) ?>
                                        <?php endif; ?>
                                    </td>
                                    <?php endfor; ?>
                                </tr>
                                <?php endfor; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card">
                    <h2 class="text-lg font-semibold text-gray-900">Scores</h2>
                    <?php if ($isManual): ?>
                    <p class="mt-2 text-sm text-gray-500">This game is on manual scoring. The feed no longer writes to it.</p>
                    <?php else: ?>
                    <p class="mt-2 text-sm text-gray-500">
                        Scores sync from the feed<?= empty($board['last_synced_at']) ? '' : ' (last synced ' . htmlspecialchars(date('M j, g:i a', strtotime((string) $board['last_synced_at']))) . ')' ?>.
                        Saving here switches this game to manual permanently.
                    </p>
                    <?php endif; ?>

                    <form method="POST" action="/admin/boards/<?= $boardId ?>/scores" class="mt-4 space-y-4">
                        <?= \Keel\Core\Csrf::field() ?>

                        <div class="overflow-x-auto rounded-xl border border-gray-200">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Period</th>
                                        <th><?= htmlspecialchars((string) $board['home_team']) ?> (home)</th>
                                        <th><?= htmlspecialchars((string) $board['away_team']) ?> (away)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (\Keel\App\Models\Game::PERIOD_COLUMNS as $period => $columns): ?>
                                    <tr>
                                        <td class="font-medium text-gray-900"><?= htmlspecialchars(strtoupper($period)) ?></td>
                                        <td>
                                            <input type="number" min="0" name="<?= htmlspecialchars($columns[0]) ?>" class="form-input"
                                                   value="<?= $board[$columns[0]] === null ? '' : (int) $board[$columns[0]] ?>">
                                        </td>
                                        <td>
                                            <input type="number" min="0" name="<?= htmlspecialchars($columns[1]) ?>" class="form-input"
                                                   value="<?= $board[$columns[1]] === null ? '' : (int) $board[$columns[1]] ?>">
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="sm:max-w-xs">
                            <label class="form-label" for="game_status">Game status</label>
                            <select id="game_status" name="game_status" class="form-input">
                                <?php foreach ($gameStatuses as $gameStatus): ?>
                                <option value="<?= htmlspecialchars((string) $gameStatus) ?>" <?= $board['game_status'] === $gameStatus ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) $gameStatus) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="mt-1 text-xs text-gray-500">The final prize pays out only once the game status is final.</p>
                        </div>

                        <button type="submit" class="btn btn-danger btn-sm">Override scores</button>
                    </form>
                </div>
            </div>

            <div class="space-y-6">
                <div class="card">
                    <h2 class="text-lg font-semibold text-gray-900">Board settings</h2>
                    <form method="POST" action="/admin/boards/<?= $boardId ?>" class="mt-4 space-y-4">
                        <?= \Keel\Core\Csrf::field() ?>
                        <div>
                            <label class="form-label" for="claim_limit">Squares per person</label>
                            <input id="claim_limit" type="number" name="claim_limit" class="form-input" min="1" max="100"
                                   value="<?= (int) $board['claim_limit'] ?>">
                        </div>
                        <div>
                            <label class="form-label" for="status">Board status</label>
                            <select id="status" name="status" class="form-input">
                                <?php foreach ($statuses as $status): ?>
                                <option value="<?= htmlspecialchars((string) $status) ?>" <?= $board['status'] === $status ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) $status) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-secondary btn-sm">Save settings</button>
                    </form>
                </div>

                <div class="card">
                    <h2 class="text-lg font-semibold text-gray-900">Prizes</h2>
                    <p class="mt-1 text-sm text-gray-500">One offer per scoring period. Dealer-supplied service offers, no cash.</p>

                    <datalist id="prize-library-labels">
                        <?php foreach ($prizeLibrary as $libraryPrize): ?>
                        <option value="<?= htmlspecialchars((string) $libraryPrize['label']) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>

                    <?php foreach ($periods as $period): ?>
                    <?php $prize = $prizes[$period] ?? null; ?>
                    <form method="POST" action="/admin/boards/<?= $boardId ?>/prizes" class="mt-5 space-y-3 rounded-xl border border-gray-200 p-4">
                        <?= \Keel\Core\Csrf::field() ?>
                        <input type="hidden" name="scoring_period" value="<?= htmlspecialchars((string) $period) ?>">

                        <div class="flex items-center justify-between gap-2">
                            <h3 class="text-sm font-semibold text-gray-900"><?= htmlspecialchars($periodLabels[$period] ?? $period) ?></h3>
                            <?php if ($prize !== null): ?>
                            <span class="badge badge-success">set</span>
                            <?php endif; ?>
                        </div>

                        <div>
                            <label class="form-label" for="label-<?= htmlspecialchars((string) $period) ?>">Offer</label>
                            <input id="label-<?= htmlspecialchars((string) $period) ?>" type="text" name="label" class="form-input"
                                   list="prize-library-labels" maxlength="255"
                                   value="<?= htmlspecialchars((string) ($prize['label'] ?? '')) ?>">
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="form-label" for="value-<?= htmlspecialchars((string) $period) ?>">Retail value</label>
                                <input id="value-<?= htmlspecialchars((string) $period) ?>" type="number" step="0.01" min="0" name="retail_value"
                                       class="form-input" value="<?= htmlspecialchars((string) ($prize['retail_value'] ?? '')) ?>">
                            </div>
                            <div>
                                <label class="form-label" for="expires-<?= htmlspecialchars((string) $period) ?>">Expires (days)</label>
                                <input id="expires-<?= htmlspecialchars((string) $period) ?>" type="number" min="1" name="expires_days"
                                       class="form-input" value="<?= (int) ($prize['expires_days'] ?? 30) ?>">
                            </div>
                        </div>

                        <div>
                            <label class="form-label" for="terms-<?= htmlspecialchars((string) $period) ?>">Offer terms</label>
                            <textarea id="terms-<?= htmlspecialchars((string) $period) ?>" name="terms_text" rows="2" class="form-input"><?= htmlspecialchars((string) ($prize['terms_text'] ?? '')) ?></textarea>
                        </div>

                        <label class="flex items-center gap-2 text-xs text-gray-600">
                            <input type="checkbox" name="save_to_library" value="1">
                            Also save this offer to our prize library
                        </label>

                        <div class="flex gap-2">
                            <button type="submit" class="btn btn-primary btn-sm">Save prize</button>
                        </div>
                    </form>

                    <?php if ($prize !== null): ?>
                    <form method="POST" action="/admin/boards/<?= $boardId ?>/prizes/<?= htmlspecialchars((string) $period) ?>/delete" class="mt-2">
                        <?= \Keel\Core\Csrf::field() ?>
                        <button type="submit" class="text-xs text-gray-500 hover:text-gray-900">Remove <?= htmlspecialchars($periodLabels[$period] ?? $period) ?> prize</button>
                    </form>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </div>

                <div class="card">
                    <h2 class="text-lg font-semibold text-gray-900">Winners</h2>
                    <?php if ($wins === []): ?>
                    <p class="mt-2 text-sm text-gray-500">No periods resolved yet.</p>
                    <?php endif; ?>
                    <ul class="mt-3 space-y-3">
                        <?php foreach ($wins as $win): ?>
                        <li class="rounded-xl border border-gray-200 p-3">
                            <p class="text-sm font-medium text-gray-900">
                                <?= htmlspecialchars(strtoupper((string) $win['scoring_period'])) ?>:
                                <?= htmlspecialchars((string) $win['first_name'] . ' ' . (string) $win['last_name']) ?>
                            </p>
                            <p class="text-xs text-gray-500">
                                <?= htmlspecialchars((string) $win['prize_label']) ?>
                                &middot; code <span class="font-mono font-semibold"><?= htmlspecialchars((string) $win['redemption_code']) ?></span>
                                &middot; <?= empty($win['redeemed_at']) ? 'unredeemed' : 'redeemed' ?>
                            </p>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <a href="/admin/wins?board_id=<?= $boardId ?>" class="mt-3 inline-block text-sm text-gray-600 hover:underline">Manage redemptions</a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
