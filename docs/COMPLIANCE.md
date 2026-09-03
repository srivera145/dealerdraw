# Compliance

**This documents what the code enforces and why. It is not legal advice.** Sweepstakes and messaging
rules are state-specific and change; EchoDial's own counsel signs off on the rules text, and each
dealer should have theirs review before a first board goes live.

The point of this file is narrower and more useful: if you are changing this code, these are the
constraints that are load-bearing, and the reason each one is not a setting.

---

## 1. No purchase necessary — and no way to require one

**The rule.** A promotion with a prize, awarded by chance, becomes an illegal lottery in most US
states when entrants also give *consideration* — money, a purchase, a qualifying transaction. Remove
consideration and the same prize awarded by the same draw is a sweepstakes.

**What the code does.** There is no price field on a board, no payment integration on the claim path,
and no entry-gating of any kind. Stripe keys still exist in `.env` because Keel's controllers read
them, but nothing in the claim flow touches billing. `views/public/claim.php` renders

> No purchase necessary. Free to enter.

directly above the grid, unconditionally. It is not driven by a column, a config value or a feature
flag, and there is no admin control that hides it.

**Why it is not an option.** A dealer under pressure to lift redemption rates will ask for "entries
for customers with an open RO" or "an extra square with an oil change". Either one re-introduces
consideration and changes the legal character of the promotion. Making it impossible in the schema is
cheaper than making it a policy somebody has to remember.

**If you are changing this code:** do not add a price, a purchase requirement, an entry-earning
mechanic, or a switch that hides the disclosure. A feature test asserts the disclosure renders above
the grid on every board.

---

## 2. No entry fee

`claims` has no amount column. `squares` has no price. The public claim controller collects name,
email, phone and consent — nothing else, and no payment fields exist on the form.

The per-person limit (`boards.claim_limit`, default 5) exists to keep one person from taking a whole
board, not to create scarcity worth paying to escape. It is counted in squares held, matched on
email **or** phone, so re-submitting the form does not reset the allowance.

---

## 3. No cash prizes

Prizes are dealer-supplied service offers. `prizes` stores a `label`, a `retail_value` used for
display and for state registration thresholds, `terms_text`, and `expires_days`.

Nothing pays out money. The winner email and SMS carry a redemption code that an advisor marks used
at the counter; there is no disbursement path, no balance, and no transfer. Both the claim page and
the winner email state that prizes have no cash value.

`retail_value` matters beyond display: a few states require sweepstakes registration above a total
prize-value threshold. Summed retail value per board is the number that gets compared against those
thresholds, so keep it accurate.

---

## 4. Digits stay hidden until the board locks

**The rule.** The draw has to be genuinely random and genuinely after entries close. If the numbers
were knowable while people were still claiming, the promotion is not what it says it is.

**What the code does.** `boards.row_digits` and `boards.col_digits` are `NULL` until lock — the values
do not exist to leak. `Board::digits()` returns `null` unless `locked_at` is set, so the JSON payload
sends `"digits": null` and the grid renders blank axis headers.

`BoardLockService::lock()` assigns them with a Fisher-Yates shuffle driven by `random_int`,
deliberately not `shuffle()`, which uses a predictable PRNG sequence. Locking is one-way: the update
carries `WHERE status = 'open' AND locked_at IS NULL`, so a second lock is refused and digits are
never redrawn under claims that are already public.

**If you are changing this code:** never add a code path that writes digits before lock, and never
add one that rewrites them after. Feature tests assert both.

---

## 5. SMS opt-out is honoured tenant-wide

**The rule.** A recipient who opts out must stop receiving messages, and the opt-out must be honoured
promptly and completely.

**What the code does.** An inbound STOP arrives at `/webhooks/plivo/inbound`. The number is
normalised to E.164 and written to `sms_opt_outs`, keyed on `(tenant_id, phone_e164)` — the
*dealership*, not the board it replied to. `SmsOptOut::revokeClaimConsent()` also sets `consent_sms = 0`
on every matching claim, so exports and admin screens agree with the suppression list.

