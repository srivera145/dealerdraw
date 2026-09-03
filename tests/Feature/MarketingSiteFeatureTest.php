<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Models\Lead;
use Keel\Core\Database;
use Keel\Core\Session;
use Tests\TestCase;

/**
 * The dealerdraw.com landing page and its one conversion action.
 */
class MarketingSiteFeatureTest extends TestCase
{
    public function testHeroSellsTheOutcomeWithoutNamingTheMechanic(): void
    {
        $response = $this->get('/');

        self::assertSame(200, $response->status);

        // Self-check: the value proposition lands without the word "squares".
        preg_match('#<h1[^>]*>(.*?)</h1>#s', $response->body, $headline);
        preg_match('#class="hero__sub"[^>]*>(.*?)</p>#s', $response->body, $subhead);

        $hero = strtolower(strip_tags(($headline[1] ?? '') . ' ' . ($subhead[1] ?? '')));

        self::assertStringContainsString('customer list', $hero);
        self::assertStringNotContainsString('square', $hero, 'the hero must not lean on the mechanic');
        self::assertStringNotContainsString('football', $hero);
        self::assertStringContainsString('opted-in', $hero);
    }

    public function testEveryRequiredSectionIsPresentInOrder(): void
    {
        // Scope to the body: 'EchoDial LLC' also appears in the head's JSON-LD.
        $full = $this->get('/')->body;
        $body = substr($full, (int) strpos($full, '<body'));

        $sections = [
            'Turn your service lounge',
            'You already met these customers',
            'Four steps',
            'One board. Four service offers',
            'This is not gambling',
            'Football starts it',
            'One price',
            'Fifteen minutes',
            'is a product of EchoDial LLC',
        ];

        $lastPosition = -1;

        foreach ($sections as $needle) {
            $position = strpos($body, $needle);
            self::assertIsInt($position, "missing section: {$needle}");
            self::assertGreaterThan($lastPosition, $position, "section out of order: {$needle}");
            $lastPosition = $position;
        }
    }

    public function testTheLeadFormIsTheOnlyConversionAction(): void
    {
        $body = $this->get('/')->body;

        // Exactly one form that writes anything.
        self::assertSame(1, substr_count($body, '<form'));
        self::assertSame(1, substr_count($body, 'action="/request-demo"'));
        self::assertSame(1, substr_count($body, 'method="POST"'));

        // Every "request a demo" affordance points at that same form.
        // The shared nav links '/#request-demo'; in-page anchors use '#request-demo'.
        $anchors = substr_count($body, 'href="#request-demo"') + substr_count($body, 'href="/#request-demo"');
        self::assertGreaterThanOrEqual(2, $anchors);

        // The only competing link is the demo board, which routes back here.
        self::assertStringContainsString('href="/demo"', $body);
        self::assertStringNotContainsString('calendly', strtolower($body));
        self::assertStringNotContainsString('mailto:', strtolower($body));
        self::assertStringNotContainsString('tel:', strtolower($body));
    }

    public function testComplianceBandAnswersTheLegalQuestionPlainly(): void
    {
        $body = $this->get('/')->body;

        self::assertStringContainsString('This is not gambling, and it is not a pool.', $body);
        self::assertStringContainsString('Nobody pays to enter.', $body);
        self::assertStringContainsString('There is no pot.', $body);
        self::assertStringContainsString('No cash prizes.', $body);
        self::assertStringContainsString('No purchase necessary', $body);

        // Plain-spoken, not a disclaimer wall.
        foreach (['heretofore', 'aforementioned', 'pursuant', 'hereinafter', 'indemnif'] as $lawyerly) {
            self::assertStringNotContainsString($lawyerly, strtolower($body));
        }

        // And it still points them at their own counsel rather than promising legality.
        self::assertStringContainsString('counsel should sign off', $body);
    }

    public function testSocialCardPointsAtARealBoardImageThatExists(): void
    {
        $body = $this->get('/')->body;

        self::assertMatchesRegularExpression('#<meta property="og:image" content="[^"]+/assets/images/board-og\.png"#', $body);
        self::assertStringContainsString('<meta property="og:image:width" content="1200">', $body);
        self::assertStringContainsString('<meta property="og:image:height" content="630">', $body);
        self::assertStringContainsString('<meta name="twitter:card" content="summary_large_image">', $body);

        $ogImage = dirname(__DIR__, 2) . '/public_html/assets/images/board-og.png';
        self::assertFileExists($ogImage, 'the og:image must not 404 when a dealer pastes the link');

        $size = getimagesize($ogImage);
        self::assertSame([1200, 630], [$size[0], $size[1]]);
    }

    public function testPageIsAccessibleAndLight(): void
    {
        $body = $this->get('/')->body;

        // One h1, and no heading level is skipped.
        self::assertSame(1, substr_count($body, '<h1'));

        preg_match_all('#<h([1-3])#', $body, $levels);
        $previous = 0;

        foreach ($levels[1] as $level) {
            self::assertLessThanOrEqual($previous + 1, (int) $level, 'heading levels must not skip');
            $previous = max($previous, (int) $level);
        }

        // Every image carries alt text.
        preg_match_all('#<img[^>]*>#', $body, $images);
        self::assertNotEmpty($images[0]);

        foreach ($images[0] as $image) {
            self::assertMatchesRegularExpression('#\salt="[^"]+"#', $image, 'image without alt text: ' . $image);
        }

        // Every visible input has a label bound to it.
        preg_match_all('#<(?:input|textarea)[^>]*\sid="([^"]+)"#', $body, $inputs);
        self::assertNotEmpty($inputs[1]);

        foreach ($inputs[1] as $id) {
            self::assertStringContainsString('for="' . $id . '"', $body, "no label for #{$id}");
        }

        // No framework, no jQuery.
        foreach (['jquery', 'bootstrap', 'react', 'vue', 'tailwind'] as $dependency) {
            self::assertStringNotContainsString($dependency, strtolower($body));
        }

        self::assertSame(0, substr_count($body, '<script src="http'), 'no third-party scripts');
    }

    public function testAssetBudgetStaysUnderHalfAMegabyte(): void
    {
        $body = $this->get('/')->body;
        $root = dirname(__DIR__, 2) . '/public_html';

        $weight = strlen($body);

        foreach (['/assets/css/site.css', '/assets/js/site.js', '/assets/images/board-preview.png'] as $asset) {
            self::assertFileExists($root . $asset);
            $weight += filesize($root . $asset);
        }

        self::assertLessThan(500 * 1024, $weight, 'first-load weight is over budget: ' . $weight . ' bytes');
    }
}
