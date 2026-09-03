<?php
$guides = $guides ?? [];

$pageTitle = 'DealerDraw guides - dealership promotions, retention and text messaging';
$pageDescription = 'Practical guides for dealership fixed ops: running legal free-entry promotions, service drive '
    . 'retention that survives a busy Saturday, 10DLC registration for text messaging, and the difference between '
    . 'a sweepstakes and a lottery.';
$canonicalPath = '/guides';
$schemaTypes = ['organization', 'software', 'breadcrumbs'];
$schemaCrumbs = [
    ['name' => 'DealerDraw', 'path' => '/'],
    ['name' => 'Guides', 'path' => '/guides'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require __DIR__ . '/../_head.php'; ?>
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
<?php require __DIR__ . '/../_nav.php'; ?>

<main id="main" class="section">
    <div class="shell">
        <nav class="crumbs" aria-label="Breadcrumb">
            <a href="/">DealerDraw</a> <span aria-hidden="true">/</span> <span aria-current="page">Guides</span>
        </nav>

        <p class="eyebrow">Guides</p>
        <h1>The long version</h1>

        <p class="prose">
            Four write-ups on the things dealerships get stuck on before running a promotion. No gated PDFs and
            no email wall - the whole thing is on the page.
        </p>

        <ul class="guide-list">
            <?php foreach ($guides as $guide): ?>
            <li class="guide-card">
                <h2>
                    <a href="/guides/<?= htmlspecialchars($guide['slug'], ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars($guide['title'], ENT_QUOTES, 'UTF-8') ?>
                    </a>
                </h2>
                <p><?= htmlspecialchars($guide['summary'], ENT_QUOTES, 'UTF-8') ?></p>
                <p class="guide-card__meta">
                    Updated <time datetime="<?= htmlspecialchars($guide['updated'], ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars(date('F Y', strtotime($guide['updated']) ?: time()), ENT_QUOTES, 'UTF-8') ?>
                    </time>
                </p>
            </li>
            <?php endforeach; ?>
        </ul>

        <section class="cta-band">
            <h2>Want to see it running?</h2>
            <p>The sample board is live, needs no account, and saves nothing.</p>
            <a class="btn btn--ghost" href="/demo">See a live board</a>
        </section>
    </div>
</main>

<?php require __DIR__ . '/../_footer.php'; ?>
</body>
</html>
