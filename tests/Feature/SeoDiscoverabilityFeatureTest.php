<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Content\MarketingContent;
use Tests\TestCase;

/**
 * Crawlability, structured data and the content pages that carry it.
 */
class SeoDiscoverabilityFeatureTest extends TestCase
{
    /** Every marketing URL that should render. */
    private function marketingPaths(): array
    {
        $paths = ['/', '/demo', '/faq', '/guides'];

        foreach (MarketingContent::guides() as $guide) {
            $paths[] = '/guides/' . $guide['slug'];
        }

        return $paths;
    }

    /* ---- robots.txt --------------------------------------------------- */

    public function testRobotsAllowsTheAiCrawlersWeWantQuotingUs(): void
    {
        $robots = $this->get('/robots.txt');

        self::assertSame(200, $robots->status);

        // Self-check: none of these may be blocked. Blocking them removes
        // DealerDraw from those assistants' answers entirely.
        foreach (['GPTBot', 'ClaudeBot', 'PerplexityBot', 'Google-Extended'] as $crawler) {
            self::assertMatchesRegularExpression(
                '/User-agent: ' . preg_quote($crawler, '/') . "\nAllow: \\/\n/",
                $robots->body,
                $crawler . ' must be allowed'
            );
        }

        self::assertStringNotContainsString('Disallow: /' . "\n", $robots->body, 'nothing may blanket-block the site');
    }

    public function testRobotsBlocksClaimPagesAndAdminForEveryone(): void
    {
        $robots = $this->get('/robots.txt')->body;

        // The wildcard group has to carry the same disallows as the named ones.
        $wildcard = substr($robots, (int) strpos($robots, 'User-agent: *'));

        foreach (['/admin/', '/p/', '/dashboard', '/settings/', '/api/'] as $path) {
            self::assertStringContainsString('Disallow: ' . $path, $wildcard, $path . ' must be disallowed');
        }

        self::assertStringContainsString('Sitemap: ', $robots);
    }

    /* ---- sitemap ------------------------------------------------------ */

    public function testSitemapListsEveryMarketingPageAndNothingElse(): void
    {
        $sitemap = $this->get('/sitemap.xml');

        self::assertSame(200, $sitemap->status);

        preg_match_all('#<loc>([^<]+)</loc>#', $sitemap->body, $matches);
        $paths = array_map(
            static fn (string $url): string => (string) (parse_url(html_entity_decode($url), PHP_URL_PATH) ?: '/'),
            $matches[1]
        );

        sort($paths);
        $expected = $this->marketingPaths();
        sort($expected);

        self::assertSame($expected, $paths);
    }

    public function testClaimPagesAndAdminNeverAppearInTheSitemap(): void
    {
        $body = $this->get('/sitemap.xml')->body;

        // Self-check: a real campaign existing must not change the answer.
        foreach (['/p/', '/admin', '/login', '/dashboard', '/settings', '/docs'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $body, $forbidden . ' must not be in the sitemap');
        }
    }

    /* ---- structured data ---------------------------------------------- */

