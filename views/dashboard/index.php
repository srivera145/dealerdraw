<!DOCTYPE html>
<html lang="en"<?= \Keel\Core\Theme::htmlAttribute() ?>>
<head>
<?php require __DIR__ . '/../partials/head.php'; ?>
</head>
<body class="bg-gray-50 min-h-screen">
    <div class="max-w-3xl mx-auto px-4 py-10">
        <div class="flex items-center justify-between mb-8">
            <h1 class="text-xl font-bold text-gray-900">Dashboard</h1>
            <form method="POST" action="/logout">
                <?= \Keel\Core\Csrf::field() ?>
                <button type="submit" class="text-sm text-gray-500 hover:text-gray-900">Sign out</button>
            </form>
        </div>
        <div class="card rounded-xl">
            <p class="text-sm text-gray-500">Signed in as</p>
            <p class="text-base font-medium text-gray-900"><?= htmlspecialchars($user['email'] ?? '') ?></p>
        </div>

        <div class="card mt-6 rounded-xl">
            <h2 class="text-lg font-semibold text-gray-900">Promotional games</h2>
            <p class="mt-1 text-sm text-gray-500">Run football squares boards and hand out redemption codes.</p>
            <div class="mt-4 flex flex-wrap gap-2">
                <a href="/admin/campaigns" class="btn btn-primary btn-sm">Campaigns</a>
                <a href="/admin/wins" class="btn btn-secondary btn-sm">Winners</a>
            </div>
        </div>
    </div>
</body>
</html>
