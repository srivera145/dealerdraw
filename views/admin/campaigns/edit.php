<?php
$campaign = $campaign ?? [];
$boards = $boards ?? [];
$statuses = $statuses ?? [];
$notice = (string) ($notice ?? '');
$error = (string) ($error ?? '');
$appUrl = rtrim((string) \Keel\Core\Env::get('APP_URL', ''), '/');
$publicUrl = $appUrl . '/p/' . (string) ($campaign['public_slug'] ?? '');

$toLocalInput = static function (?string $value): string {
    if ($value === null || trim($value) === '') {
        return '';
    }

    $timestamp = strtotime($value);

    return $timestamp === false ? '' : date('Y-m-d\TH:i', $timestamp);
};
?>
<!DOCTYPE html>
<html lang="en"<?= \Keel\Core\Theme::htmlAttribute() ?>>
<head>
<?php require __DIR__ . '/../../partials/head.php'; ?>
</head>
<body class="bg-gray-50 min-h-screen">
    <div class="mx-auto max-w-5xl px-4 py-10">
        <?php require __DIR__ . '/../_nav.php'; ?>

        <div class="mb-8">
            <a href="/admin/campaigns" class="text-sm text-gray-500 hover:text-gray-900">Back to campaigns</a>
            <h1 class="mt-2 text-3xl font-bold text-gray-900"><?= htmlspecialchars((string) $campaign['name']) ?></h1>
            <p class="mt-1 text-sm text-gray-500">
                Public claim page:
                <a class="hover:underline" href="/p/<?= htmlspecialchars((string) $campaign['public_slug']) ?>"><?= htmlspecialchars($publicUrl) ?></a>
            </p>
        </div>

        <?php if ($notice !== ''): ?>
        <div class="alert alert-success mb-6 px-4 py-3"><?= htmlspecialchars($notice) ?></div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
        <div class="alert alert-error mb-6 px-4 py-3"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(320px,0.8fr)]">
            <div class="card">
                <h2 class="text-lg font-semibold text-gray-900">Campaign details</h2>
                <form method="POST" action="/admin/campaigns/<?= (int) $campaign['id'] ?>" enctype="multipart/form-data" class="mt-4 space-y-5">
                    <?= \Keel\Core\Csrf::field() ?>

                    <div>
                        <label class="form-label" for="name">Campaign name</label>
                        <input id="name" type="text" name="name" class="form-input" required maxlength="255"
                               value="<?= htmlspecialchars((string) $campaign['name']) ?>">
                    </div>

                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <label class="form-label" for="status">Status</label>
                            <select id="status" name="status" class="form-input">
                                <?php foreach ($statuses as $status): ?>
                                <option value="<?= htmlspecialchars((string) $status) ?>" <?= $campaign['status'] === $status ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) $status) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="form-label" for="brand_primary_color">Brand color</label>
                            <input id="brand_primary_color" type="color" name="brand_primary_color" class="form-input h-11"
                                   value="<?= htmlspecialchars((string) ($campaign['brand_primary_color'] ?? '#111827')) ?>">
                        </div>
                    </div>

                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <label class="form-label" for="starts_at">Starts</label>
                            <input id="starts_at" type="datetime-local" name="starts_at" class="form-input"
                                   value="<?= htmlspecialchars($toLocalInput($campaign['starts_at'] ?? null)) ?>">
                        </div>
                        <div>
                            <label class="form-label" for="ends_at">Ends</label>
                            <input id="ends_at" type="datetime-local" name="ends_at" class="form-input"
                                   value="<?= htmlspecialchars($toLocalInput($campaign['ends_at'] ?? null)) ?>">
                        </div>
                    </div>

                    <div>
                        <label class="form-label" for="brand_logo">Dealer logo</label>
                        <input id="brand_logo" type="file" name="brand_logo" class="form-input" accept="image/png,image/jpeg">
                        <?php if (!empty($campaign['brand_logo_path'])): ?>
                        <p class="mt-1 text-xs text-gray-500">Current: <?= htmlspecialchars(\Keel\Core\Storage::url((string) $campaign['brand_logo_path'])) ?></p>
                        <?php endif; ?>
                    </div>

                    <div>
                        <label class="form-label" for="terms_text">Official rules / terms</label>
                        <textarea id="terms_text" name="terms_text" rows="6" class="form-input"><?= htmlspecialchars((string) ($campaign['terms_text'] ?? '')) ?></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary btn-md">Save campaign</button>
                </form>
            </div>

            <div class="card">
                <div class="flex items-center justify-between gap-4">
                    <h2 class="text-lg font-semibold text-gray-900">Boards</h2>
                    <a href="/admin/campaigns/<?= (int) $campaign['id'] ?>/boards/create" class="btn btn-secondary btn-sm">Add board</a>
                </div>

                <?php if ($boards === []): ?>
                <p class="mt-4 text-sm text-gray-500">No boards yet. Add one for the game you want to run.</p>
                <?php endif; ?>

                <ul class="mt-4 space-y-3">
                    <?php foreach ($boards as $board): ?>
                    <li class="rounded-xl border border-gray-200 p-4">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="font-medium text-gray-900">
                                    <?= htmlspecialchars((string) $board['away_team']) ?> at <?= htmlspecialchars((string) $board['home_team']) ?>
                                </p>
                                <p class="text-xs text-gray-500">
                                    <?= htmlspecialchars(strtoupper((string) $board['league'])) ?>
                                    &middot; <?= htmlspecialchars(date('M j, Y g:i a', strtotime((string) $board['kickoff_at']))) ?>
                                    &middot; <?= (int) $board['claimed_count'] ?>/100 claimed
                                </p>
                            </div>
                            <span class="badge badge-neutral"><?= htmlspecialchars((string) $board['status']) ?></span>
                        </div>
                        <a href="/admin/boards/<?= (int) $board['id'] ?>/edit" class="mt-3 inline-block text-sm text-gray-600 hover:underline">Manage board</a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</body>
</html>
