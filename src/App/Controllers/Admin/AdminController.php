<?php

namespace Keel\App\Controllers\Admin;

use Keel\App\Models\User;
use Keel\Core\Controller;
use Keel\Core\Session;

/**
 * Base for every dealer-facing admin screen. tenantId() is the single place the
 * current dealership is resolved, and every model call below it takes that id.
 */
abstract class AdminController extends Controller
{
    protected function currentUser(): array
    {
        $user = User::find((int) Session::get('user_id'));

        if ($user === null) {
            $this->redirect('/login');
        }

        return $user;
    }

    protected function tenantId(): int
    {
        $tenantId = (int) ($this->currentUser()['organization_id'] ?? 0);

        if ($tenantId <= 0) {
            $this->redirect('/onboarding/organization');
        }

        return $tenantId;
    }

    /**
     * Query-string notices, matching how the rest of the app reports outcomes.
     */
    protected function backWith(string $path, string $key, string $value): never
    {
        $separator = str_contains($path, '?') ? '&' : '?';

        $this->redirect($path . $separator . $key . '=' . urlencode($value));
    }
}