    public function testEveryPageHasOneH1ACanonicalAndParseableJsonLd(): void
    {
        foreach ($this->marketingPaths() as $path) {
            $body = $this->get($path)->body;

            self::assertSame(1, substr_count($body, '<h1'), $path . ' must have exactly one h1');
            self::assertMatchesRegularExpression('#<link rel="canonical" href="[^"]+"#', $body, $path . ' needs a canonical');

            preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $body, $blocks);
            self::assertNotEmpty($blocks[1], $path . ' has no JSON-LD');

            foreach ($blocks[1] as $block) {
                $decoded = json_decode($block, true);

                self::assertIsArray($decoded, $path . ' has invalid JSON-LD: ' . json_last_error_msg());
                self::assertSame('https://schema.org', $decoded['@context'] ?? null);
                self::assertArrayHasKey('@type', $decoded);
            }
        }
    }

    public function testCanonicalMatchesTheRequestedPath(): void
    {
        foreach ($this->marketingPaths() as $path) {
            $body = $this->get($path)->body;
            preg_match('#<link rel="canonical" href="([^"]+)"#', $body, $match);

            self::assertSame($path, (string) parse_url($match[1], PHP_URL_PATH), 'canonical mismatch on ' . $path);
        }
    }

    public function testSoftwareApplicationCarriesTheCategoryAndTheOffer(): void
    {
        $software = $this->schemaOfType('/', 'SoftwareApplication');

        self::assertSame('BusinessApplication', $software['applicationCategory']);
        self::assertSame('199.00', $software['offers']['price']);
        self::assertSame('USD', $software['offers']['priceCurrency']);
        self::assertSame('MON', $software['offers']['priceSpecification']['unitCode'], 'the $199 must be per month');
        self::assertNotEmpty($software['name']);
        self::assertNotEmpty($software['description']);
    }

    public function testOrganizationNamesEchoDialWithDealerDrawAsTheBrand(): void
    {
        $organization = $this->schemaOfType('/', 'Organization');

        self::assertSame('EchoDial LLC', $organization['name']);
        self::assertSame('Brand', $organization['brand']['@type']);
        self::assertSame('DealerDraw', $organization['brand']['name']);

        // No LocalBusiness without a real listed address.
        self::assertStringNotContainsString('LocalBusiness', $this->get('/')->body);
    }

    public function testFaqPageSchemaMatchesTheVisibleQuestionsAndAnswers(): void
    {
        $faqSchema = $this->schemaOfType('/faq', 'FAQPage');
        $body = $this->get('/faq')->body;
        $registry = MarketingContent::faqs();

        self::assertCount(count($registry), $faqSchema['mainEntity']);

        foreach ($faqSchema['mainEntity'] as $index => $question) {
            self::assertSame('Question', $question['@type']);
            self::assertSame($registry[$index]['question'], $question['name']);
            self::assertSame('Answer', $question['acceptedAnswer']['@type']);

            // Structured answer must be what the page actually says.
            self::assertStringContainsString(
                htmlspecialchars($registry[$index]['answer'], ENT_QUOTES, 'UTF-8'),
                $body,
                'the answer in the markup is not on the page'
            );
            self::assertStringContainsString($registry[$index]['answer'], $question['acceptedAnswer']['text']);
        }
    }

    public function testGuidePagesCarryBreadcrumbs(): void
    {
        foreach (MarketingContent::guides() as $guide) {
            $crumbs = $this->schemaOfType('/guides/' . $guide['slug'], 'BreadcrumbList');
            $names = array_column($crumbs['itemListElement'], 'name');
            $positions = array_column($crumbs['itemListElement'], 'position');

            self::assertSame(['DealerDraw', 'Guides', $guide['title']], $names);
            self::assertSame([1, 2, 3], $positions, 'breadcrumb positions must be sequential from 1');
        }
    }

    /* ---- answer-engine shape ------------------------------------------ */

    public function testEveryFaqAnswerStandsAloneIfQuoted(): void
    {
        foreach (MarketingContent::faqs() as $faq) {
            $answer = $faq['answer'];

            // Two to three complete sentences, not a fragment.
            $sentences = preg_split('/(?<=[.!?])\s+/', trim($answer)) ?: [];
            self::assertGreaterThanOrEqual(2, count($sentences), $faq['slug'] . ': too short to be an answer');
            self::assertLessThanOrEqual(4, count($sentences), $faq['slug'] . ': too long to be quoted cleanly');
            self::assertStringEndsWith('.', trim($answer));

            // No opener that only makes sense next to the question.
            foreach (['Yes,', 'No,', 'It depends', 'As mentioned', 'This means', 'That is because'] as $dangling) {
                self::assertStringStartsNotWith($dangling, $answer, $faq['slug'] . ' opens with a fragment');
            }

            // Names its own subject rather than leaning on the heading.
            self::assertMatchesRegularExpression(
                '/dealership|promotion|sweepstakes|lottery|contact information|service drive/i',
                $sentences[0],
                $faq['slug'] . ': first sentence has no subject of its own'
            );
        }
    }

    public function testEveryGuideOpensWithADirectAnswerAndIsNotThin(): void
    {
        foreach (MarketingContent::guides() as $guide) {
            $body = $this->get('/guides/' . $guide['slug'])->body;

            preg_match('#<article class="prose">(.*?)</article>#s', $body, $article);
            self::assertNotEmpty($article[1] ?? '', $guide['slug'] . ' has no article body');

            $words = str_word_count(strip_tags($article[1]));

            self::assertGreaterThanOrEqual(800, $words, $guide['slug'] . ' is thin: ' . $words . ' words');
            self::assertLessThanOrEqual(1400, $words, $guide['slug'] . ' is over length: ' . $words . ' words');

            // A lede answer before any subheading, then question-shaped H2s.
            self::assertStringContainsString('class="lede"', $article[1], $guide['slug'] . ' has no opening answer');
            self::assertLessThan(
                strpos($article[1], '<h2'),
                strpos($article[1], 'class="lede"'),
                $guide['slug'] . ': the answer must come before the first subheading'
            );

            $headings = [];
            preg_match_all('#<h2[^>]*>(.*?)</h2>#s', $article[1], $matches);

            foreach ($matches[1] as $heading) {
                $headings[] = trim(strip_tags($heading));
            }

            self::assertGreaterThanOrEqual(5, count($headings), $guide['slug'] . ' needs more structure');

            $questions = array_filter($headings, static fn (string $h): bool => str_ends_with($h, '?'));
            self::assertGreaterThanOrEqual(
                4,
                count($questions),
                $guide['slug'] . ': most headings should be question-shaped'
            );
        }
    }

    public function testUnknownGuideSlugIsANotFound(): void
    {
        self::assertSame(404, $this->get('/guides/does-not-exist')->status);
    }

    /* ---- llms.txt ----------------------------------------------------- */

    public function testLlmsTxtDescribesTheProductAudiencePricingAndLinks(): void
    {
        $llms = $this->get('/llms.txt');

        self::assertSame(200, $llms->status);

        $body = $llms->body;

        self::assertStringContainsString('# DealerDraw', $body);
        self::assertStringContainsString('EchoDial LLC', $body);
        self::assertStringContainsString('Fixed operations directors', $body);
        self::assertStringContainsString('$199 per month per rooftop', $body);
        self::assertStringContainsString('no per-entry fee', $body);
        self::assertStringContainsString('prize, chance and consideration', $body);

        foreach (['/demo', '/faq', '/guides'] as $path) {
            self::assertStringContainsString($path . ')', $body, $path . ' should be linked from llms.txt');
        }

        foreach (MarketingContent::guides() as $guide) {
            self::assertStringContainsString($guide['title'], $body);
        }

        foreach (MarketingContent::faqs() as $faq) {
            self::assertStringContainsString($faq['question'], $body);
            self::assertStringContainsString($faq['answer'], $body);
        }
    }

    /* ---- core web vitals ---------------------------------------------- */

    public function testAboveTheFoldCssIsInlinedAndTheRestLoadsWithoutBlocking(): void
    {
        $body = $this->get('/')->body;

        preg_match('#<style>(.*?)</style>#s', $body, $inlined);
        self::assertNotEmpty($inlined[1] ?? '', 'critical CSS is not inlined');
        self::assertGreaterThan(1000, strlen($inlined[1]), 'the inlined block is too small to cover the first screen');
        self::assertStringContainsString('rel="preload" as="style"', $body);
        self::assertStringContainsString('<noscript><link rel="stylesheet"', $body, 'needs a no-JS fallback');
    }

    public function testTheHeroImageCannotShiftTheLayout(): void
    {
        $body = $this->get('/')->body;

        preg_match('#<img[^>]*board-preview[^>]*>#', $body, $image);
        self::assertNotEmpty($image, 'the hero screenshot is missing');

        // Intrinsic size in the markup plus an aspect-ratio box in the CSS.
        self::assertStringContainsString('width="900"', $image[0]);
        self::assertStringContainsString('height="1200"', $image[0]);
        self::assertStringContainsString('loading="eager"', $image[0], 'the hero is above the fold');

        $critical = (string) file_get_contents(dirname(__DIR__, 2) . '/public_html/assets/css/critical.css');
        self::assertStringContainsString('aspect-ratio:900/1200', $critical);
    }

    /**
     * @return array<string, mixed>
     */
    private function schemaOfType(string $path, string $type): array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $this->get($path)->body, $blocks);

        foreach ($blocks[1] as $block) {
            $decoded = json_decode($block, true);

            if (is_array($decoded) && ($decoded['@type'] ?? null) === $type) {
                return $decoded;
            }
        }

        self::fail('no ' . $type . ' JSON-LD on ' . $path);
    }
}
