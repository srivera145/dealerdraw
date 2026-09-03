<?php
/**
 * Shared shell for a guide. The content file supplies the article body only,
 * so every guide gets the same head, breadcrumbs and schema without repeating
 * them four times.
 *
 * @var array  $guide
 * @var string $contentFile
 */
$guide = $guide ?? [];
$contentFile = (string) ($contentFile ?? '');

$pageTitle = (string) $guide['title'] . ' - DealerDraw';
$pageDescription = (string) $guide['description'];
$canonicalPath = '/guides/' . (string) $guide['slug'];
$schemaTypes = ['organization', 'software', 'breadcrumbs'];
$schemaCrumbs = [
    ['name' => 'DealerDraw', 'path' => '/'],
    ['name' => 'Guides', 'path' => '/guides'],
    ['name' => (string) $guide['title'], 'path' => $canonicalPath],
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
            <a href="/">DealerDraw</a> <span aria-hidden="true">/</span>
            <a href="/guides">Guides</a> <span aria-hidden="true">/</span>
            <span aria-current="page"><?= htmlspecialchars((string) $guide['title'], ENT_QUOTES, 'UTF-8') ?></span>
        </nav>

        <article class="prose">
            <?php require __DIR__ . '/' . $contentFile . '.php'; ?>

            <p class="guide-card__meta">
                Last updated
                <time datetime="<?= htmlspecialchars((string) $guide['updated'], ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars(date('F Y', strtotime((string) $guide['updated']) ?: time()), ENT_QUOTES, 'UTF-8') ?>
                </time>.
                Published by EchoDial LLC. Nothing here is legal advice.
            </p>
        </article>

        <section class="cta-band">
            <h2>See a board before you commit to anything</h2>
            <p>
                The sample board is live and saves nothing. If it looks right for your store, ask for a demo and
                we will build your first board with you.
            </p>
            <div class="hero__actions">
                <a class="btn btn--primary" href="/#request-demo">Request a demo</a>
                <a class="btn btn--ghost" href="/demo">See a live board</a>
            </div>
        </section>
    </div>
</main>

<?php require __DIR__ . '/../_footer.php'; ?>
</body>
</html>
