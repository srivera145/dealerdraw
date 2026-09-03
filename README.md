# DealerDraw

Free-entry promotional games for car dealerships. A dealer creates a game board tied to a real
football game, attaches their own service offers as prizes, and shares one link; customers claim
squares for free and the dealer collects opted-in phone numbers and email addresses. Squares is the
first game type — spin-to-win, scratch-offs, brackets and punch cards are planned, and the schema is
built so they slot in rather than get bolted on.

Dealers subscribe at $199/month per rooftop. Customers never pay anything, ever.

> **The free-entry constraint is not a config option.** There is no setting that adds an entry fee,
> requires a purchase, or hides the "No purchase necessary" disclosure. That is deliberate — it is
> what keeps a board a sweepstakes rather than a lottery. See [docs/COMPLIANCE.md](docs/COMPLIANCE.md).

---

## Stack

| Piece | Version / choice | Notes |
| --- | --- | --- |
| PHP | 8.2+ | Uses `match`, enums-as-const arrays, readonly promotion |
| MySQL | 8.0+ | Needs `JSON` columns and `ON DUPLICATE KEY UPDATE` |
| Framework | [Keel](composer.json) — in-repo MVC | Custom router, no Laravel/Symfony |
| Front end | Vanilla JS + Tailwind (admin) / hand-written CSS (public) | No framework, no jQuery |
| SMS | Plivo REST API | Winner notifications, inbound STOP |
| Scores | ESPN public scoreboard | No API key; one request per league-day |
| Queue | MySQL `jobs` table, polled by a PHP worker | No Redis, no Horizon |
| Mail | PHPMailer over SMTP, or a local log driver | |

---

## Quick start

Paste these in order. Nothing needs editing between steps.

```bash
git clone <repo-url> dealerdraw
cd dealerdraw
composer install
npm install && npm run build
cp .env.example .env
php database/migrate.php
php scripts/seed-demo.php
php -S 127.0.0.1:8000 -t public_html scripts/dev-server.php
```

`database/migrate.php` creates the database itself if the MySQL user has permission, so there is no
separate "create database" step.

Then open <http://127.0.0.1:8000>. Two things to know:

- **Set `APP_URL=http://127.0.0.1:8000` in `.env`** if you use the built-in server. The seeder and
  the marketing pages build absolute links from it, and Plivo signature verification depends on it.
- **Sign in** at `/login` as `owner@demo.test`. With `MAIL_MAILER=log` the code and magic link are
  written to `storage/logs/mail.log` — no SMTP account needed.

Why `scripts/dev-server.php` and not `public_html/index.php`? The app's front controller handles
every request it is given, so used directly as a router the built-in server never serves
`/assets/...` and the CSS 404s. The shim hands existing files back to the server, which is what
nginx and Apache do in production.

---

## Environment variables

Full list with comments in [.env.example](.env.example). The ones that matter:

| Key | Required to boot | Purpose |
| --- | --- | --- |
| `APP_URL` | **yes** | Absolute base URL. Canonical tags, sitemap, and Plivo signature verification all derive from it — a wrong value breaks webhooks silently |
| `APP_ENV` | no (`local`) | `production` disables debug output and blocks the demo seeder |
| `DB_HOST` `DB_PORT` `DB_DATABASE` `DB_USERNAME` `DB_PASSWORD` `DB_CHARSET` | **yes** | Connection. The pool also pins MySQL's session timezone to PHP's |
| `MULTI_TENANCY_ENABLED` | **yes** (`true`) | Must stay true; every dealer is an organization and all admin queries are tenant-scoped |
| `MAIL_MAILER` | no (`log`) | `log` writes to `storage/logs/mail.log`; use `smtp` in production |
| `MAIL_HOST` `MAIL_PORT` `MAIL_USERNAME` `MAIL_PASSWORD` `MAIL_ENCRYPTION` `MAIL_FROM_ADDRESS` `MAIL_FROM_NAME` | only with `smtp` | Outbound mail |
| `PLIVO_AUTH_ID` | no locally | Plivo account id. Blank means SMS is skipped and email still sends |
| `PLIVO_AUTH_TOKEN` | no locally | Plivo secret. Also the HMAC key for webhook signature verification |
| `PLIVO_SRC_NUMBER` | no locally | Sending number in E.164, e.g. `+15555550100` |
| `PLIVO_STATUS_CALLBACK_URL` | no | Delivery-status webhook. Blank means `APP_URL` + `/webhooks/plivo/status` |
| `SMS_DEFAULT_COUNTRY_CODE` | no (`1`) | Assumed for claim-form numbers typed without a prefix |
| `SCORES_FEED_USER_AGENT` | no | Override only if ESPN starts 403ing the built-in default |
| `LEAD_NOTIFICATION_EMAIL` | no | Where marketing-site demo requests go. Falls back to `MAIL_FROM_ADDRESS` |
| `AUTH_METHOD` | no (`both`) | `otp`, `magic_link` or `both` for dealer sign-in |

