<?php
$campaign = $campaign ?? [];
$games = $games ?? [];
$leagues = $leagues ?? [];
$defaultClaimLimit = (int) ($defaultClaimLimit ?? 5);
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
            <a href="/admin/campaigns/<?= (int) $campaign['id'] ?>/edit" class="text-sm text-gray-500 hover:text-gray-900">Back to campaign</a>
            <h1 class="mt-2 text-3xl font-bold text-gray-900">New board</h1>
            <p class="mt-1 text-sm text-gray-500"><?= htmlspecialchars((string) $campaign['name']) ?></p>
        </div>

        <?php if ($error !== ''): ?>
        <div class="alert alert-error mb-6 px-4 py-3"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="card">
            <form method="POST" action="/admin/campaigns/<?= (int) $campaign['id'] ?>/boards" class="space-y-6">
                <?= \Keel\Core\Csrf::field() ?>

                <div>
                    <label class="form-label" for="game_id">Existing game</label>
                    <select id="game_id" name="game_id" class="form-input">
                        <option value="0">Enter a new game below</option>
                        <?php foreach ($games as $game): ?>
                        <option value="<?= (int) $game['id'] ?>">
                            <?= htmlspecialchars(strtoupper((string) $game['league'])) ?>
                            &middot; <?= htmlspecialchars((string) $game['away_team']) ?> at <?= htmlspecialchars((string) $game['home_team']) ?>
                            &middot; <?= htmlspecialchars(date('M j, Y g:i a', strtotime((string) $game['kickoff_at']))) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <fieldset class="rounded-xl border border-gray-200 p-4">
                    <legend class="px-2 text-sm font-semibold text-gray-700">Or add a new game</legend>

                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <label class="form-label" for="league">League</label>
                            <select id="league" name="league" class="form-input">
                                <?php foreach ($leagues as $league): ?>
                                <option value="<?= htmlspecialchars((string) $league) ?>"><?= htmlspecialchars(strtoupper((string) $league)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="form-label" for="kickoff_at">Kickoff</label>
                            <input id="kickoff_at" type="datetime-local" name="kickoff_at" class="form-input">
                        </div>
                        <div>
                            <label class="form-label" for="away_team">Away team</label>
                            <input id="away_team" type="text" name="away_team" class="form-input" maxlength="100">
                        </div>
                        <div>
                            <label class="form-label" for="home_team">Home team</label>
                            <input id="home_team" type="text" name="home_team" class="form-input" maxlength="100">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="form-label" for="external_id">Feed game id</label>
                            <input id="external_id" type="text" name="external_id" class="form-input" maxlength="64"
                                   placeholder="Leave blank to score this game by hand">
                            <p class="mt-1 text-xs text-gray-500">Without a feed id the game is scored manually.</p>
                        </div>
                    </div>
                </fieldset>

                <div>
                    <label class="form-label" for="claim_limit">Squares per person</label>
                    <input id="claim_limit" type="number" name="claim_limit" class="form-input" min="1" max="100"
                           value="<?= $defaultClaimLimit ?>">
                </div>

                <p class="text-xs text-gray-500">
                    Rows carry the home team digit, columns the away team digit. Numbers are drawn at kickoff, not now.
                </p>

                <button type="submit" class="btn btn-primary btn-md">Create board</button>
            </form>
        </div>
    </div>
</body>
</html>
