<?php
$campaign = $campaign ?? [];
$board = $board ?? [];
$squares = $squares ?? [];
$digits = $digits ?? null;
$prizes = $prizes ?? [];
$periods = $periods ?? [];
$periodLabels = $periodLabels ?? [];
$scores = $scores ?? [];
$winners = $winners ?? [];
$claimsOpen = (bool) ($claimsOpen ?? false);
$availableCount = (int) ($availableCount ?? 0);
$logoUrl = $logoUrl ?? null;
$brandColor = (string) ($brandColor ?? '#111827');
$brandContrast = (string) ($brandContrast ?? '#ffffff');
$notice = (string) ($notice ?? '');
$error = (string) ($error ?? '');
$claimedCells = $claimedCells ?? [];
$takenCells = $takenCells ?? [];
$selectedCells = $selectedCells ?? [];

$slug = (string) ($campaign['public_slug'] ?? '');
$boardId = (int) ($board['id'] ?? 0);
$claimLimit = (int) ($board['claim_limit'] ?? 5);
$isLocked = !empty($board['locked_at']);
$homeTeam = (string) ($board['home_team'] ?? '');
$awayTeam = (string) ($board['away_team'] ?? '');
$kickoffAt = strtotime((string) ($board['kickoff_at'] ?? 'now')) ?: time();

$assetVersion = static function (string $relativePath): string {
    $absolute = dirname(__DIR__, 2) . '/public_html' . $relativePath;
    $modified = is_file($absolute) ? (int) filemtime($absolute) : 0;

    return $relativePath . ($modified > 0 ? '?v=' . $modified : '');
};

$winnerCells = [];
foreach ($winners as $period => $winner) {
    $winnerCells[$winner['row'] . '-' . $winner['col']] = (string) $period;
}

