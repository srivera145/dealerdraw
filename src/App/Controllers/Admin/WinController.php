<?php

namespace Keel\App\Controllers\Admin;

use Keel\App\Models\Prize;
use Keel\App\Models\Win;
use Keel\Core\Request;
use Keel\Core\Session;

class WinController extends AdminController
{
    public function index(Request $request): void
    {
        $tenantId = $this->tenantId();
        $boardId = (int) $request->input('board_id', 0);

        $this->view('admin.wins.index', [
            'title' => 'Winners',
            'wins' => Win::forTenant($tenantId, $boardId > 0 ? $boardId : null),
            'periodLabels' => Prize::PERIOD_LABELS,
            'notice' => (string) $request->input('notice', ''),
            'error' => (string) $request->input('error', ''),
        ]);
    }

    /**
     * Advisor marks a redemption code as used at the counter.
     */
    public function redeem(Request $request, string $id): void
    {
        $tenantId = $this->tenantId();
        $userId = (int) Session::get('user_id');

        if (Win::findForTenant((int) $id, $tenantId) === null) {
            $this->backWith('/admin/wins', 'error', 'Winner not found.');
        }

        if (!Win::markRedeemed((int) $id, $tenantId, $userId)) {
            $this->backWith('/admin/wins', 'error', 'That code was already redeemed.');
        }

        $this->backWith('/admin/wins', 'notice', 'Code marked redeemed.');
    }
}
