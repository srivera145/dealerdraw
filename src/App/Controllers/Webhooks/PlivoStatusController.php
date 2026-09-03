<?php

namespace Keel\App\Controllers\Webhooks;

use Keel\App\Models\SmsOptOut;
use Keel\App\Models\Win;
use Keel\App\Services\Sms\PhoneNumber;
use Keel\App\Services\Sms\PlivoClient;
use Keel\Core\Controller;
use Keel\Core\Env;
use Keel\Core\Request;
use Keel\Core\Response;

/**
 * Plivo webhooks: delivery status callbacks and inbound messages.
 *
 * Both endpoints verify the Plivo V2 signature before touching anything. An
 * unsigned or badly signed request is refused - these routes sit outside CSRF,
 * so the signature is the only thing standing between the public internet and
 * the opt-out table.
 */
class PlivoStatusController extends Controller
{
    /**
     * Message status callback. Plivo posts MessageUUID and Status as the message
     * moves through queued -> sent -> delivered / undelivered / failed.
     */
    public function status(Request $request): never
    {
        if (!$this->verify($request)) {
            Response::abort(403, 'Invalid signature');
        }

        $messageUuid = trim((string) $request->input('MessageUUID', $request->input('MessageUuid', '')));
        $status = strtolower(trim((string) $request->input('Status', '')));

        if ($messageUuid === '' || $status === '') {
            Response::raw('', 200);
        }

        $matched = Win::recordSmsStatus($messageUuid, $status);

        if (!$matched) {
            // Not ours, or a status for a message sent before this build.
            error_log('[DealerDraw] Plivo status for unknown message ' . $messageUuid);
        }

        Response::raw('', 200);
    }

    /**
     * Inbound message. A STOP keyword suppresses the number and revokes SMS
     * consent on the matching claims; START puts it back.
     */
    public function inbound(Request $request): never
    {
        if (!$this->verify($request)) {
            Response::abort(403, 'Invalid signature');
        }

        $from = PhoneNumber::toE164((string) $request->input('From', ''));
        $text = strtolower(trim((string) $request->input('Text', '')));

        if ($from === null || $text === '') {
            Response::raw('', 200);
        }

        $keyword = preg_replace('/[^a-z ]/', '', $text) ?? '';
        $keyword = trim($keyword);

        $isStop = in_array($keyword, SmsOptOut::STOP_KEYWORDS, true);
        $isStart = in_array($keyword, SmsOptOut::START_KEYWORDS, true);

        if (!$isStop && !$isStart) {
            Response::raw('', 200);
        }

        // One Plivo sender number serves every dealership in this build, so a
        // consumer's STOP has to apply to every tenant that could text them from
        // it - anything narrower would keep messaging them from the same number.
        $tenantIds = SmsOptOut::tenantsForPhone($from);

        foreach ($tenantIds as $tenantId) {
            if ($isStop) {
                SmsOptOut::add($tenantId, $from, $keyword);
                SmsOptOut::revokeClaimConsent($tenantId, $from);

                continue;
            }

            SmsOptOut::remove($tenantId, $from);
        }

        error_log(sprintf(
            '[DealerDraw] Inbound %s from %s applied to %d tenant(s).',
            $isStop ? 'STOP' : 'START',
            PhoneNumber::mask($from),
            count($tenantIds)
        ));

        Response::raw('', 200);
    }

    /**
     * Plivo signs the exact URL it was configured with, so the URL is rebuilt
     * from APP_URL rather than from host headers an attacker controls.
     */
    private function verify(Request $request): bool
    {
        $appUrl = rtrim(trim((string) Env::get('APP_URL', '')), '/');

        if ($appUrl === '') {
            error_log('[DealerDraw] APP_URL is not set; refusing to verify a Plivo webhook.');

            return false;
        }

        $headers = $request->headers;

        foreach ($_SERVER as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_')) {
                $headers[substr((string) $key, 5)] = (string) $value;
            }
        }

        return PlivoClient::verifySignature($appUrl . $request->uri, $headers);
    }
}
