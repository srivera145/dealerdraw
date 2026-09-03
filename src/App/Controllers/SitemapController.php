<?php

namespace Keel\App\Controllers;

use Keel\App\Content\MarketingContent;
use Keel\Core\Controller;
use Keel\Core\Env;
use Keel\Core\Request;
use Keel\Core\Response;

/**
 * sitemap.xml for the marketing site.
 *
 * Built from an explicit allowlist rather than by walking the router. A derived
 * sitemap has to be trusted to keep excluding things; an allowlist cannot start
 * publishing /p/{slug} claim pages or an admin route because somebody added a
 * route with the wrong flag six months from now.
 */
class SitemapController extends Controller
{
    public function index(Request $request): never
    {
        $baseUrl = $this->baseUrl();

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";

        foreach (MarketingContent::sitemapPaths() as $entry) {
            $path = (string) $entry['path'];

            $xml .= "  <url>\n";
            $xml .= '    <loc>' . htmlspecialchars($baseUrl . $path, ENT_QUOTES | ENT_XML1, 'UTF-8') . "</loc>\n";

            $lastModified = $this->lastModifiedForPath($path);

            if ($lastModified !== null) {
                $xml .= '    <lastmod>' . $lastModified . "</lastmod>\n";
            }

            $xml .= '    <changefreq>' . htmlspecialchars((string) $entry['changefreq'], ENT_QUOTES | ENT_XML1, 'UTF-8') . "</changefreq>\n";
            $xml .= '    <priority>' . htmlspecialchars((string) $entry['priority'], ENT_QUOTES | ENT_XML1, 'UTF-8') . "</priority>\n";
            $xml .= "  </url>\n";
        }

        $xml .= "</urlset>\n";

        Response::raw($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    /** Taken from the view file that renders the page, so it reflects real edits. */
    private function lastModifiedForPath(string $path): ?string
    {
        $viewRoot = dirname(__DIR__, 3) . '/views/public';

        $file = match (true) {
            $path === '/' => $viewRoot . '/home.php',
            $path === '/demo' => $viewRoot . '/demo.php',
            $path === '/faq' => $viewRoot . '/faq.php',
            $path === '/guides' => $viewRoot . '/guides/index.php',
            str_starts_with($path, '/guides/') => $viewRoot . '/guides/' . substr($path, strlen('/guides/')) . '.php',
            default => null,
        };

        if ($file === null || !is_file($file)) {
            return null;
        }

        $timestamp = filemtime($file);

        return $timestamp === false ? null : gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }

    private function baseUrl(): string
    {
        $baseUrl = trim((string) Env::get('APP_URL', ''));

        return $baseUrl !== '' ? rtrim($baseUrl, '/') : 'https://dealerdraw.com';
    }
}
