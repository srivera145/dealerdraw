<?php

namespace Keel\App\Jobs;

use Keel\App\Models\Prize;
use Keel\App\Models\SmsOptOut;
use Keel\App\Models\Win;
use Keel\App\Services\Sms\PhoneNumber;
use Keel\App\Services\Sms\PlivoClient;
use Keel\Core\Env;
use Keel\Core\Mailer;
use Keel\Core\View;

/**
 * Delivers a redemption code to a winner over the channels they consented to.
 *
 * Idempotency is enforced by an atomic claim on notified_at rather than a read
 * and a later write, so a re-run - or a second worker - cannot send twice. If
 * every channel fails outright the claim is released so the queue can retry.
 *
 * Email and SMS are independent: an SMS that is suppressed, unsendable, or
 * rejected never stops the email from going out.
 */
class NotifyWinnerJob implements Job
{
    private PlivoClient $sms;

    public function __construct(?PlivoClient $sms = null)
    {
        $this->sms = $sms ?? new PlivoClient();
    }

    public function handle(array $data): void
    {
        $winId = (int) ($data['win_id'] ?? 0);

        if ($winId <= 0) {
            throw new \RuntimeException('NotifyWinnerJob requires a win_id.');
        }

        $win = Win::notificationPayload($winId);

        if ($win === null) {
            throw new \RuntimeException("NotifyWinnerJob could not load win {$winId}.");
        }

        // An unclaimed winning square has no recipient. It is flagged for the
        // dealer at resolution time; there is nothing to send.
        if (empty($win['claim_id'])) {
            Win::recordDelivery($winId, [
                'sms_status' => 'skipped_unclaimed',
                'email_status' => 'skipped_unclaimed',
            ]);
            Win::claimNotification($winId);

            return;
        }

        // The guard. A win that is already notified stops right here, before any
        // Plivo call is made.
        if (!Win::claimNotification($winId)) {
            return;
        }

        $smsResult = $this->deliverSms($win);
        $emailResult = $this->deliverEmail($win);

        $delivered = $smsResult['status'] === 'sent' || $emailResult['status'] === 'sent';

        // Report both channels: an SMS error must not hide why the email failed.
        $errors = [];

        if ($smsResult['error'] !== null) {
            $errors[] = 'sms: ' . $smsResult['error'];
        }

        if ($emailResult['error'] !== null) {
            $errors[] = 'email: ' . $emailResult['error'];
        }

        $error = $errors === [] ? null : implode('; ', $errors);

        Win::recordDelivery($winId, [
            'sms_message_uuid' => $smsResult['message_uuid'],
            'sms_status' => $smsResult['status'],
            'email_status' => $emailResult['status'],
            // Kept even when the other channel got through: a dealer looking at
            // "sms: failed" needs to see why it failed.
            'notify_error' => $error,
        ]);

        // Nothing got through and something was actually attempted: hand the
        // notification back so the queue's retry can have another go.
        if (!$delivered && $this->wasAttempted($smsResult, $emailResult)) {
            Win::releaseNotification($winId, (string) $error);

            throw new \RuntimeException('Winner notification failed for win ' . $winId . ': ' . $error);
        }
    }

    /**
     * @return array{status: string, message_uuid: ?string, error: ?string}
     */
    private function deliverSms(array $win): array
    {
        if ((int) $win['consent_sms'] !== 1) {
            return $this->channel('skipped_no_consent');
        }

        $tenantId = (int) $win['tenant_id'];
        $destination = PhoneNumber::toE164((string) $win['phone']);

        if ($destination === null) {
            // Refused, not attempted: a number we cannot normalise is a data
            // problem, and retrying it will not fix anything.
            error_log(sprintf(
                '[DealerDraw] Win %d has an unusable phone number; SMS refused.',
                (int) $win['id']
            ));

            return $this->channel('invalid_number', null, 'Destination number failed E.164 validation.');
        }

        // Tenant-wide suppression: a STOP on any board of this dealership.
        if (SmsOptOut::isOptedOut($tenantId, $destination)) {
            error_log(sprintf(
                '[DealerDraw] Win %d suppressed: %s opted out of tenant %d.',
                (int) $win['id'],
                PhoneNumber::mask($destination),
                $tenantId
            ));

            return $this->channel('skipped_opted_out');
        }

        if (!PlivoClient::isConfigured()) {
            return $this->channel('not_configured', null, 'Plivo is not configured.');
        }

        $result = $this->sms->send($destination, $this->messageBody($win));

        if (!$result['sent']) {
            error_log(sprintf(
                '[DealerDraw] Plivo send failed for win %d to %s: %s',
                (int) $win['id'],
                PhoneNumber::mask($destination),
                (string) $result['error']
            ));

            return $this->channel('failed', null, (string) $result['error']);
        }

        return $this->channel('sent', $result['message_uuid']);
    }