There is no score-provider toggle in env. The provider is selected in
`ScoreSyncService::provider()`; see Architecture below.

---

## Architecture

### Why `campaign_types` exists

`campaigns` is deliberately game-agnostic. It holds the tenant, the name, the status, the public
slug and the branding — nothing about squares. What kind of game a campaign runs is one foreign key,
`campaign_type_id`, pointing at a seeded row in `campaign_types` (`squares` today).

Everything squares-specific hangs off `boards`, which is the *instance* table for that game type:
`boards` → `squares` → `claims`, `prizes`, `wins`.

**To add a new game type**, e.g. spin-to-win:

1. Insert a row in `campaign_types` (`slug`, `name`, `active`). Nothing in `campaigns` changes.
2. Create an instance table for it — `wheels`, say — with a `campaign_id`, the same way `boards` has one.
3. Give it its own service (the equivalent of `BoardLockService` / `WinnerService`) and its own
   admin controller under `src/App/Controllers/Admin/`.
4. Branch on `campaign_type_slug` where a campaign is rendered, not inside the squares code.

**The one honest caveat:** `claims`, `prizes` and `wins` currently carry `board_id`, so they are tied
to the squares instance table. A second game type that needs its own entrants or prizes will need
either its own tables or a migration adding a polymorphic `instance_type`/`instance_id` pair. That
was a deliberate trade for a simpler first build — do not discover it late.

### Layout

```
src/App/Models/          One class per table. Static methods, arrays in and out, no ORM.
src/App/Services/        Game logic: BoardLockService, WinnerService, ScoreSyncService.
src/App/Services/Sms/    PlivoClient, PhoneNumber (E.164).
src/App/Services/Providers/  ScoreProvider interface + EspnScoreProvider.
src/App/Jobs/            Queue jobs: SyncScoresJob, NotifyWinnerJob.
src/App/Console/Commands/    CLI commands. Thin runners live in scripts/.
src/App/Content/         Marketing copy registry (FAQ + guides).
src/Core/                Keel framework. Touch sparingly.
views/                   admin/, public/, emails/.
```

Tenancy rule: every admin-facing model read takes a `$tenantId` and joins through `campaigns`.
There is deliberately no `Board::findById()` without one. Public routes resolve the tenant from the
campaign slug and nothing else.

---

## Key flows

### 1. Claim → lock → digit assignment

A customer opens `/p/{slug}`, taps open squares and submits name, email, mobile and a consent box.
`Square::assign()` is a conditional `UPDATE ... WHERE claim_id IS NULL`, so of two people submitting
the same square exactly one wins; the loser's whole submission rolls back and the response names the
squares that went, leaving their other picks selected.

The row and column digits **do not exist** until the board locks. The columns are null, the JSON
payload sends `"digits": null`, and the grid renders blank axis headers. At kickoff
`ScoreSyncService::lockBoardsAtKickoff()` (or the admin lock button) calls `BoardLockService::lock()`,
which shuffles 0–9 with `random_int` — not `shuffle()` — writes both arrays, and flips the board to
`locked`. Locking is one-way: a second lock is refused so digits can never be redrawn under claims
that are already public.

### 2. Period close → winner resolution → notification

`SyncScoresJob` polls ESPN every 60s while a game window is open, grouped one request per
league-day. A period is only written once it has **closed** (the feed moved past it, or the game
ended), and scores are stored cumulatively — end-of-quarter running totals, not per-quarter points.

When a period newly closes, `WinnerService::resolveBoard()` runs. Orientation is fixed everywhere:
**rows carry the home digit, columns the away digit**. A 24–17 final resolves to the square whose row
digit is 4 and column digit is 7 — the same cell the claim page highlights.

Resolution is idempotent via a unique index on `(board_id, scoring_period)`. Re-running creates zero
rows. An unclaimed winning square still produces a win row with a null claimant, flagged for the
dealer; nothing is notified because there is nobody to notify.

