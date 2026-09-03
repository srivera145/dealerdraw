<?php

namespace Keel\App\Jobs;

use Keel\App\Models\Prize;
use Keel\App\Models\Win;
use Keel\Core\Env;
use Keel\Core\Mailer;

/**
 * Delivers the redemption code to the winner over the channels they consented to.
 * Re-running is safe: a win that already has notified_at set is skipped.
 */
class NotifyWinnerJob implements Job
{
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

        if (!empty($win['notified_at'])) {
            return;
        }

        $delivered = false;

        if ((int) $win['consent_email'] === 1 && trim((string) $win['email']) !== '') {
            $delivered = $this->sendEmail($win) || $delivered;
        }

        if ((int) $win['consent_sms'] === 1 && trim((string) $win['phone']) !== '') {
            $delivered = $this->sendSms($win) || $delivered;
        }

        if (!$delivered) {
            // No consented channel is a settled outcome, not a failure to retry.
            error_log('[DealerDraw] No delivered notification channel for win ' . $winId);
        }

        Win::markNotified($winId);
    }

    private function sendEmail(array $win): bool
    {
        $periodLabel = Prize::PERIOD_LABELS[$win['scoring_period']] ?? (string) $win['scoring_period'];
        $name = trim((string) $win['first_name'] . ' ' . (string) $win['last_name']);
        $subject = 'You won: ' . (string) $win['prize_label'];

        return Mailer::send((string) $win['email'], $name, $subject, $this->emailTemplate($win, $periodLabel));
    }

    private function emailTemplate(array $win, string $periodLabel): string
    {
        $appName = htmlspecialchars((string) Env::get('APP_NAME', 'DealerDraw'), ENT_QUOTES, 'UTF-8');
        $firstName = htmlspecialchars((string) $win['first_name'], ENT_QUOTES, 'UTF-8');
        $prizeLabel = htmlspecialchars((string) $win['prize_label'], ENT_QUOTES, 'UTF-8');
        $campaignName = htmlspecialchars((string) $win['campaign_name'], ENT_QUOTES, 'UTF-8');
        $matchup = htmlspecialchars((string) $win['away_team'] . ' at ' . (string) $win['home_team'], ENT_QUOTES, 'UTF-8');
        $period = htmlspecialchars($periodLabel, ENT_QUOTES, 'UTF-8');
        $code = htmlspecialchars((string) $win['redemption_code'], ENT_QUOTES, 'UTF-8');
        $expiresDays = (int) $win['expires_days'];
        $terms = htmlspecialchars(trim((string) ($win['terms_text'] ?? '')), ENT_QUOTES, 'UTF-8');
        $termsBlock = $terms !== ''
            ? '<p style="color: #9ca3af; font-size: 13px;">' . $terms . '</p>'
            : '';

        return <<<HTML
        <div style="font-family: Arial, sans-serif; max-width: 480px; margin: 0 auto; padding: 32px;">
            <h2 style="color: #111827;">Congratulations, {$firstName}!</h2>
            <p style="color: #4b5563; font-size: 15px;">Your square won the <strong>{$period}</strong> prize in {$campaignName} ({$matchup}).</p>
            <p style="color: #111827; font-size: 18px; font-weight: 600; margin-bottom: 4px;">{$prizeLabel}</p>
            <p style="color: #4b5563; font-size: 15px; margin-top: 0;">Show this code at the service counter:</p>
            <p style="display: inline-block; background: #111827; color: #ffffff; padding: 12px 24px; border-radius: 8px; font-size: 22px; letter-spacing: 3px; font-weight: 700; margin: 8px 0;">{$code}</p>
            <p style="color: #4b5563; font-size: 14px;">Redeem within {$expiresDays} days.</p>
            {$termsBlock}
            <p style="color: #9ca3af; font-size: 13px;">No purchase was necessary to enter or win. Sent by {$appName}.</p>
        </div>
        HTML;
    }

    /**
     * EchoDial SMS send. Falls back to the mail log style behaviour - a logged
     * line - when the endpoint is not configured, so local runs stay quiet.
     */
    private function sendSms(array $win): bool
    {
        $periodLabel = Prize::PERIOD_LABELS[$win['scoring_period']] ?? (string) $win['scoring_period'];
        $message = sprintf(
            '%s: your square won the %s prize - %s. Code %s. Redeem within %d days. No purchase necessary. Reply STOP to opt out.',
            (string) Env::get('APP_NAME', 'DealerDraw'),
            $periodLabel,
            (string) $win['prize_label'],
            (string) $win['redemption_code'],
            (int) $win['expires_days']
        );

        $endpoint = trim((string) Env::get('ECHODIAL_SMS_URL', ''));
        $apiKey = trim((string) Env::get('ECHODIAL_API_KEY', ''));

        if ($endpoint === '' || $apiKey === '') {
            error_log('[DealerDraw] SMS not configured; would send to ' . $win['phone'] . ': ' . $message);

            return false;
        }

        $payload = json_encode([
            'to' => (string) $win['phone'],
            'from' => (string) Env::get('ECHODIAL_SMS_FROM', ''),
            'body' => $message,
        ], JSON_THROW_ON_ERROR);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Authorization: Bearer ' . $apiKey,
                ]),
                'content' => $payload,
                'timeout' => 8,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($endpoint, false, $context);

        if ($response === false) {
            error_log('[DealerDraw] EchoDial SMS send failed for win ' . $win['id']);

            return false;
        }

        return true;
    }
}