    /**
     * @return array{status: string, message_uuid: ?string, error: ?string}
     */
    private function deliverEmail(array $win): array
    {
        if ((int) $win['consent_email'] !== 1) {
            return $this->channel('skipped_no_consent');
        }

        $address = trim((string) $win['email']);

        if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            return $this->channel('invalid_address', null, 'Winner email address is not valid.');
        }

        $name = trim((string) $win['first_name'] . ' ' . (string) $win['last_name']);
        $subject = 'You won: ' . (string) $win['prize_label'];

        try {
            $sent = Mailer::send($address, $name, $subject, $this->renderEmail($win));
        } catch (\Throwable $exception) {
            return $this->channel('failed', null, $exception->getMessage());
        }

        return $sent ? $this->channel('sent') : $this->channel('failed', null, 'Mailer rejected the message.');
    }

    /**
     * SMS body: dealer, prize, code, expiry, and the STOP instruction carriers
     * expect on every promotional message.
     */
    private function messageBody(array $win): string
    {
        return sprintf(
            '%s: you won %s! Code %s. Redeem by %s. No purchase necessary. Reply STOP to opt out.',
            (string) $win['dealer_name'],
            (string) $win['prize_label'],
            (string) $win['redemption_code'],
            Win::expiresAt($win)
        );
    }

    private function renderEmail(array $win): string
    {
        $matchup = trim((string) $win['away_team']) === ''
            ? ''
            : (string) $win['away_team'] . ' at ' . (string) $win['home_team'];

        ob_start();

        try {
            View::render('emails.winner', [
                'dealerName' => (string) ($win['dealer_name'] ?? Env::get('APP_NAME', 'DealerDraw')),
                'firstName' => (string) $win['first_name'],
                'campaignName' => (string) $win['campaign_name'],
                'matchup' => $matchup,
                'periodLabel' => Prize::PERIOD_LABELS[$win['scoring_period']] ?? (string) $win['scoring_period'],
                'prizeLabel' => (string) $win['prize_label'],
                'redemptionCode' => (string) $win['redemption_code'],
                'expiresOn' => date('F j, Y', strtotime(Win::expiresAt($win)) ?: time()),
                'retailValue' => $win['retail_value'] ?? null,
                'prizeTerms' => $win['terms_text'] ?? null,
                'campaignTerms' => $win['campaign_terms'] ?? null,
            ]);
        } catch (\Throwable $exception) {
            ob_end_clean();

            throw $exception;
        }

        return (string) ob_get_clean();
    }

    /**
     * A channel counts as attempted only when something could actually have
     * been delivered - a skip for consent or an opt-out is a settled outcome,
     * not a failure worth retrying.
     *
     * @param array{status: string} $smsResult
     * @param array{status: string} $emailResult
     */
    private function wasAttempted(array $smsResult, array $emailResult): bool
    {
        $retryable = ['failed'];

        return in_array($smsResult['status'], $retryable, true)
            || in_array($emailResult['status'], $retryable, true);
    }

    /**
     * @return array{status: string, message_uuid: ?string, error: ?string}
     */
    private function channel(string $status, ?string $messageUuid = null, ?string $error = null): array
    {
        return ['status' => $status, 'message_uuid' => $messageUuid, 'error' => $error];
    }
}
