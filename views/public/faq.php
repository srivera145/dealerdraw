<?php
$faqs = $faqs ?? [];

$pageTitle = 'DealerDraw FAQ - Are dealership football squares legal, and what do they cost?';
$pageDescription = 'Straight answers to the questions dealerships ask before running a free-entry promotion: '
    . 'whether squares boards are legal, what separates a sweepstakes from a lottery, how to collect contact '
    . 'information legally, what it costs, and whether you need a licence.';
$canonicalPath = '/faq';
$schemaTypes = ['organization', 'software', 'faq', 'breadcrumbs'];
$schemaFaqs = $faqs;
$schemaCrumbs = [
    ['name' => 'DealerDraw', 'path' => '/'],
    ['name' => 'FAQ', 'path' => '/faq'],
];
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
        <nav class="crumbs" aria-label="Breadcrumb">
            <a href="/">DealerDraw</a> <span aria-hidden="true">/</span> <span aria-current="page">FAQ</span>
        </nav>

        <p class="eyebrow">Questions dealers actually ask</p>
        <h1>DealerDraw FAQ</h1>

        <p class="prose">
            Every answer below starts with the direct answer, in full sentences, so it makes sense on its own.
            None of it is legal advice - it is the framework, and your own counsel signs off on your rules.
        </p>

        <div class="prose">
            <?php foreach ($faqs as $faq): ?>
            <section class="qa" id="<?= htmlspecialchars($faq['slug'], ENT_QUOTES, 'UTF-8') ?>">
                <h2><?= htmlspecialchars($faq['question'], ENT_QUOTES, 'UTF-8') ?></h2>
                <p class="qa__answer"><?= htmlspecialchars($faq['answer'], ENT_QUOTES, 'UTF-8') ?></p>

                <?php foreach ($faq['elaboration'] as $paragraph): ?>
                <p><?= htmlspecialchars($paragraph, ENT_QUOTES, 'UTF-8') ?></p>
                <?php endforeach; ?>
            </section>
            <?php endforeach; ?>
        </div>

        <section class="cta-band">
            <h2>Still deciding?</h2>
            <p>
                We will set up a board for your next home game before the call, so you are looking at your teams
                and your service offers rather than a slide deck.
            </p>
            <a class="btn btn--primary" href="/#request-demo">Request a demo</a>
        </section>

        <p class="prose"><a href="/guides">Read the guides</a> for the longer version of any of these.</p>
    </div>
</main>

<?php require __DIR__ . '/_footer.php'; ?>
</body>
</html>
