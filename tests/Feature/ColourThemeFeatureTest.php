<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Content\MarketingContent;
use Tests\Support\SquaresFixtures;
use Tests\TestCase;

/**
 * Light and dark themes on the public pages: resolved before first paint,
 * togglable, and readable whatever colour the dealer picked.
 */
class ColourThemeFeatureTest extends TestCase
{
    use SquaresFixtures;

    private function publicPaths(): array
    {
        $paths = ['/', '/demo', '/faq', '/guides'];

        foreach (MarketingContent::guides() as $guide) {
            $paths[] = '/guides/' . $guide['slug'];
        }

        return $paths;
    }

    public function testEveryPageResolvesTheThemeBeforeFirstPaint(): void
    {
        foreach ($this->publicPaths() as $path) {
            $body = $this->get($path)->body;

            // Inline and synchronous, inside <head>: a deferred script would
            // paint the wrong palette first and then flash.
            $head = substr($body, 0, (int) strpos($body, '</head>'));

            self::assertStringContainsString("localStorage.getItem('keel-theme')", $head, $path);
            self::assertStringContainsString('prefers-color-scheme: dark', $head, $path);
            self::assertStringContainsString("setAttribute('data-theme'", $head, $path);
            self::assertStringNotContainsString('defer', substr($head, (int) strpos($head, "localStorage.getItem('keel-theme')"), 400));
        }
    }

    public function testTheToggleShipsHiddenAndIsRevealedByScript(): void
    {
        $body = $this->get('/')->body;

        preg_match('#<button[^>]*data-theme-toggle[^>]*>#', $body, $button);
        self::assertNotEmpty($button, 'no theme toggle on the page');

        // Progressive enhancement: a control that cannot work is not shown.
        self::assertStringContainsString('hidden', $button[0]);

        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/public_html/assets/js/theme.js');
        self::assertStringContainsString('button.hidden = false;', $script);
        self::assertStringContainsString("localStorage.setItem(THEME_KEY", $script);
    }

    public function testStylesheetsCarryBothTheAttributeRuleAndTheMediaQuery(): void
    {
        $root = dirname(__DIR__, 2) . '/public_html/assets/css/';

        foreach (['site.css', 'critical.css', 'board.css'] as $sheet) {
            $css = (string) file_get_contents($root . $sheet);

            // The attribute rule is what normally applies; the media query is
            // what covers a visitor with JavaScript switched off. Matched
            // loosely on whitespace because critical.css ships minified.
            self::assertStringContainsString('[data-theme="dark"]', $css, $sheet);
            self::assertMatchesRegularExpression('/prefers-color-scheme:\s*dark/', $css, $sheet);

            // An explicit light choice has to beat the OS preference.
            self::assertStringContainsString(':not([data-theme="light"])', $css, $sheet);
        }
    }

    public function testHeroScreenshotHasAThemeMatchedPair(): void
    {
        $body = $this->get('/')->body;

        self::assertStringContainsString('board-preview.png', $body);
        self::assertStringContainsString('board-preview-dark.png', $body);

        $images = dirname(__DIR__, 2) . '/public_html/assets/images/';
        self::assertFileExists($images . 'board-preview.png');
        self::assertFileExists($images . 'board-preview-dark.png');

        // Genuinely two different captures, not the same file twice.
        self::assertNotSame(
            md5_file($images . 'board-preview.png'),
            md5_file($images . 'board-preview-dark.png'),
            'the dark hero is a copy of the light one'
        );

        // Same intrinsic size, so swapping them cannot shift the layout.
        self::assertSame(
            array_slice((array) getimagesize($images . 'board-preview.png'), 0, 2),
            array_slice((array) getimagesize($images . 'board-preview-dark.png'), 0, 2)
        );
    }

    public function testClaimPageIsThemedAndKeepsTheDealerColourReadable(): void
    {
        $context = $this->openClaimBoard('#111827');   // near-black brand
        $body = $this->get('/p/' . $context)->body;

        self::assertStringContainsString("localStorage.getItem('keel-theme')", $body);
        self::assertStringContainsString('data-theme-toggle', $body);

        // The chosen colour is kept for the light theme...
        self::assertStringContainsString('--dealer-brand: #111827', $body);

        // ...and a lightened variant of it is supplied for the dark one, or the
        // dealer's buttons and selected squares would vanish into the page.
        preg_match('/--dealer-brand-dark: (\#[0-9a-f]{6})/', $body, $dark);
        self::assertNotEmpty($dark, 'no dark-theme brand colour was emitted');
        self::assertNotSame('#111827', $dark[1]);

        $red = (int) hexdec(substr($dark[1], 1, 2));
        $green = (int) hexdec(substr($dark[1], 3, 2));
        $blue = (int) hexdec(substr($dark[1], 5, 2));
        $luma = (0.299 * $red + 0.587 * $green + 0.114 * $blue) / 255;

        self::assertGreaterThanOrEqual(0.45, $luma, 'the dark-theme brand colour is still too dark to read');
    }

    public function testAnAlreadyBrightBrandColourIsLeftAlone(): void
    {
        $context = $this->openClaimBoard('#fde047');   // bright yellow
        $body = $this->get('/p/' . $context)->body;

        self::assertStringContainsString('--dealer-brand: #fde047', $body);
        self::assertStringContainsString('--dealer-brand-dark: #fde047', $body);
    }

    public function testTheClaimPageNoLongerLoadsTheAdminBundle(): void
    {
        $body = $this->get('/p/' . $this->openClaimBoard('#0f766e'))->body;

        // Its own theme script owns the attribute; loading Keel's app.js too
        // would bind a second handler to the same toggle and cancel every click.
        self::assertStringNotContainsString('resources/js/app.js', $body);
        self::assertStringContainsString('/assets/js/theme.js', $body);
        self::assertStringContainsString('/assets/js/board.js', $body);
    }

    private function openClaimBoard(string $brandColor): string
    {
        $dealer = $this->createOrganization('Theme Motors');
        $slug = 'theme-' . bin2hex(random_bytes(3));

        $campaign = $this->createCampaign((int) $dealer['id'], [
            'public_slug' => $slug,
            'status' => 'active',
            'brand_primary_color' => $brandColor,
        ]);

        $game = $this->createGame(['kickoff_at' => date('Y-m-d H:i:s', time() + 86400)]);
        $this->createBoard((int) $campaign['id'], (int) $game['id']);

        return $slug;
    }
}
