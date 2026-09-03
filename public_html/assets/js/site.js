/*
 * dealerdraw.com. Deliberately small: the marketing page works fully without
 * it, so this only smooths two things - the demo board's selection state, and
 * keeping the visitor on the page when the lead form is submitted.
 */
(function () {
	'use strict';

	var DEMO_LIMIT_MESSAGE = 'On a real board the dealer sets the limit. Five is the default.';

	/* ---- demo board -------------------------------------------------- */

	function mountDemoBoard(board) {
		var limit = parseInt(board.getAttribute('data-claim-limit') || '5', 10);
		var counter = document.querySelector('[data-demo-count]');
		var notice = document.querySelector('[data-demo-notice]');
		var submit = document.querySelector('[data-demo-submit]');

		function selected() {
			return board.querySelectorAll('input[type="checkbox"]:checked');
		}

		function sync() {
			var count = selected().length;

			Array.prototype.forEach.call(board.querySelectorAll('[data-cell]'), function (label) {
				var input = label.querySelector('input[type="checkbox"]');
				label.classList.toggle('cell--selected', Boolean(input && input.checked));
			});

			if (counter) {
				counter.textContent = String(count);
			}
		}

		board.addEventListener('change', function (event) {
			var input = event.target;

			if (!input || input.type !== 'checkbox') {
				return;
			}

			if (input.checked && selected().length > limit) {
				input.checked = false;

				if (notice) {
					notice.textContent = DEMO_LIMIT_MESSAGE;
					notice.hidden = false;
				}
			}

			sync();
		});

		if (submit) {
			submit.addEventListener('click', function () {
				var count = selected().length;

				if (notice) {
					notice.textContent = count === 0
						? 'Tap a few open squares first, then try again.'
						: 'This is a demo, so nothing was saved. On a real board those '
							+ count + ' square(s) would be yours and you would get a text if they won.';
					notice.hidden = false;
					notice.focus();
				}
			});
		}

		sync();
	}

	/* ---- lead form --------------------------------------------------- */

	function mountLeadForm(form) {
		form.addEventListener('submit', function () {
			var button = form.querySelector('[data-lead-submit]');

			if (button) {
				// Stops a double-tap creating two leads while the post is in flight.
				button.disabled = true;
				button.textContent = 'Sending...';
			}
		});
	}

	function init() {
		var board = document.querySelector('[data-demo-board]');

		if (board) {
			mountDemoBoard(board);
		}

		var form = document.querySelector('[data-lead-form]');

		if (form) {
			mountLeadForm(form);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init, { once: true });
	} else {
		init();
	}
})();
