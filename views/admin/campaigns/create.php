<?php
$campaignTypes = $campaignTypes ?? [];
$statuses = $statuses ?? [];
$error = (string) ($error ?? '');
?>
<!DOCTYPE html>
<html lang="en"<?= \Keel\Core\Theme::htmlAttribute() ?>>
<head>
<?php require __DIR__ . '/../../partials/head.php'; ?>
</head>
<body class="bg-gray-50 min-h-screen">
    <div class="mx-auto max-w-3xl px-4 py-10">
        <?php require __DIR__ . '/../_nav.php'; ?>

        <div class="mb-8">
            <a href="/admin/campaigns" class="text-sm text-gray-500 hover:text-gray-900">Back to campaigns</a>
            <h1 class="mt-2 text-3xl font-bold text-gray-900">New campaign</h1>
        </div>

        <?php if ($error !== ''): ?>
        <div class="alert alert-error mb-6 px-4 py-3"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="card">
            <form method="POST" action="/admin/campaigns" enctype="multipart/form-data" class="space-y-5">
                <?= \Keel\Core\Csrf::field() ?>

                <div>
                    <label class="form-label" for="name">Campaign name</label>
                    <input id="name" type="text" name="name" class="form-input" required maxlength="255"
                           placeholder="Sunday Night Squares">
                </div>

                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label class="form-label" for="campaign_type_id">Game type</label>
                        <select id="campaign_type_id" name="campaign_type_id" class="form-input" required>
                            <?php foreach ($campaignTypes as $campaignType): ?>
                            <option value="<?= (int) $campaignType['id'] ?>"><?= htmlspecialchars((string) $campaignType['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label" for="status">Status</label>
                        <select id="status" name="status" class="form-input">
                            <?php foreach ($statuses as $status): ?>
                            <option value="<?= htmlspecialchars((string) $status) ?>"><?= htmlspecialchars((string) $status) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="mt-1 text-xs text-gray-500">The claim page is live only while the campaign is active.</p>
                    </div>
                </div>

                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label class="form-label" for="starts_at">Starts</label>
                        <input id="starts_at" type="datetime-local" name="starts_at" class="form-input">
                    </div>
                    <div>
                        <label class="form-label" for="ends_at">Ends</label>
                        <input id="ends_at" type="datetime-local" name="ends_at" class="form-input">
                    </div>
                </div>

                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label class="form-label" for="brand_primary_color">Brand color</label>
                        <input id="brand_primary_color" type="color" name="brand_primary_color" class="form-input h-11" value="#111827">
                    </div>
                    <div>
                        <label class="form-label" for="brand_logo">Dealer logo</label>
                        <input id="brand_logo" type="file" name="brand_logo" class="form-input" accept="image/png,image/jpeg">
                    </div>
                </div>

                <div>
                    <label class="form-label" for="terms_text">Official rules / terms</label>
                    <textarea id="terms_text" name="terms_text" rows="5" class="form-input"
                              placeholder="No purchase necessary. Free to enter. Open to legal residents 18+..."></textarea>
                </div>

                <button type="submit" class="btn btn-primary btn-md">Create campaign</button>
            </form>
        </div>
    </div>
</body>
</html>
