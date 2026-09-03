<?php

use Keel\App\Controllers\Admin\BoardController;
use Keel\App\Controllers\Admin\CampaignController;
use Keel\App\Controllers\Admin\PrizeController;
use Keel\App\Controllers\Admin\WinController;
use Keel\App\Controllers\Public\ClaimController;
use Keel\App\Controllers\Public\SiteController;
use Keel\App\Controllers\Webhooks\PlivoStatusController;
use Keel\App\Controllers\AuthController;
use Keel\App\Controllers\ActivityController;
use Keel\App\Controllers\ApiFileController;
use Keel\App\Controllers\ApiTokenController;
use Keel\App\Controllers\BillingController;
use Keel\App\Controllers\DashboardController;
use Keel\App\Controllers\DocsController;
use Keel\App\Controllers\FileController;
use Keel\App\Controllers\HealthController;
use Keel\App\Controllers\LlmsTxtController;
use Keel\App\Controllers\ManifestController;
use Keel\App\Controllers\OrganizationController;
use Keel\App\Controllers\RobotsController;
use Keel\App\Controllers\SitemapController;
use Keel\App\Controllers\SuperAdminController;
use Keel\App\Controllers\ThemeController;
use Keel\App\Controllers\StripeWebhookController;
use Keel\App\Controllers\WelcomeController;
use Keel\App\Middleware\AuthMiddleware;
use Keel\App\Middleware\ApiAuthMiddleware;
use Keel\App\Middleware\CsrfMiddleware;
use Keel\App\Middleware\RequireOrgAdminMiddleware;
use Keel\App\Middleware\RequireOrganizationMiddleware;
use Keel\App\Middleware\RequireSuperAdminMiddleware;
use Keel\App\Middleware\ThrottleMiddleware;
use Keel\Core\Env;

/** @var \Keel\Core\Router $router */

$multiTenancyEnabled = (bool) Env::get('MULTI_TENANCY_ENABLED', false);

$router->get('/up', [HealthController::class, 'index']);
$router->get('/manifest.webmanifest', [ManifestController::class, 'index']);
$router->get('/sitemap.xml', [SitemapController::class, 'index']);
$router->get('/robots.txt', [RobotsController::class, 'index']);
$router->get('/llms.txt', [LlmsTxtController::class, 'index']);

