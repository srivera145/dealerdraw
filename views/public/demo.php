<?php
$board = $board ?? ['home_team' => '', 'away_team' => '', 'claim_limit' => 5, 'squares' => [], 'taken' => 0];
$submitted = (bool) ($submitted ?? false);
$available = 100 - (int) $board['taken'];

$pageTitle = 'A live DealerDraw board - tap through it, nothing is saved';
$pageDescription = 'A working sample DealerDraw board. Tap the open squares to see what your customers see. '
    . 'It is a demo, so nothing is stored and no account is needed.';
$canonicalPath = '/demo';
// Indexable: it is a real product page with explanatory copy, and it is listed
// in the sitemap. The sample entrants are labelled as such on the page.
$schemaTypes = ['organization', 'software'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require __DIR__ . '/_head.php'; ?>
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>

<?php require __DIR__ . '/_nav.php'; ?>

<main id="main" class="section">
    <div class="shell">
        <p class="eyebrow">Sample board</p>
        <h1>This is what your customer sees.</h1>

        <p class="demo-note">
            <strong>Demo board.</strong> The names are made up and nothing you do here is saved -
            there is no account, no database, and no way to enter a real game from this page.
            Tap a few open squares to try it.
        </p>

        <!-- The same disclosure a real board carries, in the same position. -->
        <p class="disclosure">No purchase necessary. Free to enter.</p>

        <div class="board-meta">
            <span>
                <?= htmlspecialchars((string) $board['away_team'], ENT_QUOTES, 'UTF-8') ?>
                at <?= htmlspecialchars((string) $board['home_team'], ENT_QUOTES, 'UTF-8') ?>
            </span>
            <span>
                <?= $available ?> of 100 open &middot;
                you have picked <span data-demo-count>0</span> of <?= (int) $board['claim_limit'] ?>
            </span>
        </div>

        <div class="board-wrap">
            <table class="board" data-demo-board data-claim-limit="<?= (int) $board['claim_limit'] ?>">
                <colgroup>
                    <col class="board__axis">
                </colgroup>
                <caption class="footnote" style="text-align:left;margin:0 0 8px;">
                    Rows are the home team, columns the away team. On a real board the numbers stay
                    hidden until kickoff, which is why the edges are blank here too.
                </caption>
                <thead>
                    <tr>
                        <th scope="col"><span class="hp">Home digit by away digit</span></th>
                        <?php for ($col = 0; $col < 10; $col++): ?>
                        <th scope="col"></th>
                        <?php endfor; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php for ($row = 0; $row < 10; $row++): ?>
                    <tr>
                        <th scope="row"></th>
                        <?php for ($col = 0; $col < 10; $col++): ?>
                        <?php
                        $name = (string) ($board['squares'][$row][$col] ?? '');
                        $taken = $name !== '';
                        $label = 'Square row ' . $row . ' column ' . $col . ($taken ? ', taken by ' . $name : ', open');
                        ?>
                        <td>
                            <label class="cell<?= $taken ? ' cell--taken' : '' ?>" data-cell="<?= $row ?>-<?= $col ?>">
                                <input type="checkbox" name="squares[]" value="<?= $row ?>-<?= $col ?>"
                                       aria-label="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>"
                                       <?= $taken ? 'disabled' : '' ?>>
                                <span><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></span>
                            </label>
                        </td>
                        <?php endfor; ?>
                    </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>

        <p class="alert alert--ok" data-demo-notice tabindex="-1" <?= $submitted ? '' : 'hidden' ?> role="status">
            This is a demo, so nothing was saved. On a real board those squares would be yours, and
            you would get a text if they won.
        </p>

        <div class="hero__actions" style="margin-top:20px;">
            <!-- A GET, deliberately: this page has no write path of any kind. -->
            <form method="GET" action="/demo">
                <input type="hidden" name="submitted" value="1">
                <button type="submit" class="btn btn--primary" data-demo-submit>Claim these squares</button>
            </form>
            <a class="btn btn--ghost" href="/#request-demo">Request a demo</a>
        </div>

        <h2 style="margin-top:40px;">What happens on a real board</h2>
        <p>
            Your customer fills in their name, email and mobile, and ticks a consent box. At kickoff
            the numbers are drawn at random and the board locks. Scores sync on their own, and each
            quarter's winner gets a redemption code by text and email that your advisor marks off at
            the counter.
        </p>
        <p><a href="/#how">See the four steps</a> or <a href="/#request-demo">request a demo</a>.</p>
    </div>
</main>

<?php require __DIR__ . '/_footer.php'; ?>
</body>
</html>
