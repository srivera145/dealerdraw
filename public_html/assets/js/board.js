/*
 * DealerDraw public claim page.
 *
 * The grid is server rendered, so the page works with this file blocked: the
 * squares are real checkboxes in a real form and a standard POST claims them.
 * Everything here is enhancement - live refresh, optimistic selection, and
 * turning a submit conflict into "these two squares just went, the rest are
 * still yours" instead of a full page bounce.
 */
(function () {
	'use strict';

	var VISIBLE_POLL_MS = 10000;
	var CONFLICT_CLASS = 'square--conflict';

	function cellKey(row, col) {
		return row + '-' + col;
	}

	function parseCells(value) {
		return String(value || '')
			.split(' ')
			.filter(function (cell) {
				return cell !== '';
			});
	}

	function Board(root) {
		this.root = root;
		this.form = document.getElementById(root.getAttribute('data-form-id') || '');
		this.slug = root.getAttribute('data-slug') || '';
		this.boardId = root.getAttribute('data-board-id') || '';
		this.claimLimit = parseInt(root.getAttribute('data-claim-limit') || '5', 10);
		this.claimsOpen = root.getAttribute('data-claims-open') === '1';
		this.locked = root.getAttribute('data-locked') === '1';
		this.cells = {};
		this.mine = {};
		this.timer = null;

		var self = this;

		parseCells(root.getAttribute('data-mine')).forEach(function (cell) {
			self.mine[cell] = true;
		});

		Array.prototype.forEach.call(root.querySelectorAll('[data-cell]'), function (label) {
			self.cells[label.getAttribute('data-cell')] = {
				label: label,
				input: label.querySelector('input[type="checkbox"]'),
				text: label.querySelector('[data-cell-label]'),
			};
		});

		this.counter = document.querySelector('[data-available]');
		this.selectedCounter = document.querySelector('[data-selected-count]');
		this.alert = document.querySelector('[data-live-alert]');
		this.alertText = document.querySelector('[data-live-alert-text]');
		this.alertDetail = document.querySelector('[data-live-alert-detail]');
		this.submitButton = this.form ? this.form.querySelector('[data-submit]') : null;
	}

	Board.prototype.eachCell = function (callback) {
		var keys = Object.keys(this.cells);

		for (var i = 0; i < keys.length; i++) {
			callback(this.cells[keys[i]], keys[i]);
		}
	};

	Board.prototype.selected = function () {
		var chosen = [];

		this.eachCell(function (cell, key) {
			if (cell.input && cell.input.checked) {
				chosen.push(key);
			}
		});

		return chosen;
	};

	Board.prototype.syncSelection = function () {
		var chosen = this.selected();

		this.eachCell(function (cell) {
			if (!cell.input) {
				return;
			}

			cell.label.classList.toggle('square--selected', cell.input.checked);
		});

		if (this.selectedCounter) {
			this.selectedCounter.textContent = String(chosen.length);
		}

		return chosen;
	};

	Board.prototype.message = function (text, detail, kind) {
		if (!this.alert || !this.alertText) {
			return;
		}

		this.alertText.textContent = text;

		if (this.alertDetail) {
			this.alertDetail.textContent = detail || '';
		}

		this.alert.className = 'claim-alert claim-alert--' + (kind || 'ok');
		this.alert.hidden = false;
	};

	/* Applies one poll or claim response to the grid. */
	Board.prototype.apply = function (payload) {
		var self = this;
		var winners = payload.winners || {};
		var winnerAt = {};

		Object.keys(winners).forEach(function (period) {
			winnerAt[cellKey(winners[period].row, winners[period].col)] = period;
		});

		(payload.squares || []).forEach(function (square) {
			var key = cellKey(square.row, square.col);
			var cell = self.cells[key];

			if (!cell) {
				return;
			}

			var period = winnerAt[key];
			var isMine = self.mine[key] === true;

			if (cell.text) {
				cell.text.textContent = period ? period.toUpperCase() : square.name || '';
			}

			cell.label.classList.toggle('square--winner', Boolean(period));
			cell.label.classList.toggle('square--mine', isMine && !period);
			cell.label.classList.toggle('square--taken', square.taken && !isMine && !period);

			if (!cell.input) {
				return;
			}

			// A square someone else took is locked out, but never one the
			// visitor has already ticked and not yet submitted.
			if (square.taken && !cell.input.checked) {
				cell.input.disabled = true;
			} else if (!square.taken && self.claimsOpen) {
				cell.input.disabled = false;
			}
		});

		// digits is null until locked_at is set; there is nothing to reveal early.
		if (payload.digits) {
			Array.prototype.forEach.call(this.root.querySelectorAll('[data-row-digit]'), function (header) {
				header.textContent = payload.digits.row[parseInt(header.getAttribute('data-row-digit'), 10)];
			});

			Array.prototype.forEach.call(this.root.querySelectorAll('[data-col-digit]'), function (header) {
				header.textContent = payload.digits.col[parseInt(header.getAttribute('data-col-digit'), 10)];
			});
		}

		if (this.counter && typeof payload.available === 'number') {
			this.counter.textContent = String(payload.available);
		}

		// The board locking swaps the claim form for the status panel, which is
		// a server-rendered decision - reload rather than rebuild it here.
		var nowLocked = Boolean(payload.board && payload.board.locked);
		var stillOpen = Boolean(payload.board && payload.board.claims_open);

		if (nowLocked !== this.locked || (this.claimsOpen && !stillOpen)) {
			this.stopPolling();
			window.location.reload();
		}
	};

	Board.prototype.refresh = function () {
		var self = this;
		var url = '/p/' + encodeURIComponent(this.slug) + '/board?board=' + encodeURIComponent(this.boardId);

		return fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
			.then(function (response) {
				return response.ok ? response.json() : null;
			})
			.then(function (payload) {
				if (payload) {
					self.apply(payload);
					self.syncSelection();
				}
			})
			.catch(function () {
				// Offline or a blip: the last rendered state stays on screen.
			});
	};

	Board.prototype.stopPolling = function () {
		if (this.timer !== null) {
			window.clearTimeout(this.timer);
			this.timer = null;
		}
	};

	/* Poll only while the tab is visible; a hidden tab schedules nothing. */
	Board.prototype.startPolling = function () {
		var self = this;

		this.stopPolling();

		if (document.hidden) {
			return;
		}

		this.timer = window.setTimeout(function () {
			self.refresh().then(function () {
				self.startPolling();
			});
		}, VISIBLE_POLL_MS);
	};

	Board.prototype.clearConflicts = function (cells) {
		var self = this;

		cells.forEach(function (key) {
			var cell = self.cells[key];

			if (!cell) {
				return;
			}

			if (cell.input) {
				cell.input.checked = false;
				cell.input.disabled = true;
			}

			cell.label.classList.remove('square--selected');
			cell.label.classList.add('square--taken', CONFLICT_CLASS);

			window.setTimeout(function () {
				cell.label.classList.remove(CONFLICT_CLASS);
			}, 2600);
		});

		this.syncSelection();
	};

	Board.prototype.markMine = function (cells) {
		var self = this;

		cells.forEach(function (key) {
			var cell = self.cells[key];
			self.mine[key] = true;

			if (!cell) {
				return;
			}

			if (cell.input) {
				cell.input.checked = false;
				cell.input.disabled = true;
			}

			cell.label.classList.remove('square--selected');
			cell.label.classList.add('square--mine');
		});

		this.root.setAttribute('data-mine', Object.keys(this.mine).join(' '));
		this.syncSelection();
	};

	Board.prototype.bindSelection = function () {
		var self = this;

		this.root.addEventListener('change', function (event) {
			var input = event.target;

			if (!input || input.type !== 'checkbox') {
				return;
			}

			var chosen = self.selected();

			if (input.checked && chosen.length > self.claimLimit) {
				input.checked = false;
				self.message(
					'You can claim up to ' + self.claimLimit + ' squares on this board.',
					'',
					'error'
				);
			}

			self.syncSelection();
		});
	};

	Board.prototype.bindSubmit = function () {
		if (!this.form) {
			return;
		}

		var self = this;

		this.form.addEventListener('submit', function (event) {
			var chosen = self.selected();

			if (chosen.length === 0) {
				return; // Let the server answer, same as it does without JS.
			}

			event.preventDefault();

			if (self.submitButton) {
				self.submitButton.disabled = true;
			}

			fetch(self.form.getAttribute('action'), {
				method: 'POST',
				credentials: 'same-origin',
				headers: { Accept: 'application/json' },
				body: new FormData(self.form),
			})
				.then(function (response) {
					return response.json().then(function (payload) {
						return { status: response.status, payload: payload || {} };
					});
				})
				.then(function (result) {
					if (self.submitButton) {
						self.submitButton.disabled = false;
					}

					if (result.status === 201) {
						// reset() first: it restores the server-rendered checked
						// attributes, so it has to run before the just-claimed
						// squares are locked down.
						self.form.reset();
						self.markMine(result.payload.cells || []);
						self.message(
							'You are in. ' + (result.payload.claimed || 0) + ' square(s) claimed.',
							'Watch this page - we will email or text you if you win.',
							'ok'
						);
						return self.refresh();
					}

					// 409: someone claimed one of these between the last poll and
					// this submit. Nothing was saved, so drop only those squares
					// and leave the rest ticked for a one-tap retry.
					if (result.status === 409 && (result.payload.failed || []).length > 0) {
						self.clearConflicts(result.payload.failed);
						self.message(
							result.payload.error || 'Some squares were just taken.',
							'Still selected: ' + self.selected().length + '. Submit again to claim them.',
							'error'
						);
						return self.refresh();
					}

					self.message(result.payload.error || 'That claim could not be completed.', '', 'error');
					return self.refresh();
				})
				.catch(function () {
					if (self.submitButton) {
						self.submitButton.disabled = false;
					}

					// The request never landed: fall back to a normal form POST.
					self.form.submit();
				});
		});
	};

	Board.prototype.mount = function () {
		var self = this;

		this.bindSelection();
		this.bindSubmit();
		this.syncSelection();

		document.addEventListener('visibilitychange', function () {
			if (document.hidden) {
				self.stopPolling();
				return;
			}

			// Catch up immediately on return, then resume the cadence.
			self.refresh().then(function () {
				self.startPolling();
			});
		});

		this.startPolling();
	};

	function init() {
		Array.prototype.forEach.call(document.querySelectorAll('[data-board]'), function (root) {
			new Board(root).mount();
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init, { once: true });
	} else {
		init();
	}
})();