Before every send, `NotifyWinnerJob` checks `SmsOptOut::isOptedOut()` and records `skipped_opted_out`
rather than dialling out. Email is unaffected: an SMS opt-out is not an email opt-out.

**One design decision worth knowing.** A single Plivo sender number serves every dealership in this
build, and an inbound reply carries no board or tenant id. A STOP is therefore applied to every
tenant that has ever messaged that number — anything narrower would keep texting a customer from the
number they just tried to stop. If you move to per-dealer sender numbers, narrow this to the tenant
that owns the receiving number.

STOP keywords, and the START keywords that reverse it, are listed in `SmsOptOut::STOP_KEYWORDS` /
`START_KEYWORDS`.

---

## 6. Consent is recorded, not assumed

Every claim stores `consent_sms` and `consent_email` as separate booleans, plus `ip` and
`created_at`. The checkboxes are not pre-ticked into anything, the wording next to them names the
dealership, states message frequency, says message and data rates may apply, and explains how to
stop.

The claim form requires at least one contact channel — otherwise a winner cannot be told they won.
That is a delivery requirement, not a purchase requirement; entry itself remains free either way.

Consent is not a condition of buying anything, and there is nothing to buy.

---

## 7. 10DLC registration is required before production texting

**The rule.** US carriers require application-to-person traffic on ten-digit long codes to be
registered — a brand, then a campaign describing the use case and how consent is collected.

**What happens without it.** Traffic is filtered. The failure is silent: Plivo reports the message as
accepted, the carrier drops it, and nobody sees an error. A dealership concludes texting does not
work for them.

**What the code does — and does not.** The app does not and cannot register on your behalf. It is
built to satisfy what a campaign registration has to describe: an explicit unticked consent checkbox
on the public claim form, stored consent records with timestamps, the dealership named in every
message, an opt-out instruction on every message, and automatic STOP handling.

**Before production:** complete brand and campaign registration through Plivo, and make the consent
description in the campaign match the real claim form — reviewers check, and a mismatch gets the
campaign rejected. Have a screenshot of the live opt-in and the exact checkbox wording ready.

10DLC and the TCPA are separate obligations. Registration governs deliverability; the TCPA governs
consent. Neither substitutes for the other.

---

## What the code cannot enforce

Being explicit about the gaps, because they are the ones that will bite:

- **Official rules content.** `campaigns.terms_text` is free text rendered on the claim page. Nothing
  validates that a dealer wrote adequate rules, or any.
- **State registration.** Nothing checks prize totals against Florida, New York or Rhode Island
  thresholds, or files anything. That is a human step.
- **Eligibility.** No age or residency check beyond whatever the rules text says.
- **Prize delivery.** The app issues and tracks a code; nothing verifies the dealer honours it.
- **Message content beyond the templates.** The winner SMS and email templates are compliant as
  written. Anything sent through another channel is outside this codebase.

---

## Tests that hold these in place

| Constraint | Test |
| --- | --- |
| Disclosure renders above the grid, always | `ClaimPageFeatureTest`, `SquaresClaimFeatureTest` |
| Digits absent before lock, in payload and page | `SquaresBoardLockFeatureTest` |
| Lock is one-way, digits never redrawn | `SquaresBoardLockFeatureTest` |
| Claim limit per person, matched on email or phone | `SquaresClaimFeatureTest` |
| STOP suppresses across a tenant's other boards | `WinnerNotificationFeatureTest` |
| Consent required, recorded, and revoked on STOP | `SquaresClaimFeatureTest`, `WinnerNotificationFeatureTest` |
| No duplicate notification on re-run | `WinnerNotificationFeatureTest` |
| Credentials never reach logs or stored errors | `PlivoClientTest`, `WinnerNotificationFeatureTest` |

If you change behaviour in this area, change the test in the same commit — the test is the record of
what was intended.
