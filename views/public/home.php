<?php
$errors = $errors ?? [];
$old = $old ?? [];
$submitted = (bool) ($submitted ?? false);
$formStartedAt = (int) ($formStartedAt ?? time());

$value = static function (string $field) use ($old): string {
    return htmlspecialchars((string) ($old[$field] ?? ''), ENT_QUOTES, 'UTF-8');
};

$pageTitle = 'DealerDraw - Turn your service lounge into a customer list';
$pageDescription = 'Free-to-enter game promotions for car dealerships. Customers claim a square, '
    . 'you collect opted-in phone numbers and emails, winners get a service offer by text. '
    . 'No purchase necessary, no entry fee, no cash prizes. $199 per month per rooftop.';
$canonicalPath = '/';
$schemaTypes = ['organization', 'software'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require __DIR__ . '/_head.php'; ?>
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>

<?php require __DIR__ . '/_nav.php'; ?>

<main id="main">

    <section class="hero">
        <div class="shell hero__inner">
            <div>
                <h1>Turn your service lounge into a customer list.</h1>
                <p class="hero__sub">
                    DealerDraw runs free-to-enter promotions that collect opted-in phone numbers and
                    emails - from the customers already waiting in your dealership, and the ones you
                    have not seen in two years.
                </p>
                <div class="hero__actions">
                    <a class="btn btn--primary" href="#request-demo">Request a demo</a>
                    <a class="btn btn--ghost" href="/demo">See a live board</a>
                </div>
                <p class="hero__note">No purchase necessary. No entry fee. Nothing for your customers to buy.</p>
            </div>

            <figure class="hero__shot">
                <img src="/assets/images/board-preview.png"
                     alt="A DealerDraw game board on a phone, with customer first names and last initials filling about a third of the hundred squares."
                     width="900" height="1200" loading="eager" decoding="async">
                <figcaption>An actual board, as your customer sees it on their phone.</figcaption>
            </figure>
        </div>
    </section>

    <section class="section section--tint" aria-labelledby="problem-heading">
        <div class="shell">
            <p class="eyebrow">The problem</p>
            <h2 id="problem-heading">You already met these customers. You just cannot reach them.</h2>
            <p class="section__lead">
                Three gaps that cost fixed ops more than any advertising line item.
            </p>

            <ul class="cards">
                <li class="card">
                    <h3>They lapse quietly</h3>
                    <p>
                        A customer buys, services twice, then drifts to the quick-lube down the road.
                        Nobody notices, because nothing in the CRM fires when someone simply stops coming.
                    </p>
                </li>
                <li class="card">
                    <h3>The list is dead weight</h3>
                    <p>
                        Half the numbers in your DMS were typed once at a delivery desk years ago.
                        No opt-in, no consent on record, and no confidence to text any of them.
                    </p>
                </li>
                <li class="card">
                    <h3>Promotions get ignored</h3>
                    <p>
                        Ten percent off an alignment does not get shared, screenshotted, or talked about
                        in the waiting room. It gets thrown away with the receipt.
                    </p>
                </li>
            </ul>
        </div>
    </section>

    <section class="section" id="how" aria-labelledby="how-heading">
        <div class="shell">
            <p class="eyebrow">How it works</p>
            <h2 id="how-heading">Four steps. About ten minutes of setup.</h2>

            <ol class="steps">
                <li class="step">
                    <span class="step__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="3" width="18" height="18" rx="2"></rect>
                            <path d="M3 9h18M3 15h18M9 3v18M15 3v18"></path>
                        </svg>
                    </span>
                    <div>
                        <h3>Build your board</h3>
                        <p>Pick the game, add the service offers you want to give away, set how many entries one person can take.</p>
                    </div>
                </li>

                <li class="step">
                    <span class="step__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.5 1.5"></path>
                            <path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.5-1.5"></path>
                        </svg>
                    </span>
                    <div>
                        <h3>Share one link</h3>
                        <p>A QR code on the service counter, a text to your database, a post in the local Facebook group. One link, everywhere.</p>
                    </div>
                </li>

                <li class="step">
                    <span class="step__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                            <circle cx="12" cy="7" r="4"></circle>
                        </svg>
                    </span>
                    <div>
                        <h3>Customers claim squares free</h3>
                        <p>Name, email, mobile, and a consent box. No payment fields anywhere - there is nothing to charge them for.</p>
                    </div>
                </li>

                <li class="step">
                    <span class="step__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                            <path d="M8 10h8M8 14h5"></path>
                        </svg>
                    </span>
                    <div>
                        <h3>Winners get a code by text</h3>
                        <p>Scores sync automatically. Each winner gets a redemption code, and your advisor marks it used at the counter.</p>
                    </div>
                </li>
            </ol>
        </div>
    </section>

    <section class="section section--tint" id="cost" aria-labelledby="cost-heading">
        <div class="shell">
            <p class="eyebrow">What it costs</p>
            <h2 id="cost-heading">One board. Four service offers. A hundred opted-in contacts.</h2>
            <p class="section__lead">
                Worked with real numbers, so you can put your own in and see whether it holds up.
            </p>

            <div class="ledger">
                <div class="ledger__row">
                    <span class="ledger__label">1st quarter - oil change and filter</span>
                    <span class="ledger__value">about $35</span>
                </div>
                <div class="ledger__row">
                    <span class="ledger__label">Halftime - tire rotation and brake inspection</span>
                    <span class="ledger__value">about $25</span>
                </div>
                <div class="ledger__row">
                    <span class="ledger__label">3rd quarter - full detail</span>
                    <span class="ledger__value">about $60</span>
                </div>
                <div class="ledger__row">
                    <span class="ledger__label">Final - the big one, your call</span>
                    <span class="ledger__value">about $80</span>
                </div>
                <div class="ledger__row ledger__row--total">
                    <span class="ledger__label">Your cost for a full board</span>
                    <span class="ledger__value">under $200</span>
                </div>
            </div>

            <p class="footnote">
                Those are internal costs - parts, labour and bay time - not the retail values the
                customer sees, which run three to four times higher. Replace them with your own.
            </p>

            <p style="margin-top:20px;">
                A full board is <strong>100 people who typed in their own mobile number and ticked
                a consent box</strong>. Divide your cost by a hundred and compare it to what you
                currently pay per lead. That comparison is the whole pitch, and it is yours to run,
                not ours to claim.
            </p>
            <p>
                The prizes only cost you anything when somebody wins one - and a winner is a customer
                who has to come back into your service drive to redeem it.
            </p>
        </div>
    </section>

    <section class="compliance" id="legal" aria-labelledby="legal-heading">
        <div class="shell">
            <p class="eyebrow">Straight answer</p>
            <h2 id="legal-heading">This is not gambling, and it is not a pool.</h2>
            <p>
                It is the question every GM asks in the first two minutes, so here it is up front,
                without the hedging.
            </p>

            <ul class="compliance__list">
                <li class="compliance__item">
                    <svg class="compliance__check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"></path></svg>
                    <span><strong>Nobody pays to enter.</strong> There is no entry fee, no minimum purchase, no "buy a service and get a square". The page does not collect payment details at all.</span>
                </li>
                <li class="compliance__item">
                    <svg class="compliance__check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"></path></svg>
                    <span><strong>There is no pot.</strong> A pool works because everyone puts money in and the winner takes it. Here nothing goes in, so there is nothing to divide.</span>
                </li>
                <li class="compliance__item">
                    <svg class="compliance__check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"></path></svg>
                    <span><strong>No cash prizes.</strong> Winners get one of your service offers. It has a retail value printed on it and no cash value at all.</span>
                </li>
                <li class="compliance__item">
                    <svg class="compliance__check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"></path></svg>
                    <span><strong>"No purchase necessary" is printed on the page.</strong> Above the board, on every board, and it is not a setting anybody can switch off.</span>
                </li>
            </ul>

            <p style="margin-top:22px;">
                We host your official rules on the same page and keep a record of who consented to what
                and when, so you can produce it if you are ever asked. Rules vary by state, and your own
                counsel should sign off before your first board goes live - we will give them everything
                they need to look at.
            </p>
        </div>
    </section>

    <section class="section" id="beyond" aria-labelledby="beyond-heading">
        <div class="shell">
            <p class="eyebrow">Beyond football</p>
            <h2 id="beyond-heading">Football starts it. It does not end there.</h2>
            <p class="section__lead">
                The season is four months. Your service drive is open all twelve, so the same
                mechanic runs on a different game.
            </p>

            <ul class="chips">
                <li class="chip chip--live">Football boards - live now</li>
                <li class="chip">Spin-to-win</li>
                <li class="chip">Scratch-offs</li>
                <li class="chip">Bracket pools</li>
                <li class="chip">Punch cards</li>
            </ul>

            <p>
                Same contacts, same consent records, same admin. Every game type is included -
                there is no upsell waiting for you in March.
            </p>
        </div>
    </section>

    <section class="section section--tint" id="pricing" aria-labelledby="pricing-heading">
        <div class="shell">
            <p class="eyebrow">Pricing</p>
            <h2 id="pricing-heading">One price. No per-entry fees.</h2>

            <div class="pricing-grid">
                <div class="price">
                    <p class="price__amount">$199<span class="price__period"> / month</span></p>
                    <ul class="price__list">
                        <li>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"></path></svg>
                            <span>Unlimited boards, per rooftop</span>
                        </li>
                        <li>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"></path></svg>
                            <span>Every game type, including the ones still coming</span>
                        </li>
                        <li>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"></path></svg>
                            <span>Automatic scoring, winner texts and emails</span>
                        </li>
                        <li>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"></path></svg>
                            <span>Contact exports you own, whenever you want them</span>
                        </li>
                        <li>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"></path></svg>
                            <span>No contract, no setup fee, no charge per entry</span>
                        </li>
                    </ul>

                    <p class="founding">
                        <strong>Founding dealer:</strong> the first season is locked at this price for as
                        long as you stay, and we will build your first board with you on a call.
                    </p>
                </div>

                <div>
                    <h3>What you are not paying for</h3>
                    <p>
                        No per-message SMS charge, no per-contact fee, no percentage of anything.
                        Prizes come out of your own shop at your own cost, and you decide what they are.
                    </p>
                    <p>
                        Prizes are the only other expense, and you set them. A board you never run
                        costs you nothing but the subscription.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <section class="section" id="request-demo" aria-labelledby="demo-heading">
        <div class="shell">
            <p class="eyebrow">Request a demo</p>
            <h2 id="demo-heading">Fifteen minutes, and you will see your own board.</h2>
            <p class="section__lead">
                Tell us the store and we will set up a board for your next home game before the call,
                so you are looking at your teams and your offers rather than a slide deck.
            </p>

            <?php if ($submitted): ?>
            <p class="alert alert--ok" role="status">
                Thanks - that is with us. We will call or text within one business day.
            </p>
            <?php endif; ?>

            <?php if (isset($errors['form'])): ?>
            <p class="alert alert--error" role="alert"><?= htmlspecialchars($errors['form'], ENT_QUOTES, 'UTF-8') ?></p>
            <?php elseif ($errors !== []): ?>
            <p class="alert alert--error" role="alert">Please check the highlighted fields below.</p>
            <?php endif; ?>

            <div class="form-card">
                <form method="POST" action="/request-demo" data-lead-form novalidate>
                    <?= \Keel\Core\Csrf::field() ?>
                    <input type="hidden" name="form_started_at" value="<?= $formStartedAt ?>">

                    <div class="hp" aria-hidden="true">
                        <label for="company_website">Company website</label>
                        <input type="text" id="company_website" name="company_website" tabindex="-1" autocomplete="off">
                    </div>

                    <div class="field">
                        <label for="dealer_name">Dealership name</label>
                        <input type="text" id="dealer_name" name="dealer_name" maxlength="255" required
                               autocomplete="organization"
                               value="<?= $value('dealer_name') ?>"
                               <?= isset($errors['dealer_name']) ? 'aria-invalid="true" aria-describedby="dealer_name_error"' : '' ?>>
                        <?php if (isset($errors['dealer_name'])): ?>
                        <span class="field__error" id="dealer_name_error"><?= htmlspecialchars($errors['dealer_name'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="field">
                        <label for="contact_name">Your name</label>
                        <input type="text" id="contact_name" name="contact_name" maxlength="255" required
                               autocomplete="name"
                               value="<?= $value('contact_name') ?>"
                               <?= isset($errors['contact_name']) ? 'aria-invalid="true" aria-describedby="contact_name_error"' : '' ?>>
                        <?php if (isset($errors['contact_name'])): ?>
                        <span class="field__error" id="contact_name_error"><?= htmlspecialchars($errors['contact_name'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="field--split">
                        <div class="field">
                            <label for="email">Work email</label>
                            <input type="email" id="email" name="email" maxlength="255" required
                                   autocomplete="email" inputmode="email"
                                   value="<?= $value('email') ?>"
                                   <?= isset($errors['email']) ? 'aria-invalid="true" aria-describedby="email_error"' : '' ?>>
                            <?php if (isset($errors['email'])): ?>
                            <span class="field__error" id="email_error"><?= htmlspecialchars($errors['email'], ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="field">
                            <label for="phone">Mobile</label>
                            <input type="tel" id="phone" name="phone" maxlength="32" required
                                   autocomplete="tel" inputmode="tel"
                                   value="<?= $value('phone') ?>"
                                   <?= isset($errors['phone']) ? 'aria-invalid="true" aria-describedby="phone_error"' : '' ?>>
                            <?php if (isset($errors['phone'])): ?>
                            <span class="field__error" id="phone_error"><?= htmlspecialchars($errors['phone'], ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="field">
                        <label for="rooftop_count">Rooftops <span class="optional">(optional)</span></label>
                        <input type="number" id="rooftop_count" name="rooftop_count" min="1" max="999" inputmode="numeric"
                               value="<?= $value('rooftop_count') ?>"
                               <?= isset($errors['rooftop_count']) ? 'aria-invalid="true" aria-describedby="rooftop_count_error"' : '' ?>>
                        <?php if (isset($errors['rooftop_count'])): ?>
                        <span class="field__error" id="rooftop_count_error"><?= htmlspecialchars($errors['rooftop_count'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="field">
                        <label for="message">Anything we should know <span class="optional">(optional)</span></label>
                        <textarea id="message" name="message" rows="3" maxlength="2000"
                                  <?= isset($errors['message']) ? 'aria-invalid="true" aria-describedby="message_error"' : '' ?>><?= $value('message') ?></textarea>
                        <?php if (isset($errors['message'])): ?>
                        <span class="field__error" id="message_error"><?= htmlspecialchars($errors['message'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                    </div>

                    <button type="submit" class="btn btn--primary btn--block" data-lead-submit>Request a demo</button>

                    <p class="form-legal">
                        We use this to contact you about DealerDraw and nothing else. No list, no resale.
                    </p>
                </form>
            </div>
        </div>
    </section>
</main>

<?php require __DIR__ . '/_footer.php'; ?>
</body>
</html>
