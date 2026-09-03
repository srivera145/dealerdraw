<?php
$board = $board ?? [];
$claims = $claims ?? [];
$boardId = (int) ($board['id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en"<?= \Keel\Core\Theme::htmlAttribute() ?>>
<head>
<?php require __DIR__ . '/../../partials/head.php'; ?>
</head>
<body class="bg-gray-50 min-h-screen">
    <div class="mx-auto max-w-6xl px-4 py-10">
        <?php require __DIR__ . '/../_nav.php'; ?>

        <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
            <div>
                <a href="/admin/boards/<?= $boardId ?>/edit" class="text-sm text-gray-500 hover:text-gray-900">Back to board</a>
                <h1 class="mt-2 text-3xl font-bold text-gray-900">Claims</h1>
                <p class="mt-1 text-sm text-gray-500">
                    <?= htmlspecialchars((string) $board['away_team']) ?> at <?= htmlspecialchars((string) $board['home_team']) ?>
                    &middot; <?= count($claims) ?> entries
                </p>
            </div>
            <a href="/admin/boards/<?= $boardId ?>/claims.csv" class="btn btn-primary btn-md">Export CSV</a>
        </div>

        <div class="card">
            <div class="overflow-x-auto rounded-xl border border-gray-200">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Claimed</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Squares</th>
                            <th>Consent</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($claims === []): ?>
                        <tr>
                            <td colspan="6" class="p-6">
                                <div class="empty-state">
                                    <p class="empty-state-title">No entries yet</p>
                                    <p class="empty-state-text">Share the campaign claim link to start collecting entries.</p>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>

                        <?php foreach ($claims as $claim): ?>
                        <tr>
                            <td class="whitespace-nowrap"><?= htmlspecialchars(date('M j, g:i a', strtotime((string) $claim['created_at']))) ?></td>
                            <td class="font-medium text-gray-900"><?= htmlspecialchars((string) $claim['first_name'] . ' ' . (string) $claim['last_name']) ?></td>
                            <td><?= htmlspecialchars((string) $claim['email']) ?></td>
                            <td><?= htmlspecialchars((string) $claim['phone']) ?></td>
                            <td>
                                <span class="font-medium"><?= (int) $claim['square_count'] ?></span>
                                <span class="text-xs text-gray-500"><?= htmlspecialchars((string) ($claim['square_cells'] ?? '')) ?></span>
                            </td>
                            <td class="text-xs">
                                <?= (int) $claim['consent_email'] === 1 ? 'email' : '' ?>
                                <?= (int) $claim['consent_sms'] === 1 ? 'sms' : '' ?>
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
