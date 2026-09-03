<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Transport-level checks on the three discovery endpoints.
 *
 * These previously asserted the Keel starter kit's own sitemap, robots and
 * llms.txt, which described a PHP framework. This domain serves DealerDraw, so
 * they now assert that and that the framework's docs are no longer published.
 * The content of each file is covered in detail by SeoDiscoverabilityFeatureTest.
 */
class PublicDiscoveryEndpointsFeatureTest extends TestCase
{
    public function testSitemapIsServedAsXmlAndCoversOnlyMarketingPages(): void
    {
        $response = $this->get('/sitemap.xml');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('application/xml', (string) $response->header('Content-Type'));

        $baseUrl = rtrim((string) ($_ENV['APP_URL'] ?? 'http://localhost'), '/');

        self::assertStringContainsString('<loc>' . $baseUrl . '/</loc>', $response->body);
        self::assertStringContainsString('<loc>' . $baseUrl . '/faq</loc>', $response->body);

        // Route patterns, protected areas and the framework docs stay out.
        self::assertStringNotContainsString('{', $response->body);
        self::assertStringNotContainsString('/dashboard', $response->body);
        self::assertStringNotContainsString('/settings/', $response->body);
        self::assertStringNotContainsString('/api/', $response->body);
        self::assertStringNotContainsString('/docs', $response->body);
        self::assertStringNotContainsString('/login', $response->body);
    }

    public function testRobotsIsServedAsPlainTextAndProtectsTheApplication(): void
    {
        $response = $this->get('/robots.txt');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('text/plain', (string) $response->header('Content-Type'));

        self::assertStringContainsString("User-agent: *\n", $response->body);
        self::assertStringContainsString("Allow: /\n", $response->body);

        foreach (['/api/', '/billing/', '/dashboard', '/files/', '/settings/', '/admin/', '/p/'] as $path) {
            self::assertStringContainsString('Disallow: ' . $path . "\n", $response->body);
        }

        // Keel's framework docs are not DealerDraw content and are excluded now.
        self::assertStringContainsString('Disallow: /docs', $response->body);

        $baseUrl = rtrim((string) ($_ENV['APP_URL'] ?? 'http://localhost'), '/');
        self::assertStringContainsString('Sitemap: ' . $baseUrl . '/sitemap.xml', $response->body);
    }

    public function testLlmsTxtDescribesDealerDrawRatherThanTheFramework(): void
    {
        $response = $this->get('/llms.txt');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('text/plain', (string) $response->header('Content-Type'));

        self::assertStringContainsString('# DealerDraw', $response->body);
        self::assertStringNotContainsString('# Keel', $response->body);
        self::assertStringNotContainsString('starter kit', $response->body);
        self::assertStringNotContainsString('/docs/', $response->body);
    }
}
