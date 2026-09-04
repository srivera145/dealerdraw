<?php
$authMethod = $authMethod ?? 'both';
$csrfToken = \Keel\Core\Csrf::token();

$errorCode = (string) ($_GET['error'] ?? '');
$errorMessages = [
    'invalid_invite' => 'That invite link is invalid, expired, or has already been used.',
    'invalid_link' => 'That sign-in link is invalid or has expired. Request a new one below.',
];
$errorMessage = $errorMessages[$errorCode] ?? '';

$publicRoot = dirname(__DIR__, 2) . '/public_html';

$assetVersion = static function (string $relativePath) use ($publicRoot): string {
    $modified = is_file($publicRoot . $relativePath) ? (int) filemtime($publicRoot . $relativePath) : 0;

    return $relativePath . ($modified > 0 ? '?v=' . $modified : '');
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in - DealerDraw</title>
<meta name="description" content="Sign in to DealerDraw to run your dealership's promotional game boards.">
<meta name="robots" content="noindex,follow">
<meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
<meta name="theme-color" content="#16543f">
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">

<!-- Same pre-paint theme resolution as the rest of the site. -->
<script>
(function () {
	var stored = null;
	try { stored = localStorage.getItem('keel-theme'); } catch (e) { stored = null; }

	var theme = stored === 'light' || stored === 'dark' ? stored : null;

	if (!theme) {
		theme = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches
			? 'dark'
			: 'light';
	}

	document.documentElement.setAttribute('data-theme', theme);
})();
</script>

<link rel="stylesheet" href="<?= htmlspecialchars($assetVersion('/assets/css/site.css')) ?>">
<script src="<?= htmlspecialchars($assetVersion('/assets/js/theme.js')) ?>" defer></script>
</head>
<body class="auth-page">
<main class="auth-shell">
    <div class="auth-card">
        <div class="auth-card__head">
            <a class="wordmark" href="/">Dealer<span class="wordmark__mark">Draw</span></a>
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
        </div>

        <h1 class="auth-title">Sign in</h1>
        <p class="auth-sub">
            No password. We send you a code or a link.
            New to DealerDraw? Use your work email and we will set your dealership up on the next screen.
        </p>

        <?php if ($errorMessage !== ''): ?>
        <p class="alert alert--error" role="alert"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <?php if ($authMethod === 'both'): ?>
        <div class="auth-tabs" role="tablist" aria-label="Sign-in method">
            <button type="button" class="auth-tab auth-tab--active" id="tab-otp"
                    role="tab" aria-selected="true" aria-controls="panel-otp" data-tab-target="panel-otp">
                Emailed code
            </button>
            <button type="button" class="auth-tab" id="tab-magic"
                    role="tab" aria-selected="false" aria-controls="panel-magic" data-tab-target="panel-magic">
                Magic link
            </button>
        </div>
        <?php endif; ?>

        <?php if ($authMethod === 'otp' || $authMethod === 'both'): ?>
        <div id="panel-otp" role="tabpanel" aria-labelledby="tab-otp" data-tab-panel>
            <div id="otp-step-email">
                <div class="field">
                    <label for="otp-email">Work email</label>
                    <input type="email" id="otp-email" autocomplete="email" inputmode="email"
                           placeholder="you@dealership.com">
                </div>
                <button type="button" id="otp-send" class="btn btn--primary btn--block">Send my code</button>
            </div>

            <div id="otp-step-code" hidden>
                <div class="field">
                    <label for="otp-code">Enter the 6-digit code</label>
                    <input type="text" id="otp-code" maxlength="6" inputmode="numeric" autocomplete="one-time-code"
                           class="auth-code" placeholder="000000">
                </div>
                <button type="button" id="otp-verify" class="btn btn--primary btn--block">Verify and sign in</button>
                <p class="auth-hint">The code lasts a few minutes. Check spam if it has not arrived.</p>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($authMethod === 'magic_link' || $authMethod === 'both'): ?>
        <div id="panel-magic" role="tabpanel" aria-labelledby="tab-magic" data-tab-panel
             <?= $authMethod === 'both' ? 'hidden' : '' ?>>
            <div class="field">
                <label for="magic-email">Work email</label>
                <input type="email" id="magic-email" autocomplete="email" inputmode="email"
                       placeholder="you@dealership.com">
            </div>
            <button type="button" id="magic-send" class="btn btn--primary btn--block">Email me a link</button>
            <p id="magic-sent" class="alert alert--ok auth-sent" hidden role="status">
                Check your email for the sign-in link.
            </p>
        </div>
        <?php endif; ?>

        <p id="auth-error" class="alert alert--error auth-error" hidden role="alert"></p>

        <p class="auth-foot">
            <a href="/">Back to dealerdraw.com</a>
            <span aria-hidden="true">&middot;</span>
            <a href="/#request-demo">Request a demo</a>
        </p>
    </div>
</main>

<script>
(function () {
	'use strict';

	var csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

	var errorBox = document.getElementById('auth-error');

	function showError(message) {
		errorBox.textContent = message;
		errorBox.hidden = false;
	}

	function clearError() {
		errorBox.hidden = true;
		errorBox.textContent = '';
	}

	function busy(button, isBusy, label) {
		button.disabled = isBusy;
		button.textContent = isBusy ? 'Sending...' : label;
	}

	function post(url, payload) {
		return fetch(url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': csrfToken },
			body: JSON.stringify(payload),
		}).then(function (response) {
			return response.json().catch(function () {
				return { success: false, message: 'Something went wrong. Please try again.' };
			});
		});
	}

	/* ---- tabs. Replaces the Keel component this page used to pull in. ---- */

	var tabs = Array.prototype.slice.call(document.querySelectorAll('[data-tab-target]'));

	tabs.forEach(function (tab) {
		tab.addEventListener('click', function () {
			clearError();

			tabs.forEach(function (other) {
				var isActive = other === tab;
				var panel = document.getElementById(other.getAttribute('data-tab-target'));

				other.classList.toggle('auth-tab--active', isActive);
				other.setAttribute('aria-selected', isActive ? 'true' : 'false');

				if (panel) {
					panel.hidden = !isActive;
				}
			});
		});
	});

	/* ---- one-time code ---- */

	var otpSend = document.getElementById('otp-send');

	if (otpSend) {
		otpSend.addEventListener('click', function () {
			clearError();
			busy(otpSend, true, 'Send my code');

			post('/auth/otp/request', { email: document.getElementById('otp-email').value })
				.then(function (data) {
					busy(otpSend, false, 'Send my code');

					if (!data.success) {
						showError(data.message || 'Something went wrong.');
						return;
					}

					document.getElementById('otp-step-email').hidden = true;
					document.getElementById('otp-step-code').hidden = false;
					document.getElementById('otp-code').focus();
				})
				.catch(function () {
					busy(otpSend, false, 'Send my code');
					showError('We could not reach the server. Check your connection and try again.');
				});
		});

		var otpVerify = document.getElementById('otp-verify');

		otpVerify.addEventListener('click', function () {
			clearError();
			busy(otpVerify, true, 'Verify and sign in');

			post('/auth/otp/verify', {
				email: document.getElementById('otp-email').value,
				code: document.getElementById('otp-code').value,
			}).then(function (data) {
				if (data.success) {
					window.location.href = data.redirect || '/dashboard';
					return;
				}

				busy(otpVerify, false, 'Verify and sign in');
				showError(data.message || 'That code was not right.');
			}).catch(function () {
				busy(otpVerify, false, 'Verify and sign in');
				showError('We could not reach the server. Check your connection and try again.');
			});
		});

		// Enter should submit whichever step is on screen.
		document.getElementById('otp-email').addEventListener('keydown', function (event) {
			if (event.key === 'Enter') { event.preventDefault(); otpSend.click(); }
		});
		document.getElementById('otp-code').addEventListener('keydown', function (event) {
			if (event.key === 'Enter') { event.preventDefault(); otpVerify.click(); }
		});
	}

	/* ---- magic link ---- */

	var magicSend = document.getElementById('magic-send');

	if (magicSend) {
		magicSend.addEventListener('click', function () {
			clearError();
			busy(magicSend, true, 'Email me a link');

			post('/auth/magic/request', { email: document.getElementById('magic-email').value })
				.then(function (data) {
					busy(magicSend, false, 'Email me a link');

					if (!data.success) {
						showError(data.message || 'Something went wrong.');
						return;
					}

					document.getElementById('magic-sent').hidden = false;
				})
				.catch(function () {
					busy(magicSend, false, 'Email me a link');
					showError('We could not reach the server. Check your connection and try again.');
				});
		});

		document.getElementById('magic-email').addEventListener('keydown', function (event) {
			if (event.key === 'Enter') { event.preventDefault(); magicSend.click(); }
		});
	}
})();
</script>
</body>
</html>
