<?php
/**
 * Winner notification email. Rendered to a string by NotifyWinnerJob, so this
 * file must echo the document and nothing else.
 *
 * @var string  $dealerName
 * @var string  $firstName
 * @var string  $campaignName
 * @var string  $matchup
 * @var string  $periodLabel
 * @var string  $prizeLabel
 * @var string  $redemptionCode
 * @var string  $expiresOn
 * @var ?string $retailValue
 * @var ?string $prizeTerms
 * @var ?string $campaignTerms
 */
$dealerName = (string) ($dealerName ?? '');
$firstName = (string) ($firstName ?? 'there');
$campaignName = (string) ($campaignName ?? '');
$matchup = (string) ($matchup ?? '');
$periodLabel = (string) ($periodLabel ?? '');
$prizeLabel = (string) ($prizeLabel ?? '');
$redemptionCode = (string) ($redemptionCode ?? '');
$expiresOn = (string) ($expiresOn ?? '');
$retailValue = $retailValue ?? null;
$prizeTerms = trim((string) ($prizeTerms ?? ''));
$campaignTerms = trim((string) ($campaignTerms ?? ''));
?>
<div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; max-width: 520px; margin: 0 auto; padding: 32px 24px; color: #111827;">
    <p style="margin: 0 0 8px; font-size: 13px; letter-spacing: 0.06em; text-transform: uppercase; color: #6b7280;">
        <?= htmlspecialchars($dealerName, ENT_QUOTES, 'UTF-8') ?>
    </p>

    <h1 style="margin: 0 0 16px; font-size: 24px; line-height: 1.25;">
        Congratulations, <?= htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') ?>!
    </h1>

    <p style="margin: 0 0 20px; font-size: 15px; line-height: 1.6; color: #374151;">
        Your square won the <strong><?= htmlspecialchars($periodLabel, ENT_QUOTES, 'UTF-8') ?></strong>
        prize in <?= htmlspecialchars($campaignName, ENT_QUOTES, 'UTF-8') ?><?= $matchup === '' ? '' : ' (' . htmlspecialchars($matchup, ENT_QUOTES, 'UTF-8') . ')' ?>.
    </p>

    <div style="border: 1px solid #d1d5db; border-radius: 12px; padding: 20px; margin-bottom: 20px;">
        <p style="margin: 0 0 4px; font-size: 18px; font-weight: 700;">
            <?= htmlspecialchars($prizeLabel, ENT_QUOTES, 'UTF-8') ?>
        </p>

        <?php if ($retailValue !== null && $retailValue !== ''): ?>
        <p style="margin: 0 0 16px; font-size: 13px; color: #6b7280;">
            Retail value $<?= htmlspecialchars(number_format((float) $retailValue, 2), ENT_QUOTES, 'UTF-8') ?>
        </p>
        <?php endif; ?>

        <p style="margin: 0 0 8px; font-size: 14px; color: #374151;">Show this code at the service counter:</p>
        <p style="margin: 0 0 12px; display: inline-block; background: #111827; color: #ffffff; padding: 12px 24px; border-radius: 10px; font-size: 24px; font-weight: 700; letter-spacing: 4px; font-family: 'SFMono-Regular', Consolas, monospace;">
            <?= htmlspecialchars($redemptionCode, ENT_QUOTES, 'UTF-8') ?>
        </p>
        <p style="margin: 0; font-size: 14px; color: #374151;">
            Redeem by <strong><?= htmlspecialchars($expiresOn, ENT_QUOTES, 'UTF-8') ?></strong>.
        </p>
    </div>

    <?php if ($prizeTerms !== ''): ?>
    <p style="margin: 0 0 12px; font-size: 12px; line-height: 1.6; color: #6b7280;">
        <?= htmlspecialchars($prizeTerms, ENT_QUOTES, 'UTF-8') ?>
    </p>
    <?php endif; ?>

    <?php if ($campaignTerms !== ''): ?>
    <p style="margin: 0 0 12px; font-size: 12px; line-height: 1.6; color: #9ca3af; white-space: pre-line;">
        <?= htmlspecialchars($campaignTerms, ENT_QUOTES, 'UTF-8') ?>
    </p>
    <?php endif; ?>

    <hr style="border: 0; border-top: 1px solid #e5e7eb; margin: 24px 0 16px;">

    <p style="margin: 0; font-size: 12px; line-height: 1.6; color: #9ca3af;">
        No purchase was necessary to enter or win. Prize is a dealer-supplied service
        offer with no cash value. Sent by <?= htmlspecialchars($dealerName, ENT_QUOTES, 'UTF-8') ?>
        because you entered this game.
    </p>
</div>
