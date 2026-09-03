<?php

namespace Keel\App\Controllers;

use Keel\Core\Controller;
use Keel\Core\Env;
use Keel\Core\Request;
use Keel\Core\Response;

/**
 * robots.txt for the marketing site.
 *
 * AI crawlers are allowed deliberately. Blocking them does not protect anything
 * here - the marketing pages are public either way - it only removes DealerDraw
 * from the answers those assistants give when a dealer asks them about
 * dealership promotions. The things worth keeping out of an index are customer
 * claim pages and the dealer admin, and those are disallowed for everyone.
 */
class RobotsController extends Controller
{
    /**
     * Never indexable: customer-facing claim pages carry entrants' names, and
     * the admin is behind auth anyway.
     */
    private const DISALLOWED = [
        '/admin/',
        '/p/',
        '/api/',
        '/dashboard',
        '/settings/',
        '/super-admin/',
        '/billing/',
        '/onboarding/',
        '/files/',
        '/webhooks/',
        '/login',
        '/auth/',
        '/invite/',
        // Keel's own framework documentation ships with the starter kit and has
        // nothing to do with DealerDraw; keeping it out avoids indexing pages
        // that would confuse both a dealer and a crawler.
        '/docs',
    ];

    /**
     * Crawlers explicitly welcomed. Split between assistants that answer
     * questions live and crawlers that gather training data - a dealer asking
     * an assistant "are dealership squares boards legal" should be able to
     * reach this site's answer.
     */
    private const AI_CRAWLERS = [
        'GPTBot',
        'OAI-SearchBot',
        'ChatGPT-User',
        'ClaudeBot',
        'Claude-User',
        'PerplexityBot',
        'Perplexity-User',
        'Google-Extended',
        'Applebot-Extended',
        'CCBot',
        'meta-externalagent',
    ];

    public function index(Request $request): never
    {
        $lines = [];

        foreach (self::AI_CRAWLERS as $crawler) {
            $lines[] = 'User-agent: ' . $crawler;
            $lines[] = 'Allow: /';

            foreach (self::DISALLOWED as $path) {
                $lines[] = 'Disallow: ' . $path;
            }

            $lines[] = '';
        }

        $lines[] = 'User-agent: *';
        $lines[] = 'Allow: /';

        foreach (self::DISALLOWED as $path) {
            $lines[] = 'Disallow: ' . $path;
        }

        $lines[] = '';
        $lines[] = 'Sitemap: ' . $this->baseUrl() . '/sitemap.xml';

        Response::raw(implode("\n", $lines) . "\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    private function baseUrl(): string
    {
        $baseUrl = trim((string) Env::get('APP_URL', ''));

        return $baseUrl !== '' ? rtrim($baseUrl, '/') : 'https://dealerdraw.com';
    }
}