A claimed win queues `NotifyWinnerJob`, which takes an atomic claim on `notified_at` before sending,
so a re-run cannot text anyone twice. SMS and email are independent — a suppressed or failed text
never stops the email.

### 3. Manual score override

An advisor edits scores on the board admin page. `Game::applyManualScores()` flips `scores_source`
to `manual`, permanently. From then on the game is invisible to the feed: `Game::pendingFeedSync()`
will not select it, and `Game::applyFeedScores()` carries `AND scores_source = 'feed'` in its `WHERE`
clause, so even a job queued before the override cannot clobber it. Winner resolution runs
immediately after the override.

---

## Local development without live games

This is the section that saves the most time in July.

```bash
# A complete tenant: dealership, user, campaign, board, four prizes, claims.
php scripts/seed-demo.php --fresh --full     # --full claims all 100 squares
php scripts/board.php list                   # find the board id

php scripts/board.php show   --board=1       # digits, scores, prizes, winners
php scripts/board.php lock   --board=1       # draw the digits, close entries
php scripts/board.php score  --board=1 --period=q1 --home=7 --away=3
php scripts/board.php score  --board=1 --period=final --home=24 --away=17
php scripts/board.php resolve --board=1      # re-run resolution on its own
php database/queue-work.php --once           # deliver the queued notifications
```

`score` writes through the same manual-override path an advisor uses in admin, so this exercises
real code rather than a test-only back door. It resolves winners automatically after each score.
Scoring the `final` period marks the game final, which is what lets the final prize pay out.

Use `--full` when you want to see the notification path: digits are shuffled at lock time, so on a
partly-claimed board most periods land on an unclaimed square — correct behaviour, but a poor demo.
With all 100 squares claimed every period resolves to a real winner.

With `MAIL_MAILER=log`, winner emails land in `storage/logs/mail.log`. With Plivo blank, SMS is
skipped and recorded as `not_configured` — the email still sends and the win is marked notified.

---

## Console commands

| Command | Purpose | Cadence |
| --- | --- | --- |
| `php database/migrate.php` | Apply pending SQL migrations. Creates the database if absent | On deploy |
| `php database/migrate.php --pretend` | List what would run, apply nothing | Before deploy |
| `php database/queue-work.php` | Long-running queue worker | Always, under a supervisor |
| `php database/queue-work.php --once` | Drain currently available jobs and exit | Local, or cron fallback |
| `php database/dispatch-score-sync.php` | Queue a score-sync run if one is not already pending | Cron, every minute |
| `php scripts/sync-games.php --league=nfl --week=1` | Import a week's schedule from ESPN. Upserts on `external_id`; never touches scores or a manual override | Weekly, per league |
| `php scripts/seed-demo.php [--fresh] [--full]` | Build a demo tenant. Refuses to run with `APP_ENV=production` | Local only |
| `php scripts/board.php <list\|show\|lock\|score\|resolve>` | Drive a board by hand | Local, and for support |
| `php scripts/dev-server.php` | Router shim for `php -S` so static files serve | Local only |
| `composer test:all` | Rebuild the test database and run every test | Before every commit |

`sync-games.php` also takes `--season=YYYY` (defaults by month) and `--type=1|2|3`
(pre/regular/post).

---

## Testing

```bash
composer test:all        # rebuilds keel_test, then runs Unit + Feature
composer test:feature    # feature suite only
vendor/bin/phpunit --filter Squares
```

Feature tests dispatch through the real router against a real MySQL database (`keel_test`, from
`.env.testing`), so they cover routing, middleware, views and SQL together.

**Covered:** tenant isolation on every admin route; digits absent before lock; winner orientation and
idempotency; claim races and the per-person limit; score-sync batching, period-close semantics,
malformed payloads and feed backoff; the manual-override guarantee; notification idempotency,
tenant-wide SMS opt-out and credential redaction; Plivo webhook signature verification; the
marketing site, lead capture and spam guards; sitemap/robots/JSON-LD.

**Deliberately not covered:** live Plivo sends and live ESPN calls (both are behind injectable
transports and faked in tests — the real endpoints were verified by hand, see the notes in
`EspnScoreProvider`); browser rendering and JavaScript behaviour; email rendering in real clients;
load and concurrency beyond the single-writer square race.

---

## Deployment

See [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) — server requirements, cron and worker setup, the Plivo
callback URL, SSL, and rollback.

## Compliance

See [docs/COMPLIANCE.md](docs/COMPLIANCE.md) — the rules the code enforces, and why. It documents
implementation, not legal advice.

---

DealerDraw™ is a product of EchoDial LLC.