$prizesSet = array_filter($periods, static fn (string $period): bool => isset($prizes[$period]));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require __DIR__ . '/../partials/head.php'; ?>
<link rel="stylesheet" href="<?= htmlspecialchars($assetVersion('/assets/css/board.css')) ?>">
<script src="<?= htmlspecialchars($assetVersion('/assets/js/board.js')) ?>" defer></script>
</head>
<body class="claim-page" style="--dealer-brand: <?= htmlspecialchars($brandColor, ENT_QUOTES) ?>; --dealer-brand-contrast: <?= htmlspecialchars($brandContrast, ENT_QUOTES) ?>;">
<main class="claim-shell">

    <header class="claim-header<?= $logoUrl === null ? ' claim-header--plain' : '' ?>">
        <?php if ($logoUrl !== null): ?>
        <img class="claim-logo" src="<?= htmlspecialchars($logoUrl) ?>" alt="<?= htmlspecialchars((string) $campaign['name']) ?>">
        <?php endif; ?>
        <h1 class="claim-title"><?= htmlspecialchars((string) $campaign['name']) ?></h1>
        <p class="claim-matchup">
            <?= htmlspecialchars($awayTeam) ?> at <?= htmlspecialchars($homeTeam) ?>
            &middot; <?= htmlspecialchars(date('D M j, g:i a', $kickoffAt)) ?>
        </p>
    </header>

    <!-- Required disclosure: always rendered, always above the grid. -->
    <p class="claim-disclosure">No purchase necessary. Free to enter.</p>

    <?php if ($notice !== ''): ?>
    <p class="claim-alert claim-alert--ok">
        <?= htmlspecialchars($notice) ?>
        <?php if ($claimedCells !== []): ?>
        <span class="claim-alert__detail">Your squares: <?= htmlspecialchars(implode(', ', $claimedCells)) ?></span>
        <?php endif; ?>
    </p>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
    <p class="claim-alert claim-alert--error">
        <?= htmlspecialchars($error) ?>
        <?php if ($selectedCells !== []): ?>
        <span class="claim-alert__detail">Still selected: <?= htmlspecialchars(implode(', ', $selectedCells)) ?>. Submit again to claim them.</span>
        <?php endif; ?>
    </p>
    <?php endif; ?>

    <!-- Filled in by board.js; stays out of the flow when JS is off. -->
    <p class="claim-alert" data-live-alert hidden>
        <span data-live-alert-text></span>
        <span class="claim-alert__detail" data-live-alert-detail></span>
    </p>

    <form class="claim-form" id="claim-form" method="POST" action="/p/<?= htmlspecialchars(rawurlencode($slug)) ?>/claim">
        <?= \Keel\Core\Csrf::field() ?>
        <input type="hidden" name="board" value="<?= $boardId ?>">

        <section class="claim-card">
            <div class="board-head">
                <h2 class="claim-card__title">
                    <?= $claimsOpen ? 'Tap your squares' : 'The board' ?>
                </h2>
                <p class="board-counter">
                    <span data-available><?= $availableCount ?></span> of 100 open
                    <?php if ($claimsOpen): ?>
                    &middot; <span data-selected-count>0</span>/<?= $claimLimit ?> picked
                    <?php endif; ?>
                </p>
            </div>

            <div class="board-scroll">
                <table class="board-grid"
                       data-board
                       data-form-id="claim-form"
                       data-slug="<?= htmlspecialchars($slug) ?>"
                       data-board-id="<?= $boardId ?>"
                       data-claim-limit="<?= $claimLimit ?>"
                       data-claims-open="<?= $claimsOpen ? '1' : '0' ?>"
                       data-locked="<?= $isLocked ? '1' : '0' ?>"
                       data-mine="<?= htmlspecialchars(implode(' ', $claimedCells)) ?>">
                    <colgroup>
                        <col class="board-grid__axis-col">
                    </colgroup>
                    <caption class="board-legend">
                        Rows are <?= htmlspecialchars($homeTeam) ?> (home), columns are <?= htmlspecialchars($awayTeam) ?> (away).
                        <?= $isLocked ? 'Numbers are drawn.' : 'Numbers are hidden until kickoff.' ?>
                    </caption>
                    <thead>
                        <tr>
                            <th scope="col"></th>
                            <?php for ($col = 0; $col < 10; $col++): ?>
                            <!-- Blank before the lock: the digit does not exist yet. -->
                            <th scope="col" data-col-digit="<?= $col ?>"><?= $digits === null ? '' : (int) $digits['col'][$col] ?></th>
                            <?php endfor; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php for ($row = 0; $row < 10; $row++): ?>
                        <tr>
                            <th scope="row" data-row-digit="<?= $row ?>"><?= $digits === null ? '' : (int) $digits['row'][$row] ?></th>
                            <?php for ($col = 0; $col < 10; $col++): ?>
                            <?php
                            $key = $row . '-' . $col;
                            $cell = $squares[$row][$col] ?? ['taken' => false, 'name' => ''];
                            $winnerPeriod = $winnerCells[$key] ?? null;
                            $isMine = in_array($key, $claimedCells, true);
                            $isConflict = in_array($key, $takenCells, true);
                            $isSelected = in_array($key, $selectedCells, true);

                            $classes = 'square';
                            if ($winnerPeriod !== null) {
                                $classes .= ' square--winner';
                            } elseif ($isMine) {
                                $classes .= ' square--mine';
                            } elseif ($cell['taken']) {
                                $classes .= ' square--taken';
                            }
                            if ($isSelected) {
                                $classes .= ' square--selected';
                            }
                            if ($isConflict) {
                                $classes .= ' square--conflict';
                            }

                            $label = 'Square row ' . $row . ' column ' . $col;
                            if ($winnerPeriod !== null) {
                                $label .= ', winner';
                            } elseif ($cell['taken']) {
                                $label .= ', taken';
                            }
                            ?>
                            <td>
                                <label class="<?= $classes ?>" data-cell="<?= $key ?>">
                                    <input type="checkbox" name="squares[]" value="<?= $key ?>"
                                           aria-label="<?= htmlspecialchars($label) ?>"
                                           <?= $isSelected ? 'checked' : '' ?>
                                           <?= ($cell['taken'] || !$claimsOpen) ? 'disabled' : '' ?>>
                                    <span data-cell-label><?= $winnerPeriod !== null ? htmlspecialchars(strtoupper($winnerPeriod)) : htmlspecialchars((string) $cell['name']) ?></span>
                                </label>
                            </td>
                            <?php endfor; ?>
                        </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <?php if ($claimsOpen): ?>
        <section class="claim-card claim-fields">
            <h2 class="claim-card__title">Your details</h2>
            <p class="claim-card__note">Free entry. We never ask for payment information.</p>

            <div class="field-grid">
                <div class="field">
                    <label for="first_name">First name</label>
                    <input id="first_name" type="text" name="first_name" maxlength="80" autocomplete="given-name" required>
                </div>
                <div class="field">
                    <label for="last_name">Last name</label>
                    <input id="last_name" type="text" name="last_name" maxlength="80" autocomplete="family-name" required>
                </div>
                <div class="field">
                    <label for="email">Email</label>
                    <input id="email" type="email" name="email" maxlength="255" autocomplete="email" inputmode="email" required>
                </div>
                <div class="field">
                    <label for="phone">Mobile phone</label>
                    <input id="phone" type="tel" name="phone" maxlength="32" autocomplete="tel" inputmode="tel" required>
                </div>
            </div>

            <fieldset class="consent">
                <legend>How should we reach you if you win?</legend>

                <label class="consent__option">
                    <input type="checkbox" name="consent_email" value="1" checked>
                    <span>
                        Email me about this game. Up to 5 emails per game: entry confirmation,
                        quarter results, and my prize code if I win. Unsubscribe any time.
                    </span>
                </label>

                <label class="consent__option">
                    <input type="checkbox" name="consent_sms" value="1">
                    <span>
                        Text me about this game at the number above. Up to 5 messages per game.
                        Message and data rates may apply. Message frequency varies. Reply STOP to
                        opt out or HELP for help. Consent is not a condition of entry or of any purchase.
                    </span>
                </label>

                <p class="claim-card__note">Pick at least one so we can deliver your prize code.</p>
            </fieldset>

            <button type="submit" class="claim-submit" data-submit>Claim my squares</button>
        </section>
        <?php else: ?>
        <!-- Board locked or closed: the entry form is replaced by live status. -->
        <section class="claim-card board-status">
            <div class="board-head">
                <h2 class="claim-card__title">Board status</h2>
                <span class="status-badge"><?= htmlspecialchars((string) $board['status']) ?></span>
            </div>

            <p class="claim-card__note">
                <?php if ($isLocked): ?>
                Entries are closed and the numbers have been drawn. Winners are posted here as each period ends.
                <?php else: ?>
                Entries are closed for this board.
                <?php endif; ?>
            </p>

            <ul class="status-list">
                <li class="status-row">
                    <span class="status-row__label">Kickoff</span>
                    <span class="status-row__value"><?= htmlspecialchars(date('D M j, g:i a', $kickoffAt)) ?></span>
                </li>
                <li class="status-row">
                    <span class="status-row__label">Squares claimed</span>
                    <span class="status-row__value"><?= 100 - $availableCount ?> of 100</span>
                </li>
                <?php foreach ($periods as $period): ?>
                <?php $score = $scores[$period] ?? null; ?>
                <li class="status-row">
                    <span class="status-row__label"><?= htmlspecialchars($periodLabels[$period] ?? $period) ?></span>
                    <span class="status-row__value">
                        <?php if ($score === null): ?>
                        &mdash;
                        <?php else: ?>
                        <?= (int) $score['away'] ?>&ndash;<?= (int) $score['home'] ?>
                        <?php endif; ?>
                        <?php if (isset($winners[$period])): ?>
                        &middot; <?= htmlspecialchars($winners[$period]['name']) ?>
                        <?php endif; ?>
                    </span>
                </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>
    </form>

    <?php if ($prizesSet !== []): ?>
    <section class="claim-card">
        <h2 class="claim-card__title">What you can win</h2>
        <p class="claim-card__note">Dealer-supplied service offers. No cash value, no purchase required.</p>

        <ul class="prize-list">
            <?php foreach ($prizesSet as $period): ?>
            <?php $prize = $prizes[$period]; ?>
            <li class="prize">
                <p class="prize__period"><?= htmlspecialchars($periodLabels[$period] ?? $period) ?></p>
                <p class="prize__label"><?= htmlspecialchars((string) $prize['label']) ?></p>
                <?php if ($prize['retail_value'] !== null && $prize['retail_value'] !== ''): ?>
                <p class="prize__value">Retail value $<?= htmlspecialchars(number_format((float) $prize['retail_value'], 2)) ?></p>
                <?php endif; ?>
                <p class="prize__value">Redeem within <?= (int) $prize['expires_days'] ?> days of winning.</p>
                <?php if (!empty($prize['terms_text'])): ?>
                <p class="prize__terms"><?= htmlspecialchars((string) $prize['terms_text']) ?></p>
                <?php endif; ?>
                <?php if (isset($winners[$period])): ?>
                <p class="prize__winner">Won by <?= htmlspecialchars($winners[$period]['name']) ?></p>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <?php if (!empty($campaign['terms_text'])): ?>
    <section class="claim-card rules">
        <h2 class="claim-card__title">Official rules</h2>
        <p class="rules__body"><?= htmlspecialchars((string) $campaign['terms_text']) ?></p>
    </section>
    <?php endif; ?>

    <p class="claim-footer">
        No purchase necessary. Free to enter. Prizes are dealer-supplied service offers with no cash value.
    </p>
</main>
</body>
</html>