$router->group(['middleware' => [CsrfMiddleware::class]], function ($router) use ($multiTenancyEnabled) {
    // dealerdraw.com marketing site. WelcomeController is the Keel starter page
    // and is intentionally no longer routed.
    $router->get('/', [SiteController::class, 'home'], ['sitemap' => true]);
    $router->get('/demo', [SiteController::class, 'demo'], ['sitemap' => true]);
    $router->post('/request-demo', [SiteController::class, 'leadSubmit']);
    $router->get('/faq', [SiteController::class, 'faq'], ['sitemap' => true]);
    $router->get('/guides', [SiteController::class, 'guides'], ['sitemap' => true]);
    $router->get('/guides/{slug}', [SiteController::class, 'guide']);

    $router->get('/docs', [DocsController::class, 'index'], ['sitemap' => true]);
    $router->get('/docs/{slug}', [DocsController::class, 'show']);

    $router->group(['middleware' => [ThrottleMiddleware::class]], function ($router) {
        $router->get('/login', [AuthController::class, 'showLogin'], ['sitemap' => true]);
        $router->post('/auth/otp/request', [AuthController::class, 'requestOtp']);
        $router->post('/auth/otp/verify', [AuthController::class, 'verifyOtp']);
        $router->post('/auth/magic/request', [AuthController::class, 'requestMagicLink']);
        $router->get('/auth/magic', [AuthController::class, 'verifyMagicLink']);
    });

    $router->post('/logout', [AuthController::class, 'logout']);

    // Public claim pages. Tenancy is resolved from the campaign slug only.
    $router->group(['prefix' => '/p', 'middleware' => [ThrottleMiddleware::class]], function ($router) {
        $router->get('/{slug}', [ClaimController::class, 'show']);
        $router->get('/{slug}/board', [ClaimController::class, 'boardState']);
        $router->post('/{slug}/claim', [ClaimController::class, 'claim']);
    });

    if ($multiTenancyEnabled) {
        $router->get('/invite/accept', [OrganizationController::class, 'acceptInvite']);
    }

    $router->group(['middleware' => [AuthMiddleware::class]], function ($router) use ($multiTenancyEnabled) {
        $router->post('/settings/theme', [ThemeController::class, 'update']);

        $router->get('/settings/api-tokens', [ApiTokenController::class, 'index']);
        $router->post('/settings/api-tokens', [ApiTokenController::class, 'store']);
        $router->post('/settings/api-tokens/{id}/revoke', [ApiTokenController::class, 'destroy']);

        if ($multiTenancyEnabled) {
            $router->get('/onboarding/organization', [OrganizationController::class, 'showOnboarding']);
            $router->post('/onboarding/organization', [OrganizationController::class, 'createOrganization']);

            $router->group(['middleware' => [RequireOrgAdminMiddleware::class]], function ($router) {
                $router->get('/settings/organization', [OrganizationController::class, 'showSettings']);
                $router->get('/settings/members', [OrganizationController::class, 'showMembers']);
                $router->get('/settings/activity', [ActivityController::class, 'orgIndex']);
                $router->post('/settings/members/invite', [OrganizationController::class, 'sendInvite']);
            });

            $router->group(['middleware' => [RequireSuperAdminMiddleware::class]], function ($router) {
                $router->get('/super-admin/organizations', [SuperAdminController::class, 'index']);
                $router->get('/super-admin/organizations/{id}', [SuperAdminController::class, 'showOrganization']);
                $router->get('/super-admin/activity', [ActivityController::class, 'platformIndex']);
            });
        }

        $applicationMiddleware = $multiTenancyEnabled ? [RequireOrganizationMiddleware::class] : [];

        $router->group(['middleware' => $applicationMiddleware], function ($router) {
            $router->get('/dashboard', [DashboardController::class, 'index']);
            $router->get('/billing/upgrade', [BillingController::class, 'showPlans']);
            $router->get('/billing/success', [BillingController::class, 'success']);
            $router->get('/billing/cancel', [BillingController::class, 'cancel']);
            $router->post('/billing/checkout', [BillingController::class, 'checkout']);
            $router->post('/billing/portal', [BillingController::class, 'portal']);
            $router->post('/files', [FileController::class, 'store']);
            $router->get('/files/{id}', [FileController::class, 'show']);

            // Dealer admin. Every action below resolves the tenant from the signed-in
            // user and scopes its queries to it.
            $router->group(['prefix' => '/admin'], function ($router) {
                $router->get('/campaigns', [CampaignController::class, 'index']);
                $router->get('/campaigns/create', [CampaignController::class, 'create']);
                $router->post('/campaigns', [CampaignController::class, 'store']);
                $router->get('/campaigns/{id}/edit', [CampaignController::class, 'edit']);
                $router->post('/campaigns/{id}', [CampaignController::class, 'update']);

                $router->get('/campaigns/{id}/boards/create', [BoardController::class, 'create']);
                $router->post('/campaigns/{id}/boards', [BoardController::class, 'store']);
                $router->get('/boards/{id}/edit', [BoardController::class, 'edit']);
                $router->post('/boards/{id}', [BoardController::class, 'update']);
                $router->post('/boards/{id}/lock', [BoardController::class, 'lock']);
                $router->post('/boards/{id}/scores', [BoardController::class, 'updateScores']);
                $router->get('/boards/{id}/claims', [BoardController::class, 'claims']);
                $router->get('/boards/{id}/claims.csv', [BoardController::class, 'claimsCsv']);

                $router->post('/boards/{id}/prizes', [PrizeController::class, 'store']);
                $router->post('/boards/{id}/prizes/{period}/delete', [PrizeController::class, 'destroy']);
                $router->post('/prize-library/{id}/delete', [PrizeController::class, 'destroyLibraryItem']);

                $router->get('/wins', [WinController::class, 'index']);
                $router->post('/wins/{id}/redeem', [WinController::class, 'redeem']);
            });
        });
    });
});

$router->group(['prefix' => '/api/v1', 'middleware' => [ThrottleMiddleware::class, ApiAuthMiddleware::class]], function ($router) {
    $router->get('/files', [ApiFileController::class, 'index']);
});

$router->post('/webhooks/stripe', [StripeWebhookController::class, 'handle']);

// Plivo webhooks sit outside CSRF; each verifies the Plivo V2 signature instead.
$router->post('/webhooks/plivo/status', [PlivoStatusController::class, 'status']);
$router->post('/webhooks/plivo/inbound', [PlivoStatusController::class, 'inbound']);
