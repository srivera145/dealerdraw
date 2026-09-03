<?php
$wins = $wins ?? [];
$periodLabels = $periodLabels ?? [];
$notice = (string) ($notice ?? '');
$error = (string) ($error ?? '');
?>
<!DOCTYPE html>
<html lang="en"<?= \Keel\Core\Theme::htmlAttribute() ?>>
<head>
<?php require __DIR__ . '/../../partials/head.php'; ?>
</head>
<body class="bg-gray-50 min-h-screen">
    <div class="mx-auto max-w-6xl px-4 py-10">
        <?php require __DIR__ . '/../_nav.php'; ?>

        <div class="mb-8">
            <p class="text-sm text-gray-500">Redemptions</p>
            <h1 class="text-3xl font-bold text-gray-900">Winners</h1>
            <p class="mt-1 text-sm text-gray-500">Advisors mark a code redeemed once the customer takes the offer.</p>
        </div>

        <?php if ($notice !== ''): ?>
        <div class="alert alert-success mb-6 px-4 py-3"><?= htmlspecialchars($notice) ?></div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
        <div class="alert alert-error mb-6 px-4 py-3"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="overflow-x-auto rounded-xl border border-gray-200">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Winner</th>
                            <th>Prize</th>
                            <th>Period</th>
                            <th>Game</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($wins === []): ?>
                        <tr>
                            <td colspan="7" class="p-6">
                                <div class="empty-state">
                                    <p class="empty-state-title">No winners yet</p>
                                    <p class="empty-state-text">Winners appear here as each scoring period resolves.</p>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>

                        <?php foreach ($wins as $win): ?>
                        <tr>
                            <td class="font-mono font-semibold text-gray-900"><?= htmlspecialchars((string) $win['redemption_code']) ?></td>
                            <td>
                                <div class="font-medium text-gray-900"><?= htmlspecialchars((string) $win['first_name'] . ' ' . (string) $win['last_name']) ?></div>
                                <div class="text-xs text-gray-500"><?= htmlspecialchars((string) $win['email']) ?> &middot; <?= htmlspecialchars((string) $win['phone']) ?></div>
                            </td>
                            <td>
                                <div><?= htmlspecialchars((string) $win['prize_label']) ?></div>
                                <div class="text-xs text-gray-500">expires <?= (int) $win['expires_days'] ?> days after award</div>
                            </td>
                            <td><?= htmlspecialchars($periodLabels[$win['scoring_period']] ?? (string) $win['scoring_period']) ?></td>
                            <td class="text-xs">
                                <?= htmlspecialchars((string) $win['away_team']) ?> at <?= htmlspecialchars((string) $win['home_team']) ?><br>
                                <span class="text-gray-500">square <?= (int) $win['row_index'] ?>-<?= (int) $win['col_index'] ?></span>
                            </td>
                            <td>
                                <?php if (empty($win['redeemed_at'])): ?>
                                <span class="badge badge-neutral">unredeemed</span>
                                <?php else: ?>
                                <span class="badge badge-success">redeemed</span>
                                <div class="text-xs text-gray-500">
                                    <?= htmlspecialchars(date('M j, g:i a', strtotime((string) $win['redeemed_at']))) ?>
                                    <?= empty($win['redeemed_by_email']) ? '' : ' by ' . htmlspecialchars((string) $win['redeemed_by_email']) ?>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (empty($win['redeemed_at'])): ?>
                                <form method="POST" action="/admin/wins/<?= (int) $win['id'] ?>/redeem">
                                    <?= \Keel\Core\Csrf::field() ?>
                                    <button type="submit" class="btn btn-primary btn-sm">Mark redeemed</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
