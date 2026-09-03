<?php
$campaigns = $campaigns ?? [];
$notice = (string) ($notice ?? '');
$error = (string) ($error ?? '');
$appUrl = rtrim((string) \Keel\Core\Env::get('APP_URL', ''), '/');
?>
<!DOCTYPE html>
<html lang="en"<?= \Keel\Core\Theme::htmlAttribute() ?>>
<head>
<?php require __DIR__ . '/../../partials/head.php'; ?>
</head>
<body class="bg-gray-50 min-h-screen">
    <div class="mx-auto max-w-5xl px-4 py-10">
        <?php require __DIR__ . '/../_nav.php'; ?>

        <div class="mb-8 flex items-center justify-between gap-4">
            <div>
                <p class="text-sm text-gray-500">Promotions</p>
                <h1 class="text-3xl font-bold text-gray-900">Campaigns</h1>
            </div>
            <a href="/admin/campaigns/create" class="btn btn-primary btn-md">New campaign</a>
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
                            <th>Campaign</th>
                            <th>Game type</th>
                            <th>Status</th>
                            <th>Boards</th>
                            <th>Public link</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($campaigns === []): ?>
                        <tr>
                            <td colspan="5" class="p-6">
                                <div class="empty-state">
                                    <p class="empty-state-title">No campaigns yet</p>
                                    <p class="empty-state-text">Create a campaign, add a board for a game, then share the claim link.</p>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>

                        <?php foreach ($campaigns as $campaign): ?>
                        <tr>
                            <td class="font-medium text-gray-900">
                                <a href="/admin/campaigns/<?= (int) $campaign['id'] ?>/edit" class="hover:underline">
                                    <?= htmlspecialchars((string) $campaign['name']) ?>
                                </a>
                            </td>
                            <td><?= htmlspecialchars((string) ($campaign['campaign_type_name'] ?? '')) ?></td>
                            <td><span class="badge badge-neutral"><?= htmlspecialchars((string) $campaign['status']) ?></span></td>
                            <td><?= (int) ($campaign['board_count'] ?? 0) ?></td>
                            <td>
                                <a class="text-sm text-gray-600 hover:underline"
                                   href="/p/<?= htmlspecialchars((string) $campaign['public_slug']) ?>">
                                    <?= htmlspecialchars($appUrl . '/p/' . (string) $campaign['public_slug']) ?>
                                </a>
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
