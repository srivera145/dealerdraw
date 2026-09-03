<?php
/**
 * JSON-LD blocks for the marketing site.
 *
 * Emits one <script type="application/ld+json"> per graph node. Callers pass
 * $schemaTypes to choose which blocks appear, plus the data any of them need.
 *
 * @var string   $siteUrl
 * @var string[] $schemaTypes   organization | software | faq | breadcrumbs
 * @var array    $schemaFaqs    for the faq block
 * @var array    $schemaCrumbs  [['name' => ..., 'path' => ...], ...]
 */
$siteUrl = rtrim((string) ($siteUrl ?? \Keel\Core\Env::get('APP_URL', 'https://dealerdraw.com')), '/');
$schemaTypes = $schemaTypes ?? ['organization', 'software'];
$schemaFaqs = $schemaFaqs ?? [];
$schemaCrumbs = $schemaCrumbs ?? [];

$blocks = [];

if (in_array('organization', $schemaTypes, true)) {
    // EchoDial LLC is the publisher; DealerDraw is its brand. No LocalBusiness
    // node here on purpose - that requires a real listed address, and inventing
    // one to chase local results is exactly what gets a site filtered.
    $blocks[] = [
        '@context' => 'https://schema.org',
        '@type' => 'Organization',
        '@id' => $siteUrl . '/#organization',
        'name' => 'EchoDial LLC',
        'url' => $siteUrl . '/',
        'description' => 'EchoDial LLC builds customer engagement software for automotive dealerships.',
        'brand' => [
            '@type' => 'Brand',
            'name' => 'DealerDraw',
            'url' => $siteUrl . '/',
            'slogan' => 'Turn your service lounge into a customer list.',
        ],
        'makesOffer' => [
            '@type' => 'Offer',
            'itemOffered' => ['@type' => 'SoftwareApplication', '@id' => $siteUrl . '/#software'],
        ],
    ];
}

if (in_array('software', $schemaTypes, true)) {
    $blocks[] = [
        '@context' => 'https://schema.org',
        '@type' => 'SoftwareApplication',
        '@id' => $siteUrl . '/#software',
        'name' => 'DealerDraw',
        'url' => $siteUrl . '/',
        'applicationCategory' => 'BusinessApplication',
        'applicationSubCategory' => 'Customer engagement and promotions',
        'operatingSystem' => 'Web browser',
        'description' => 'DealerDraw runs free-to-enter promotional games for car dealerships. Customers claim '
            . 'squares on a game board at no cost, the dealership collects opted-in phone numbers and email '
            . 'addresses, and winners receive a redemption code for a dealer-supplied service offer by text and '
            . 'email. No purchase is necessary and there is no entry fee.',
        'publisher' => ['@type' => 'Organization', '@id' => $siteUrl . '/#organization'],
        'offers' => [
            '@type' => 'Offer',
            'price' => '199.00',
            'priceCurrency' => 'USD',
            'category' => 'Subscription',
            'availability' => 'https://schema.org/InStock',
            'url' => $siteUrl . '/#pricing',
            'priceSpecification' => [
                '@type' => 'UnitPriceSpecification',
                'price' => '199.00',
                'priceCurrency' => 'USD',
                'unitCode' => 'MON',
                'billingDuration' => 1,
                'billingIncrement' => 1,
                'referenceQuantity' => [
                    '@type' => 'QuantitativeValue',
                    'value' => 1,
                    'unitCode' => 'MON',
                ],
            ],
        ],
        'featureList' => [
            'Free-entry football squares boards for NFL and college football',
            'Opted-in contact capture with consent records',
            'Automatic score syncing and winner resolution',
            'Redemption codes delivered by SMS and email',
            'Contact export',
        ],
    ];
}

if (in_array('faq', $schemaTypes, true) && $schemaFaqs !== []) {
    $questions = [];

    foreach ($schemaFaqs as $faq) {
        // The structured answer is the full visible answer, so the markup can
        // never claim something the page does not actually say.
        $text = trim((string) $faq['answer']);

        foreach (($faq['elaboration'] ?? []) as $paragraph) {
            $text .= ' ' . trim((string) $paragraph);
        }

        $questions[] = [
            '@type' => 'Question',
            'name' => (string) $faq['question'],
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => $text,
            ],
        ];
    }

    $blocks[] = [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        '@id' => $siteUrl . '/faq#faqpage',
        'mainEntity' => $questions,
    ];
}

if (in_array('breadcrumbs', $schemaTypes, true) && $schemaCrumbs !== []) {
    $items = [];
    $position = 1;

    foreach ($schemaCrumbs as $crumb) {
        $items[] = [
            '@type' => 'ListItem',
            'position' => $position++,
            'name' => (string) $crumb['name'],
            'item' => $siteUrl . (string) $crumb['path'],
        ];
    }

    $blocks[] = [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => $items,
    ];
}

foreach ($blocks as $block): ?>
<script type="application/ld+json"><?= json_encode($block, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
<?php endforeach; ?>
