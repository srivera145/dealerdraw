/*
 * Colour theme control, shared by the marketing site and the public claim page.
 *
 * The page head already resolved and stamped data-theme before first paint;
 * this only wires up the toggle and keeps the OS in sync while the visitor has
 * not chosen for themselves. Loaded by both pages so the logic lives once.
 *
 * The storage key is shared with the dealer admin, so a preference set in one
 * place carries to the other.
 */
(function () {
	'use strict';

	var THEME_KEY = 'keel-theme';

	function readStored() {
		try {
			var stored = localStorage.getItem(THEME_KEY);
			return stored === 'light' || stored === 'dark' ? stored : null;
		} catch (error) {
			return null;
		}
	}

	function current() {
		return document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
	}

	function apply(theme, remember) {
		document.documentElement.setAttribute('data-theme', theme);

		if (remember) {
			try {
				localStorage.setItem(THEME_KEY, theme);
			} catch (error) {
				// Private browsing or storage disabled. The choice still applies
				// to this page view, it just is not remembered.
			}
		}

		var label = theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme';

		Array.prototype.forEach.call(document.querySelectorAll('[data-theme-toggle]'), function (button) {
			button.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
			button.setAttribute('title', label);
		});

		Array.prototype.forEach.call(document.querySelectorAll('[data-theme-toggle-label]'), function (node) {
			node.textContent = label;
		});
	}

	function init() {
		var toggles = document.querySelectorAll('[data-theme-toggle]');

		Array.prototype.forEach.call(toggles, function (button) {
			// Revealed only once it works: a dead control is worse than none.
			button.hidden = false;
			button.addEventListener('click', function () {
				apply(current() === 'dark' ? 'light' : 'dark', true);
			});
		});

		apply(current(), false);

		// Track the OS setting until the visitor overrides it themselves.
		if (window.matchMedia) {
			var query = window.matchMedia('(prefers-color-scheme: dark)');

			if (query.addEventListener) {
				query.addEventListener('change', function (event) {
					if (readStored() === null) {
						apply(event.matches ? 'dark' : 'light', false);
					}
				});
			}
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init, { once: true });
	} else {
		init();
	}
})();
