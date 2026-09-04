<header class="nav">
    <div class="shell nav__inner">
        <a class="wordmark" href="/">Dealer<span class="wordmark__mark">Draw</span></a>
        <nav class="nav__links" aria-label="Primary">
            <a href="/#how">How it works</a>
            <a href="/#cost">What it costs</a>
            <a href="/faq">FAQ</a>
            <a href="/guides">Guides</a>
            <a href="/#pricing">Pricing</a>
        </nav>
        <div class="nav__actions">
            <!--
                Existing dealers land here. /login is passwordless and doubles as
                sign-up: an unknown email creates the account and drops them at
                onboarding. Kept as a text link so it stays subordinate to the
                demo CTA, and outside .nav__links so it is visible on a phone.
            -->
            <a class="nav__signin" href="/login">Sign in</a>

            <!--
                Hidden until site.js unhides it: a toggle that does nothing is
                worse than no toggle. Both icons ship; CSS shows the one that
                matches the current theme, so there is no glyph to swap in JS.
            -->
            <button type="button" class="theme-toggle" data-theme-toggle hidden>
                <svg class="theme-toggle__moon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"></path>
                </svg>
                <svg class="theme-toggle__sun" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="4"></circle>
                    <path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"></path>
                </svg>
                <span class="visually-hidden" data-theme-toggle-label>Switch colour theme</span>
            </button>

            <a class="btn btn--primary btn--sm" href="/#request-demo">Request a demo</a>
        </div>
    </div>
</header>
