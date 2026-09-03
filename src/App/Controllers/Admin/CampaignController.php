<?php

namespace Keel\App\Controllers\Admin;

use Keel\App\Models\Board;
use Keel\App\Models\Campaign;
use Keel\App\Models\CampaignType;
use Keel\App\Models\Game;
use Keel\Core\Request;
use Keel\Core\Storage;

class CampaignController extends AdminController
{
    public function index(Request $request): void
    {
        $tenantId = $this->tenantId();

        $this->view('admin.campaigns.index', [
            'title' => 'Campaigns',
            'campaigns' => Campaign::forTenant($tenantId),
            'notice' => (string) $request->input('notice', ''),
            'error' => (string) $request->input('error', ''),
        ]);
    }

    public function create(Request $request): void
    {
        $this->tenantId();

        $this->view('admin.campaigns.create', [
            'title' => 'New Campaign',
            'campaignTypes' => CampaignType::active(),
            'statuses' => Campaign::STATUSES,
            'error' => (string) $request->input('error', ''),
        ]);
    }

    public function store(Request $request): void
    {
        $tenantId = $this->tenantId();

        $name = trim((string) $request->input('name', ''));
        $campaignTypeId = (int) $request->input('campaign_type_id', 0);

        if ($name === '') {
            $this->backWith('/admin/campaigns/create', 'error', 'Campaign name is required.');
        }

        $campaignType = CampaignType::find($campaignTypeId);

        if ($campaignType === null || (int) $campaignType['active'] !== 1) {
            $this->backWith('/admin/campaigns/create', 'error', 'Choose an active game type.');
        }

        $campaignId = Campaign::create([
            'tenant_id' => $tenantId,
            'campaign_type_id' => $campaignTypeId,
            'name' => $name,
            'status' => $this->normalizeStatus((string) $request->input('status', 'draft')),
            'starts_at' => $this->normalizeDateTime((string) $request->input('starts_at', '')),
            'ends_at' => $this->normalizeDateTime((string) $request->input('ends_at', '')),
            'brand_primary_color' => $this->normalizeColor((string) $request->input('brand_primary_color', '')),
            'brand_logo_path' => $this->storeLogo($tenantId),
            'public_slug' => Campaign::generateSlug($name),
            'terms_text' => $this->normalizeText((string) $request->input('terms_text', '')),
        ]);

        $this->redirect('/admin/campaigns/' . $campaignId . '/edit?notice=' . urlencode('Campaign created.'));
    }

    public function edit(Request $request, string $id): void
    {
        $tenantId = $this->tenantId();
        $campaign = Campaign::findForTenant((int) $id, $tenantId);

        if ($campaign === null) {
            $this->backWith('/admin/campaigns', 'error', 'Campaign not found.');
        }

        $this->view('admin.campaigns.edit', [
            'title' => $campaign['name'],
            'campaign' => $campaign,
            'boards' => Board::forCampaign((int) $campaign['id']),
            'games' => Game::upcoming(),
            'statuses' => Campaign::STATUSES,
            'notice' => (string) $request->input('notice', ''),
            'error' => (string) $request->input('error', ''),
        ]);
    }

    public function update(Request $request, string $id): void
    {
        $tenantId = $this->tenantId();
        $campaign = Campaign::findForTenant((int) $id, $tenantId);

        if ($campaign === null) {
            $this->backWith('/admin/campaigns', 'error', 'Campaign not found.');
        }

        $name = trim((string) $request->input('name', ''));

        if ($name === '') {
            $this->backWith('/admin/campaigns/' . (int) $id . '/edit', 'error', 'Campaign name is required.');
        }

        $logoPath = $this->storeLogo($tenantId) ?? $campaign['brand_logo_path'];

        Campaign::update((int) $id, $tenantId, [
            'name' => $name,
            'status' => $this->normalizeStatus((string) $request->input('status', (string) $campaign['status'])),
            'starts_at' => $this->normalizeDateTime((string) $request->input('starts_at', '')),
            'ends_at' => $this->normalizeDateTime((string) $request->input('ends_at', '')),
            'brand_primary_color' => $this->normalizeColor((string) $request->input('brand_primary_color', '')),
            'brand_logo_path' => $logoPath,
            'terms_text' => $this->normalizeText((string) $request->input('terms_text', '')),
        ]);

        $this->backWith('/admin/campaigns/' . (int) $id . '/edit', 'notice', 'Campaign saved.');
    }

    private function normalizeStatus(string $status): string
    {
        return in_array($status, Campaign::STATUSES, true) ? $status : 'draft';
    }

    private function normalizeDateTime(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }

    private function normalizeColor(string $value): ?string
    {
        $value = trim($value);

        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1 ? strtolower($value) : null;
    }

    private function normalizeText(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Dealer logos live on the public disk because the claim page is public.
     */
    private function storeLogo(int $tenantId): ?string
    {
        $upload = $_FILES['brand_logo'] ?? null;

        if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        try {
            $stored = Storage::putUploadedFile($upload, 'brand/' . $tenantId, true);
        } catch (\RuntimeException $exception) {
            error_log('[DealerDraw] Logo upload rejected: ' . $exception->getMessage());

            return null;
        }

        return $stored['path'];
    }
}
