<?php
/**
 * Shared <head> for every marketing page.
 *
 * @var string   $pageTitle
 * @var string   $pageDescription
 * @var string   $canonicalPath   e.g. '/faq'
 * @var ?string  $ogImagePath     defaults to the board social card
 * @var bool     $noIndex
 * @var string[] $schemaTypes
 * @var array    $schemaFaqs
 * @var array    $schemaCrumbs
 */
$siteUrl = rtrim((string) \Keel\Core\Env::get('APP_URL', 'https://dealerdraw.com'), '/');
$publicRoot = dirname(__DIR__, 2) . '/public_html';

$pageTitle = (string) ($pageTitle ?? 'DealerDraw');
$pageDescription = (string) ($pageDescription ?? '');
$canonicalPath = (string) ($canonicalPath ?? '/');
$ogImagePath = (string) ($ogImagePath ?? '/assets/images/board-og.png');
$noIndex = (bool) ($noIndex ?? false);

$canonicalUrl = $siteUrl . $canonicalPath;
$ogImageUrl = $siteUrl . $ogImagePath;

$assetVersion = static function (string $relativePath) use ($publicRoot): string {
    $modified = is_file($publicRoot . $relativePath) ? (int) filemtime($publicRoot . $relativePath) : 0;

    return $relativePath . ($modified > 0 ? '?v=' . $modified : '');
};

// Comments are useful in the source file, not in every page's HTML.
$criticalCss = (string) preg_replace(
    '#/\*.*?\*/#s',
    '',
    (string) @file_get_contents($publicRoot . '/assets/css/critical.css')
);
$stylesheet = $assetVersion('/assets/css/site.css');
?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
<meta name="description" content="<?= htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8') ?>">
<link rel="canonical" href="<?= htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8') ?>">
<?php if ($noIndex): ?>
<meta name="robots" content="noindex,follow">
<?php else: ?>
<meta name="robots" content="index,follow,max-image-preview:large,max-snippet:-1">
<?php endif; ?>

<meta property="og:type" content="website">
<meta property="og:site_name" content="DealerDraw">
<meta property="og:title" content="<?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:description" content="<?= htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:url" content="<?= htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:image" content="<?= htmlspecialchars($ogImageUrl, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="A DealerDraw board with customer names filling the squares.">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?>">
<meta name="twitter:description" content="<?= htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8') ?>">
<meta name="twitter:image" content="<?= htmlspecialchars($ogImageUrl, ENT_QUOTES, 'UTF-8') ?>">

<meta name="theme-color" content="#16543f">
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">

<!-- Above-the-fold CSS inlined; the rest loads without blocking the render. -->
<style><?= $criticalCss ?></style>
<link rel="preload" as="style" href="<?= htmlspecialchars($stylesheet) ?>" onload="this.onload=null;this.rel='stylesheet'">
<noscript><link rel="stylesheet" href="<?= htmlspecialchars($stylesheet) ?>"></noscript>

<script src="<?= htmlspecialchars($assetVersion('/assets/js/site.js')) ?>" defer></script>
<?php require __DIR__ . '/../partials/schema.php'; ?>
