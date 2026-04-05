/**
 * PressArk Chat Panel Frontend Logic
 */
(function () {
	'use strict';

	/* ── Inline SVG Icon Helper ─────────────────────────────────────── */
	var pwIcons = {
		zap:       '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 1.5L3 9h4.5l-.5 5.5L13 7H8.5l.5-5.5z"/></svg>',
		moon:      '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M13.5 8.5a5.5 5.5 0 1 1-6-6 4.5 4.5 0 0 0 6 6z"/></svg>',
		pen:       '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M11.2 2.3a1.6 1.6 0 0 1 2.5 2l-8 8L2.5 13l.7-3.2z"/></svg>',
		search:    '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="7" cy="7" r="4.5"/><path d="m13.5 13.5-3-3"/></svg>',
		shield:    '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M8 1.5L2.5 4v3.5c0 3.5 2.3 6 5.5 7 3.2-1 5.5-3.5 5.5-7V4z"/></svg>',
		barChart:  '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 13.5V7M10 13.5V2.5M2.5 13.5v-3M13.5 13.5V5"/></svg>',
		sparkles:  '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M8 1.5l1 3.5 3.5 1-3.5 1-1 3.5-1-3.5L3.5 6l3.5-1z"/><path d="M12 10l.5 1.5 1.5.5-1.5.5-.5 1.5-.5-1.5L10 12l1.5-.5z"/></svg>',
		refresh:   '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M1.5 2.5v4h4"/><path d="M2.5 10a5.5 5.5 0 1 0 1-5.5L1.5 6.5"/></svg>',
		broom:     '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 9l6.5-6.5M4 10l-1.5 4.5L7 13l1-1"/><path d="M7 13c1.5 0 4-1.5 6.5-4"/></svg>',
		clipboard: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="2.5" width="9" height="11" rx="1.5"/><path d="M6 2.5V1.5h4v1"/><path d="M6 7h4M6 9.5h2.5"/></svg>',
		store:     '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M2 5.5l1-3.5h10l1 3.5"/><path d="M2 5.5c0 1.1.9 2 2 2s2-.9 2-2c0 1.1.9 2 2 2s2-.9 2-2c0 1.1.9 2 2 2s2-.9 2-2"/><path d="M2.5 7.5v6h11v-6"/><path d="M6.5 13.5v-3.5h3v3.5"/></svg>',
		trendUp:   '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 3.5l-5 5-3-3-5 5"/><path d="M10 3.5h4.5V7"/></svg>',
		check:     '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3.5 8.5l3 3 6-6"/></svg>',
		x:         '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4l8 8M12 4l-8 8"/></svg>',
		warning:   '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M7.13 2.5L1.5 12.5h13L8.87 2.5a1 1 0 0 0-1.74 0z"/><path d="M8 6.5v2.5"/><circle cx="8" cy="11" r=".5" fill="currentColor" stroke="none"/></svg>',
		info:      '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="8" r="6.5"/><path d="M8 7v4"/><circle cx="8" cy="5" r=".5" fill="currentColor" stroke="none"/></svg>',
		fileDown:  '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9.5 1.5H4.5a1 1 0 0 0-1 1v11a1 1 0 0 0 1 1h7a1 1 0 0 0 1-1V4.5z"/><path d="M8 7v4M6 9.5L8 11.5 10 9.5"/></svg>',
		loader:    '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M8 1.5v2M8 12.5v2M3.4 3.4l1.4 1.4M11.2 11.2l1.4 1.4M1.5 8h2M12.5 8h2M3.4 12.6l1.4-1.4M11.2 4.8l1.4-1.4"/></svg>',
		dot:       '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="8" r="3"/></svg>',
		pencil:    '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.5 2.5l3 3L5 14H2v-3z"/></svg>',
		undo:      '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h6a3 3 0 1 1 0 6H8"/><path d="M6.5 3.5L4 6l2.5 2.5"/></svg>',
		lock:      '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="7" width="9" height="6.5" rx="1.5"/><path d="M5.5 7V5a2.5 2.5 0 0 1 5 0v2"/></svg>',
		mail:      '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="1.5" y="3.5" width="13" height="9" rx="1.5"/><path d="M1.5 5l6.5 4 6.5-4"/></svg>',
		send:      '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 1.5l-6 13-2.5-5.5L1.5 6.5z"/><path d="M14.5 1.5L6 9"/></svg>',
		house:     '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M2.5 6.5L8 2l5.5 4.5V13a1 1 0 0 1-1 1h-9a1 1 0 0 1-1-1z"/><path d="M6 14V9h4v5"/></svg>',
		checkCircle: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="8" r="6.5"/><path d="M5.5 8l2 2 3.5-3.5"/></svg>',
		xCircle:   '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="8" r="6.5"/><path d="M5.5 5.5l5 5M10.5 5.5l-5 5"/></svg>',
		dollar:    '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M8 1.5v13"/><path d="M11 4.5H6.5a2 2 0 0 0 0 4h3a2 2 0 0 1 0 4H5"/></svg>',
		package:   '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M2 4.5L8 1.5l6 3v7l-6 3-6-3z"/><path d="M2 4.5L8 8l6-3.5"/><path d="M8 8v6.5"/></svg>',
		alertCircle: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="8" r="6.5"/><path d="M8 5v3.5"/><circle cx="8" cy="11" r=".5" fill="currentColor" stroke="none"/></svg>',
		star:      '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M8 1.5l1.9 4 4.4.6-3.2 3 .8 4.4L8 11.3 4.1 13.5l.8-4.4-3.2-3 4.4-.6z"/></svg>',
		gift:      '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="1.5" y="6" width="13" height="3" rx="1"/><rect x="2.5" y="9" width="11" height="5" rx="1"/><path d="M8 6v8"/><path d="M8 6C6.5 6 4 4.5 4 3a2 2 0 0 1 4 0"/><path d="M8 6c1.5 0 4-1.5 4-3a2 2 0 0 0-4 0"/></svg>',
		statusDot: '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 10 10"><circle cx="5" cy="5" r="4" fill="currentColor"/></svg>'
	};

	/** Wrap an icon SVG string in a .pw-icon span for inline use. */
	function pwIcon(name) {
		return '<span class="pw-icon">' + (pwIcons[name] || '') + '</span>';
	}

	var PANEL_STATE_KEY = 'pressark_panel_open';

	var PressArk = {
		panel: null,
		messagesEl: null,
		inputEl: null,
		sendBtn: null,
		toggleBtn: null,
		quotaBarEl: null,
		historySidebar: null,
		conversation: [],
		loadedGroups: [],
		checkpoint: null,
		isOpen: false,
		isSending: false,
		deepModeActive: false,
		lastMessageTime: 0,
		lastUserMessage: '',
		currentChatId: null,
		autoSaveTimer: null,
		scrollObserver: null,
		pendingActions: {},
		pendingPreviews: {},
		pendingRunIds: {},
		pendingActionIndices: {},
		actionIdCounter: 0,
		pendingResults: [],
		pendingTaskCount: 0,
		unreadTaskCount: 0,
		activityUrl: '',
		pollTimer: null,
		pollRequestInFlight: false,
		_activeRequest: null,
		_activeRunId: null,
		activePreviewFooterEl: null,

		init: function () {
			this.panel = document.getElementById('pressark-panel');
			this.messagesEl = document.getElementById('pressark-messages');
			this.inputEl = document.getElementById('pressark-input');
			this.sendBtn = document.getElementById('pressark-send');
			this.toggleBtn = document.getElementById('pressark-toggle');
			this.quotaBarEl = document.getElementById('pressark-quota-bar');
			this.historySidebar = document.getElementById('pressark-history-panel');
			this.activityBtn = document.getElementById('pressark-activity-btn');
			this.activityCountEl = document.getElementById('pressark-activity-count');

			if (!this.panel || !this.toggleBtn) {
				return;
			}

			var data = window.pressarkData || {};
			this.unreadTaskCount = parseInt(data.initial_unread_count || 0, 10);
			if (isNaN(this.unreadTaskCount)) {
				this.unreadTaskCount = 0;
			}
			this.activityUrl = data.activity_url || '';
			this.pendingActionIndices = {};

			// Mark frontend pages so CSS can adjust layout when panel is open.
			if (data.isFrontend) {
				document.body.classList.add('pressark-frontend');
			}

			this.loadBrandImages();
			this.bindEvents();
			this.setupScrollObserver();
			this.restorePanelState();
			this.restoreConversation();
			this.updateActivityUi();
			this.syncTaskPolling(this.isOpen);
			this.renderQuotaBar();
			this.checkAutoMessage();
		},

		// ── Brand Image Loading ──────────────────────────────────────

		loadBrandImages: function () {
			var images = (window.pressarkData && window.pressarkData.images) || {};

			// Header logo (small, for the panel header on white bg)
			var headerLogo = document.getElementById('pressark-header-logo');
			if (headerLogo) {
				var logoSrc = this.findImage(images, ['WHITE-APP-LOGO', 'icon-dark', 'favicon', 'icon', 'logo-icon', 'app-icon']);
				if (logoSrc) {
					headerLogo.src = logoSrc;
				} else {
					headerLogo.style.display = 'none';
				}
			}

			// Toggle button logo (on blue bg)
			var toggleLogo = document.getElementById('pressark-toggle-logo');
			if (toggleLogo) {
				var toggleSrc = this.findImage(images, ['PNG-LOGO', 'DARK-APP-LOGO', 'icon-light', 'icon-white', 'favicon', 'icon']);
				if (toggleSrc) {
					toggleLogo.src = toggleSrc;
				} else {
					// Fallback: use SVG sparkle
					toggleLogo.parentElement.innerHTML = '<svg width="24" height="24" viewBox="0 0 24 24" fill="white"><path d="M12 2L14 9L22 9L16 14L18 22L12 17L6 22L8 14L2 9L10 9Z"/></svg>';
				}
			}
		},

		findImage: function (images, preferredNames) {
			var i, name, key;
			for (i = 0; i < preferredNames.length; i++) {
				name = preferredNames[i];
				// Exact match
				if (images[name]) return images[name];
				// Partial match
				for (key in images) {
					if (images.hasOwnProperty(key) && key.toLowerCase().indexOf(name.toLowerCase()) !== -1) {
						return images[key];
					}
				}
			}
			// Return first available image as last resort
			var keys = Object.keys(images);
			return keys.length > 0 ? images[keys[0]] : null;
		},

		// ── Event Binding ────────────────────────────────────────────

		bindEvents: function () {
			var self = this;

			this.toggleBtn.addEventListener('click', function () {
				self.toggle();
			});

			var closeBtn = document.getElementById('pressark-close-btn');
			if (closeBtn) {
				closeBtn.addEventListener('click', function () {
					self.close();
				});
			}

			// Deep Mode toggle button.
			var deepModeBtn = document.getElementById('pressark-deep-mode-btn');
			if (deepModeBtn) {
				deepModeBtn.addEventListener('click', function () {
					self.toggleDeepMode();
				});
			}

			// New Chat button.
			var newChatBtn = document.getElementById('pressark-new-chat-btn');
			if (newChatBtn) {
				newChatBtn.addEventListener('click', function () {
					self.newChat();
				});
			}

			if (this.activityBtn) {
				this.activityBtn.addEventListener('click', function () {
					if (self.activityUrl) {
						window.location.href = self.activityUrl;
					}
				});
			}

			// History toggle button.
			var historyBtn = document.getElementById('pressark-history-btn');
			if (historyBtn) {
				historyBtn.addEventListener('click', function () {
					self.toggleHistory();
				});
			}

			// History close button.
			var historyCloseBtn = document.getElementById('pressark-history-close');
			if (historyCloseBtn) {
				historyCloseBtn.addEventListener('click', function () {
					if (self.historySidebar) {
						self.historySidebar.style.display = 'none';
					}
				});
			}

			this.sendBtn.addEventListener('click', function () {
				var stopVisible = self.sendBtn && self.sendBtn.classList.contains('pressark-send-btn--stop');
				if (self.isSending || self._activeRequest || stopVisible) {
					self.abortActiveRequest();
				} else {
					self.sendMessage();
				}
			});

			this.inputEl.addEventListener('keydown', function (e) {
				if (e.key === 'Enter' && !e.shiftKey) {
					e.preventDefault();
					self.sendMessage();
				}
			});

			this.inputEl.addEventListener('input', function () {
				self.autoGrow();
			});

			// Keyboard shortcut: Ctrl+Shift+P / Cmd+Shift+P.
			document.addEventListener('keydown', function (e) {
				if ((e.ctrlKey || e.metaKey) && e.shiftKey && e.key === 'P') {
					e.preventDefault();
					self.toggle();
				}
			});

			// Escape key closes the panel.
			this.panel.addEventListener('keydown', function (e) {
				if (e.key === 'Escape') {
					e.preventDefault();
					e.stopPropagation();
					self.close();
					return;
				}

				// Focus trap: cycle Tab through interactive elements within the panel.
				if (e.key === 'Tab') {
					var focusable = self.panel.querySelectorAll(
						'button:not([disabled]):not([hidden]), textarea:not([disabled]), input:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])'
					);
					if (focusable.length === 0) return;

					var first = focusable[0];
					var last = focusable[focusable.length - 1];

					if (e.shiftKey) {
						if (document.activeElement === first) {
							e.preventDefault();
							last.focus();
						}
					} else {
						if (document.activeElement === last) {
							e.preventDefault();
							first.focus();
						}
					}
				}
			});
		},

		// ── Deep Mode Toggle ──────────────────────────────────────────

		toggleDeepMode: function () {
			var data = window.pressarkData;

			// Remove any existing deep mode banners to prevent stacking
			var existingIndicators = this.messagesEl.querySelectorAll('.pressark-deep-indicator');
			for (var i = 0; i < existingIndicators.length; i++) {
				existingIndicators[i].remove();
			}

			// Check if user is Pro.
			if (!data.isPro) {
				var upgradeUrl = data.upgradeUrl || '#';
				var upgradeMsg = document.createElement('div');
				upgradeMsg.className = 'pressark-message pressark-message-system pressark-deep-indicator';
				upgradeMsg.innerHTML =
					'<div class="pressark-message-content">' +
					'<div class="pressark-upgrade-prompt">' +
					'<strong>' + pwIcon('zap') + ' Deep Mode is a Pro feature</strong>' +
					'<p>Deep Mode uses premium AI models (Claude Sonnet 4.6, GPT-5.4) with extended context for complex tasks like full-site rewrites, detailed analysis, and bulk content generation.</p>' +
					'<a href="' + this.escapeHtml(upgradeUrl) + '" target="_blank" class="pressark-upgrade-btn">Upgrade to Pro</a>' +
					'</div></div>';
				this.messagesEl.appendChild(upgradeMsg);
				this.scrollToBottom();
				return;
			}

			this.deepModeActive = !this.deepModeActive;
			var btn = document.getElementById('pressark-deep-mode-btn');
			if (btn) {
				btn.classList.toggle('pressark-deep-active', this.deepModeActive);
			}

			var indicator = document.createElement('div');
			indicator.className = 'pressark-message pressark-message-system pressark-deep-indicator';
			indicator.innerHTML =
				'<div class="pressark-message-content">' +
				(this.deepModeActive
					? pwIcon('zap') + ' <strong>Deep Mode ON</strong> \u2014 Using premium AI with extended context. Best for complex tasks.'
					: pwIcon('moon') + ' <strong>Deep Mode OFF</strong> \u2014 Back to standard mode.') +
				'</div>';
			this.messagesEl.appendChild(indicator);
			this.scrollToBottom();
		},

		// ── MutationObserver for auto-scroll ──────────────────────────

		setupScrollObserver: function () {
			if (!this.messagesEl || typeof MutationObserver === 'undefined') return;

			var self = this;
			this.scrollObserver = new MutationObserver(function () {
				// Only auto-scroll if user is near the bottom (within 80px).
				var el = self.messagesEl;
				var isNearBottom = (el.scrollHeight - el.scrollTop - el.clientHeight) < 80;
				if (isNearBottom) {
					self.scrollToBottom();
				}
			});

			this.scrollObserver.observe(this.messagesEl, {
				childList: true,
				subtree: true,
				characterData: true
			});
		},

		toggle: function () {
			if (this.isOpen) {
				this.close();
			} else {
				this.open();
			}
		},

		open: function () {
			this.panel.classList.add('pressark-panel-open');
			this.isOpen = true;
			sessionStorage.setItem(PANEL_STATE_KEY, 'open');
			this.setToggleVisible(false);
			this.inputEl.focus();
			this.syncTaskPolling(true);
			this.updateActivityUi();

			// Deliver any pending background task results.
			if (this.pendingResults && this.pendingResults.length > 0) {
				var remainingResults = [];
				for (var pr = 0; pr < this.pendingResults.length; pr++) {
					var pendingResult = this.pendingResults[pr] || {};
					var pendingChatId = parseInt(pendingResult.chat_id || 0, 10);
					var activeChatId = parseInt(this.currentChatId || 0, 10);
					var canRenderPending = pendingChatId < 1 || activeChatId < 1 || activeChatId === pendingChatId;

					if (pendingChatId > 0 && activeChatId < 1) {
						this.currentChatId = pendingChatId;
					}

					if (canRenderPending) {
						this.renderTaskResult(pendingResult);
					} else {
						remainingResults.push(pendingResult);
					}
				}
				this.pendingResults = remainingResults;
			}

			// Show welcome/onboarding only if no history exists.
			if (this.messagesEl.querySelectorAll('.pressark-message, .pressark-welcome').length === 0) {
				this.showWelcome();
			}
		},

		close: function () {
			this.panel.classList.remove('pressark-panel-open');
			this.isOpen = false;
			sessionStorage.setItem(PANEL_STATE_KEY, 'closed');
			this.setToggleVisible(true);

			// Return focus to the toggle button.
			if (this.toggleBtn) {
				this.toggleBtn.focus();
			}

			// Close history panel if open.
			if (this.historySidebar) {
				this.historySidebar.style.display = 'none';
			}
			this.syncTaskPolling(false);
		},

		shouldPollTasks: function () {
			return this.isOpen || this.pendingTaskCount > 0;
		},

		getTaskPollInterval: function () {
			return this.pendingTaskCount > 0 ? 15000 : 60000;
		},

		syncTaskPolling: function (immediate) {
			if (!this.shouldPollTasks()) {
				this.stopTaskPolling();
				return;
			}

			this.startTaskPolling(immediate === true);
		},

		startTaskPolling: function (immediate) {
			if (this.pollTimer) {
				clearTimeout(this.pollTimer);
				this.pollTimer = null;
			}

			var self = this;
			var delay = immediate ? 0 : this.getTaskPollInterval();
			this.pollTimer = window.setTimeout(function () {
				self.pollForTaskUpdates();
			}, delay);
		},

		stopTaskPolling: function () {
			if (this.pollTimer) {
				clearTimeout(this.pollTimer);
				this.pollTimer = null;
			}
		},

		pollForTaskUpdates: function () {
			var self = this;
			var data = window.pressarkData || {};

			if (!this.shouldPollTasks()) {
				this.stopTaskPolling();
				return;
			}

			if (this.pollRequestInFlight) {
				this.startTaskPolling(false);
				return;
			}

			this.pollRequestInFlight = true;
			this.pollTimer = null;

			fetch(data.restUrl + 'poll', {
				method: 'POST',
				cache: 'no-store',
				headers: {
					'X-WP-Nonce': data.nonce
				}
			})
				.then(function (response) {
					if (!response.ok) {
						throw new Error('Poll failed (' + response.status + ')');
					}
					return response.json();
				})
				.then(function (payload) {
					self.handleTaskPollResponse(payload || {});
				})
				.catch(function () {
					// Keep polling conservative and silent; chat send path owns user-facing errors.
				})
				.finally(function () {
					self.pollRequestInFlight = false;
					self.syncTaskPolling(false);
				});
		},

		handleTaskPollResponse: function (pw) {
			var pendingCount = parseInt(pw.pending_task_count || 0, 10);
			this.pendingTaskCount = isNaN(pendingCount) ? 0 : Math.max(0, pendingCount);
			var unreadCount = parseInt(pw.unread_count || 0, 10);
			this.unreadTaskCount = isNaN(unreadCount) ? 0 : Math.max(0, unreadCount);
			if (pw.activity_url) {
				this.activityUrl = pw.activity_url;
			}
			this.updateActivityUi();

			if (pw.completed_tasks && pw.completed_tasks.length > 0) {
				pw.completed_tasks.forEach(function (item) {
					var result = item.result || {};
					var resultChatId = parseInt(result.chat_id || 0, 10);
					var activeChatId = parseInt(PressArk.currentChatId || 0, 10);
					var canRenderInActiveChat = PressArk.isOpen && (
						resultChatId < 1 ||
						activeChatId < 1 ||
						activeChatId === resultChatId
					);

					if (resultChatId > 0 && activeChatId < 1) {
						PressArk.currentChatId = resultChatId;
					}

					if (canRenderInActiveChat) {
						PressArk.renderTaskResult(result);
					} else {
						PressArk.pendingResults = PressArk.pendingResults || [];
						PressArk.pendingResults.push(result);
					}
				});
			}

			var indexStatus = pw.index_status || {};
			var isIndexRebuilding = typeof indexStatus.running === 'boolean'
				? indexStatus.running
				: !!pw.index_rebuilding;

			if (isIndexRebuilding && PressArk.wasIndexRebuilding === undefined) {
				PressArk.wasIndexRebuilding = true;
			}
			if (!isIndexRebuilding && PressArk.wasIndexRebuilding) {
				PressArk.wasIndexRebuilding = false;
				PressArk.addMessage('ai', 'Content index rebuilt. I can now search your full site content.');
			}
		},

		renderTaskResult: function (result) {
			if (!result || typeof result !== 'object') return;

			if (this.isSilentContinuationResult(result)) {
				this.renderSilentContinuationStep(result);
			}

			if (this.continueCompactedRun(result)) {
				return;
			}

			var responseType = result.type || 'final_response';
			var replyText = result.reply || result.message || '';

			if (replyText && !this.isSilentContinuationResult(result)) {
				this.addMessage('ai', replyText, true);
				this.conversation.push({ role: 'assistant', content: replyText });
			}

			if (responseType === 'preview') {
				this.openPreview({
					preview_session_id: result.preview_session_id,
					preview_url: result.preview_url,
					diff: result.diff,
				});
			}

			if (result.pending_actions && result.pending_actions.length > 0) {
				for (var p = 0; p < result.pending_actions.length; p++) {
					this.renderPreviewCard(result.pending_actions[p], result.run_id || '', p);
				}
			}

			if (result.actions_performed && result.actions_performed.length > 0) {
				for (var i = 0; i < result.actions_performed.length; i++) {
					this.addActionResult(result.actions_performed[i]);
				}
			}

			this.applyResultState(result);

			if (result.suggestions) {
				this.renderSuggestionChips(result.suggestions);
			}

			this.autoSaveChat();
		},

		setToggleVisible: function (visible) {
			if (this.toggleBtn) {
				this.toggleBtn.style.opacity = visible ? '1' : '0';
				this.toggleBtn.style.pointerEvents = visible ? 'auto' : 'none';
				this.toggleBtn.style.transform = visible ? 'scale(1)' : 'scale(0.5)';
			}
			document.body.classList.toggle('pressark-panel-active', !visible);

			// On the frontend, push page content aside so it doesn't hide behind the panel.
			if ((window.pressarkData || {}).isFrontend) {
				document.body.style.marginRight = visible ? '' : '420px';
				document.body.style.transition = 'margin-right 0.3s cubic-bezier(0.16, 1, 0.3, 1)';
			}
		},

		restorePanelState: function () {
			// Inside a preview iframe — always start closed to avoid clutter.
			if (window.top !== window.self) {
				return;
			}

			var saved = sessionStorage.getItem(PANEL_STATE_KEY);
			if (saved === 'open') {
				this.panel.classList.add('pressark-panel-open');
				this.isOpen = true;
				this.setToggleVisible(false);

				// Show welcome if messages area is empty (mirrors open() logic).
				if (this.messagesEl.querySelectorAll('.pressark-message, .pressark-welcome').length === 0) {
					this.showWelcome();
				}
			}
		},

		/**
		 * Check for an auto-message from onboarding and send it.
		 */
		checkAutoMessage: function () {
			var autoMsg = sessionStorage.getItem('pressark_auto_message');
			if (!autoMsg) return;
			sessionStorage.removeItem('pressark_auto_message');
			if (!this.isOpen) {
				this.open();
			}
			var self = this;
			setTimeout(function () {
				self.inputEl.value = autoMsg;
				self.sendMessage();
			}, 400);
		},

		// ── Chat History (DB-backed) ──────────────────────────────────

		toggleHistory: function () {
			if (!this.historySidebar) return;
			var isOpen = this.historySidebar.style.display !== 'none' && this.historySidebar.style.display !== '';
			if (isOpen) {
				this.historySidebar.style.display = 'none';
			} else {
				this.historySidebar.style.display = 'block';
				this.loadChatList();
			}
		},

		loadChatList: function () {
			var self = this;
			var data = window.pressarkData;
			var listEl = document.getElementById('pressark-history-list');
			if (!listEl) return;

			listEl.innerHTML = '<div class="pressark-history-loading">Loading...</div>';

			fetch(data.restUrl + 'chats', {
				headers: { 'X-WP-Nonce': data.nonce }
			})
				.then(function (r) { return r.json(); })
				.then(function (chats) {
					if (!chats || chats.length === 0) {
						listEl.innerHTML = '<div class="pressark-history-empty">No saved chats yet</div>';
						return;
					}

					var html = '';
					for (var i = 0; i < chats.length; i++) {
						var c = chats[i];
						var isActive = self.currentChatId === parseInt(c.id, 10);
						html += '<div class="pressark-history-item' + (isActive ? ' pressark-history-active' : '') + '" data-chat-id="' + c.id + '">';
						html += '<span class="pressark-history-title">' + self.escapeHtml(c.title) + '</span>';
						html += '<span class="pressark-history-time">' + self.formatRelativeTime(c.updated_at) + '</span>';
						html += '<button class="pressark-history-delete" data-chat-id="' + c.id + '" title="Delete">&times;</button>';
						html += '</div>';
					}
					listEl.innerHTML = html;

					// Bind click handlers.
					var items = listEl.querySelectorAll('.pressark-history-item');
					for (var j = 0; j < items.length; j++) {
						(function (item) {
							item.addEventListener('click', function (e) {
								if (e.target.classList.contains('pressark-history-delete')) return;
								self.loadChat(parseInt(item.dataset.chatId, 10));
							});
						})(items[j]);
					}

					var delBtns = listEl.querySelectorAll('.pressark-history-delete');
					for (var k = 0; k < delBtns.length; k++) {
						(function (btn) {
							btn.addEventListener('click', function (e) {
								e.stopPropagation();
								self.deleteChat(parseInt(btn.dataset.chatId, 10));
							});
						})(delBtns[k]);
					}
				})
				.catch(function () {
					listEl.innerHTML = '<div class="pressark-history-empty">Failed to load chats</div>';
				});
		},

		loadChat: function (chatId) {
			var self = this;
			var data = window.pressarkData;

			fetch(data.restUrl + 'chats/' + chatId, {
				headers: { 'X-WP-Nonce': data.nonce }
			})
				.then(function (r) { return r.json(); })
				.then(function (chat) {
					if (!chat || chat.error) return;

					self.currentChatId = parseInt(chat.id, 10);
					self.conversation = [];
					self.loadedGroups = [];
					self.checkpoint = null;

					// Clear messages.
					self.messagesEl.innerHTML = '';

					// Restore messages from DB.
					var msgs = chat.messages || [];
					for (var i = 0; i < msgs.length; i++) {
						var msg = msgs[i];
						if (msg.role === 'user') {
							// Hide internal continuation messages from history display.
							if (msg.content && msg.content.indexOf('[Continue]') === 0) {
								self.conversation.push({ role: 'user', content: msg.content });
							} else {
								self.addMessage('user', msg.content, true);
								self.conversation.push({ role: 'user', content: msg.content });
							}
						} else if (msg.role === 'assistant' || msg.role === 'ai') {
							// Check if this is a serialised confirm/discard card.
							var staticCard = (msg.content && msg.content.indexOf('[PRESSARK_CARD:') === 0)
								? self.renderStaticCard(msg.content)
								: null;
							if (staticCard) {
								self.messagesEl.appendChild(staticCard);
							} else {
								self.addMessage('ai', msg.content, true);
							}
							self.conversation.push({ role: 'assistant', content: msg.content });
						}
					}

					self.scrollToBottom();

					// Close history panel.
					if (self.historySidebar) {
						self.historySidebar.style.display = 'none';
					}
				});
		},

		deleteChat: function (chatId) {
			var self = this;
			var data = window.pressarkData;

			fetch(data.restUrl + 'chats/' + chatId, {
				method: 'DELETE',
				headers: { 'X-WP-Nonce': data.nonce }
			})
				.then(function () {
					if (self.currentChatId === chatId) {
						self.currentChatId = null;
						self.newChat();
					}
					self.loadChatList();
				});
		},

		autoSaveChat: function () {
			var self = this;
			if (this.autoSaveTimer) clearTimeout(this.autoSaveTimer);

			this.autoSaveTimer = setTimeout(function () {
				self.saveCurrentChat();
			}, 2000);
		},

		saveCurrentChat: function () {
			if (this.conversation.length === 0) return;
			if (this.isSending && !this.currentChatId) return;

			var data = window.pressarkData;
			var body = {
				messages: this.conversation,
			};

			var savingChatId = this.currentChatId;

			if (savingChatId) {
				body.chat_id = savingChatId;
			}

			var self = this;

			fetch(data.restUrl + 'chats', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': data.nonce
				},
				body: JSON.stringify(body)
			})
				.then(function (r) { return r.json(); })
				.then(function (result) {
					if (result.chat_id && !savingChatId && self.currentChatId === null) {
						self.currentChatId = result.chat_id;
					}
				})
				.catch(function () { /* silent fail */ });
		},

		newChat: function () {
			var prevConversation = this.conversation.slice();
			var prevChatId = this.currentChatId;

			this.currentChatId = null;
			this.conversation = [];
			this.loadedGroups = [];
			this.checkpoint = null;
			this.messagesEl.innerHTML = '';

			if (prevConversation.length > 0 && prevChatId) {
				var data = window.pressarkData;
				fetch(data.restUrl + 'chats', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': data.nonce
					},
					body: JSON.stringify({
						chat_id: prevChatId,
						messages: prevConversation
					})
				}).catch(function () { /* silent */ });
			}

			this.showWelcome();
		},

		formatRelativeTime: function (dateStr) {
			if (!dateStr) return '';
			var date = new Date(dateStr.replace(' ', 'T') + 'Z');
			var now = new Date();
			var diff = Math.floor((now - date) / 1000);

			if (diff < 60) return 'just now';
			if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
			if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
			if (diff < 604800) return Math.floor(diff / 86400) + 'd ago';
			return date.toLocaleDateString();
		},

		restoreConversation: function () {
			// Try to load the most recent chat from DB on next open.
		},

		// ── Welcome / Onboarding ──────────────────────────────────────

		showWelcome: function () {
			var data = window.pressarkData;
			var isFirstTime = !data.isOnboarded;
			var hasWoo = data.hasWooCommerce;
			var images = (data && data.images) || {};
			var logoSrc = this.findImage(images, ['WHITE-APP-LOGO', 'icon', 'app-icon', 'logo']);

			var welcome = document.createElement('div');
			welcome.className = 'pressark-welcome';
			welcome.innerHTML =
				'<div class="pressark-welcome-icon">' +
				(logoSrc ? '<img src="' + logoSrc + '" alt="PressArk">' : '') +
				'</div>' +
				'<div class="pressark-welcome-title">' +
				(isFirstTime ? 'Welcome to PressArk' : 'Welcome back!') +
				'</div>' +
				'<div class="pressark-welcome-subtitle">' +
				(isFirstTime
					? 'Your AI site manager. Ask me anything about your WordPress site.'
					: 'What do you need help with?') +
				'</div>';

			this.messagesEl.appendChild(welcome);

			if (isFirstTime) {
				data.isOnboarded = true;
			}

			this.showSuggestions(hasWoo);
		},

		showSuggestions: function (hasWoo) {
			var self = this;
			var container = document.createElement('div');
			container.className = 'pressark-suggestions';

			var allSuggestions = [
				{ icon: pwIcon('pen'), label: 'Draft Blog Post', message: 'Write a new blog post with SEO-optimized title, meta description, and engaging content that matches my brand voice' },
				{ icon: pwIcon('search'), label: 'Full SEO Audit', message: 'Run a comprehensive SEO audit on my site \u2014 check meta tags, headings, alt text, and give me a prioritized fix list' },
				{ icon: pwIcon('shield'), label: 'Security Scan', message: 'Scan my site for security vulnerabilities and outdated components, then suggest fixes' },
				{ icon: pwIcon('barChart'), label: 'Content Performance', message: 'Analyze my published content and identify which posts need updating, better SEO, or more engagement hooks' },
				{ icon: pwIcon('sparkles'), label: 'Rewrite & Improve', message: 'Review my homepage content and rewrite it to be more compelling, conversion-focused, and SEO-friendly' },
				{ icon: pwIcon('refresh'), label: 'Bulk Find & Replace', message: 'Find and replace text, links, or outdated references across all my pages and posts' },
				{ icon: pwIcon('broom'), label: 'Site Cleanup', message: 'Clean up my database \u2014 remove post revisions, spam comments, orphaned metadata, and transient data' },
				{ icon: pwIcon('clipboard'), label: 'Content Overview', message: 'Give me a complete overview of all my content \u2014 pages, posts, and their publish status, word count, and last updated date' },
			];

			if (hasWoo) {
				allSuggestions.splice(2, 0,
					{ icon: pwIcon('store'), label: 'Store Health Check', message: 'Analyze my WooCommerce store health \u2014 check inventory levels, missing product data, and optimization opportunities' },
					{ icon: pwIcon('trendUp'), label: 'Product Optimizer', message: 'Review my products and improve titles, descriptions, and SEO metadata for better search visibility and conversions' }
				);
			}

			// Show 6 random suggestions each time for variety.
			var shuffled = allSuggestions.sort(function () { return 0.5 - Math.random(); });
			var suggestions = shuffled.slice(0, 6);

			for (var i = 0; i < suggestions.length; i++) {
				(function (s) {
					var btn = document.createElement('button');
					btn.className = 'pressark-suggestion';
					btn.innerHTML = s.icon + ' ' + self.escapeHtml(s.label);
					btn.addEventListener('click', function () {
						self.inputEl.value = s.message;
						self.sendMessage();
						var sugs = self.messagesEl.querySelectorAll('.pressark-suggestions');
						for (var j = 0; j < sugs.length; j++) {
							sugs[j].remove();
						}
					});
					container.appendChild(btn);
				})(suggestions[i]);
			}

			this.messagesEl.appendChild(container);
			this.scrollToBottom();
		},

		renderSuggestionChips: function (suggestions) {
			if (!suggestions || !suggestions.length) return;
			var self = this;
			var container = document.createElement('div');
			container.className = 'pressark-suggestions pressark-suggestion-chips';

			var count = Math.min(suggestions.length, 3);
			for (var i = 0; i < count; i++) {
				(function (text) {
					var chip = document.createElement('button');
					chip.className = 'pressark-chip';
					chip.textContent = text;
					chip.addEventListener('click', function () {
						self.inputEl.value = text;
						self.sendMessage();
					});
					container.appendChild(chip);
				})(suggestions[i]);
			}

			this.messagesEl.appendChild(container);
			this.scrollToBottom();
		},

		// ── Timestamps ────────────────────────────────────────────────

		maybeAddTimestamp: function () {
			var now = Date.now();
			if (this.lastMessageTime && (now - this.lastMessageTime) > 5 * 60 * 1000) {
				var tsEl = document.createElement('div');
				tsEl.className = 'pressark-timestamp';
				var d = new Date(now);
				var hours = d.getHours();
				var minutes = d.getMinutes();
				var ampm = hours >= 12 ? 'PM' : 'AM';
				hours = hours % 12 || 12;
				minutes = minutes < 10 ? '0' + minutes : minutes;
				tsEl.textContent = '\u2014 ' + hours + ':' + minutes + ' ' + ampm + ' \u2014';
				this.messagesEl.appendChild(tsEl);
			}
			this.lastMessageTime = now;
		},

		// ── Message Rendering ─────────────────────────────────────────

		autoGrow: function () {
			this.inputEl.style.height = 'auto';
			this.inputEl.style.height = Math.min(this.inputEl.scrollHeight, 120) + 'px';
		},

		/**
		 * Strip any remaining JSON code blocks from AI message text.
		 */
		cleanMessageContent: function (text) {
			text = text.replace(/```(?:json)?[\s\S]*?```/g, '');
			text = text.replace(/\{\s*"actions"\s*:\s*\[[\s\S]*\]\s*\}\s*$/g, '');
			return text.trim();
		},

		/**
		 * Render text with markdown-like formatting.
		 */
		renderFormattedMessage: function (text) {
			text = this.cleanMessageContent(text);

			// Escape HTML first — full entity escaping including quotes
			// to prevent attribute injection via crafted markdown links.
			var escaped = text
				.replace(/&/g, '&amp;')
				.replace(/</g, '&lt;')
				.replace(/>/g, '&gt;')
				.replace(/"/g, '&quot;')
				.replace(/'/g, '&#39;');

			// Code blocks (``` ... ```).
			escaped = escaped.replace(/```(\w*)\n?([\s\S]*?)```/g, function (m, lang, code) {
				return '<pre class="pressark-code-block"><code>' + code.trim() + '</code></pre>';
			});

			// Inline code (`...`).
			escaped = escaped.replace(/`([^`\n]+)`/g, '<code class="pressark-inline-code">$1</code>');

			// Headers (### ... or ## ... or # ...).
			escaped = escaped.replace(/^### (.+)$/gm, '<strong class="pressark-h3">$1</strong>');
			escaped = escaped.replace(/^## (.+)$/gm, '<strong class="pressark-h2">$1</strong>');
			escaped = escaped.replace(/^# (.+)$/gm, '<strong class="pressark-h1">$1</strong>');

			// Bold (**text** or __text__).
			escaped = escaped.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
			escaped = escaped.replace(/__(.+?)__/g, '<strong>$1</strong>');

			// Italic (*text* or _text_) — careful not to match bold markers.
			escaped = escaped.replace(/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/g, '<em>$1</em>');

			// Unordered list items (- item or * item).
			escaped = escaped.replace(/^[\-\*] (.+)$/gm, '<span class="pressark-list-item">\u2022 $1</span>');

			// Ordered list items (1. item).
			escaped = escaped.replace(/^(\d+)\. (.+)$/gm, '<span class="pressark-list-item">$1. $2</span>');

			// Links [text](url) — only allow http/https URLs.
			// Reject URLs containing whitespace or unescaped control chars.
			var self = this;
			escaped = escaped.replace(/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/g, function (m, linkText, url) {
				// Strip any residual entity-decoded quotes or dangerous chars from the URL.
				if (/[\s<>]/.test(url) || url.indexOf('javascript:') === 0) {
					return linkText;
				}
				return '<a href="' + url + '" target="_blank" rel="noopener">' + linkText + '</a>';
			});

			// Newlines.
			escaped = escaped.replace(/\n/g, '<br>');

			return escaped;
		},

		addMessage: function (type, text, skipSave) {
			// Remove suggestion chips and welcome on first user message.
			if (type === 'user') {
				var sugs = this.messagesEl.querySelectorAll('.pressark-suggestions');
				for (var i = 0; i < sugs.length; i++) {
					sugs[i].remove();
				}
				var welcomes = this.messagesEl.querySelectorAll('.pressark-welcome');
				for (var w = 0; w < welcomes.length; w++) {
					welcomes[w].remove();
				}
			}

			this.maybeAddTimestamp();

			var msgEl = document.createElement('div');
			msgEl.dataset.timestamp = String(Date.now());

			if (type === 'ai') {
				msgEl.className = 'pressark-message pressark-message-assistant';
				var images = (window.pressarkData && window.pressarkData.images) || {};
				var logoSrc = this.findImage(images, ['WHITE-APP-LOGO', 'icon', 'app-icon']);
				msgEl.innerHTML =
					'<div class="pressark-ai-label">' +
					(logoSrc ? '<img src="' + logoSrc + '" alt="PressArk">' : '') +
					'<span>PressArk</span>' +
					'</div>' +
					'<div class="pressark-message-content">' + this.renderFormattedMessage(text) + '</div>';
			} else if (type === 'error') {
				msgEl.className = 'pressark-message pressark-message-error';
				msgEl.innerHTML = this.renderErrorMessage(text);
			} else if (type === 'user') {
				msgEl.className = 'pressark-message pressark-message-user';
				var contentDiv = document.createElement('div');
				contentDiv.className = 'pressark-message-content';
				contentDiv.textContent = text;
				msgEl.appendChild(contentDiv);
			} else {
				msgEl.className = 'pressark-message pressark-message-system';
				var contentDiv2 = document.createElement('div');
				contentDiv2.className = 'pressark-message-content';
				contentDiv2.textContent = text;
				msgEl.appendChild(contentDiv2);
			}

			this.messagesEl.appendChild(msgEl);
			this.scrollToBottom();

			if (!skipSave) {
				this.autoSaveChat();
			}
		},

		renderErrorMessage: function (text) {
			var html = '<span class="pressark-error-icon">' + pwIcons.warning + '</span>';
			html += '<span class="pressark-message-content">' + this.escapeHtml(text) + '</span>';
			html += '<button class="pressark-retry-btn">Retry</button>';
			return html;
		},

		escapeHtml: function (text) {
			return String(text)
				.replace(/&/g, '&amp;')
				.replace(/</g, '&lt;')
				.replace(/>/g, '&gt;')
				.replace(/"/g, '&quot;')
				.replace(/'/g, '&#39;');
		},

		addActionResult: function (result) {
			var msgEl = document.createElement('div');
			msgEl.dataset.timestamp = String(Date.now());

			if (result.upgrade_prompt) {
				msgEl.className = 'pressark-upgrade-prompt';
				var upgradeUrl = window.pressarkData.upgradeUrl || '#';
				msgEl.innerHTML =
					'<strong>Free edit limit reached</strong>' +
					'<p>You\'ve used all free tool actions this week. Scans and analysis are still unlimited. Resets every Monday.</p>' +
					'<a href="' + this.escapeHtml(upgradeUrl) + '" target="_blank" class="pressark-upgrade-btn">Upgrade to Pro</a>';
				this.messagesEl.appendChild(msgEl);
				this.scrollToBottom();
				return;
			}

			msgEl.className = result.success
				? 'pressark-action-result pressark-action-success'
				: 'pressark-action-result pressark-action-fail';
			var icon = result.success ? pwIcon('check') : pwIcon('x');

			var contentHtml = '<span>' + icon + ' ' + this.escapeHtml(result.message) + '</span>';

			if (result.success && result.log_id) {
				contentHtml += '<button class="pressark-undo-btn" data-log-id="' + result.log_id + '">Undo</button>';
			}

			msgEl.innerHTML = contentHtml;

			// Show download link for reports.
			if (result.data && result.data.download_url) {
				var downloadBtn = document.createElement('a');
				downloadBtn.href = result.data.download_url;
				downloadBtn.target = '_blank';
				downloadBtn.className = 'pressark-download-btn';
				downloadBtn.innerHTML = pwIcon('fileDown') + ' Download Report';
				downloadBtn.download = result.data.filename || 'report.html';
				msgEl.appendChild(downloadBtn);
			}

			this.messagesEl.appendChild(msgEl);
			this.scrollToBottom();

			if (result.log_id) {
				this.bindUndoButton(msgEl.querySelector('.pressark-undo-btn'));
			}
		},

		// ── Preview Card Rendering ────────────────────────────────────

		renderPreviewCard: function (pendingAction, runId, actionIndex) {
			var self = this;
			var preview = pendingAction.preview;
			var action = pendingAction.action;

			// Store action in JS-side map instead of HTML attribute (A13: prevents XSS).
			var actionId = 'action_' + (++this.actionIdCounter);
			this.pendingActions[actionId] = action;
			this.pendingPreviews[actionId] = preview;
			this.pendingActionIndices[actionId] = (typeof actionIndex !== 'undefined') ? actionIndex : 0;
			// v3.1.0: Associate run_id so /confirm can resume the durable run.
			if (runId) {
				this.pendingRunIds[actionId] = runId;
			}

			var card = document.createElement('div');
			card.className = 'pressark-preview-card';

			var changesHTML = '';
			if (preview.changes && preview.changes.length > 0) {
				for (var i = 0; i < preview.changes.length; i++) {
					var change = preview.changes[i];
					changesHTML +=
						'<div class="pressark-preview-change">' +
						'<div class="pressark-preview-field">' + this.escapeHtml(change.field) + '</div>' +
						'<div class="pressark-preview-before">' +
						'<span class="pressark-preview-label">Before: </span>' +
						'<span class="pressark-preview-value">' + this.escapeHtml(change.before) + '</span>' +
						'</div>' +
						'<div class="pressark-preview-after">' +
						'<span class="pressark-preview-label">After: </span>' +
						'<span class="pressark-preview-value">' + this.escapeHtml(change.after) + '</span>' +
						'</div>' +
						'</div>';
				}
			}

			var warningsHTML = '';
			if (preview.seo_warnings && preview.seo_warnings.length > 0) {
				warningsHTML = '<div class="pressark-seo-warnings">';
				for (var w = 0; w < preview.seo_warnings.length; w++) {
					var warn = preview.seo_warnings[w];
					var warnClass = 'pressark-seo-warn--' + (warn.type || 'info');
					var warnIcon = warn.type === 'warning' ? pwIcons.warning
						: warn.type === 'caution' ? pwIcons.warning
						: pwIcons.info;
					warningsHTML +=
						'<div class="pressark-seo-warn ' + warnClass + '">' +
						'<span class="pressark-seo-warn-icon">' + warnIcon + '</span>' +
						'<span class="pressark-seo-warn-label">' + this.escapeHtml(warn.label) + '</span>' +
						'<span class="pressark-seo-warn-detail">' + this.escapeHtml(warn.detail) + '</span>' +
						'</div>';
				}
				warningsHTML += '</div>';
			}

			var title = preview.post_title
				? 'Proposed changes to "' + this.escapeHtml(preview.post_title) + '"'
				: 'Proposed changes';

			card.innerHTML =
				'<div class="pressark-preview-header">' +
				this.getPreviewDocumentIconMarkup() +
				'<span class="pressark-preview-title">' + title + '</span>' +
				'</div>' +
				'<div class="pressark-preview-changes">' + changesHTML + '</div>' +
				warningsHTML +
				'<div class="pressark-preview-actions">' +
				'<button type="button" class="pressark-preview-confirm">' +
				this.getPreviewButtonContent('&#10003;', 'Apply Changes') +
				'</button>' +
				'<button type="button" class="pressark-preview-cancel">' +
				this.getPreviewButtonContent('&#10005;', 'Cancel') +
				'</button>' +
				'</div>';

			card.querySelector('.pressark-preview-confirm').addEventListener('click', function (e) {
				var btn = e.currentTarget;
				var actionData = self.pendingActions[actionId];
				var actionRunId = self.pendingRunIds[actionId] || '';
				var actionPendingIndex = self.pendingActionIndices[actionId] || 0;
				btn.textContent = 'Applying...';
				btn.disabled = true;
				card.querySelector('.pressark-preview-cancel').disabled = true;

				self.confirmAction(actionData, true, actionRunId, actionPendingIndex).then(function (result) {
					var previewData = self.pendingPreviews[actionId];
					self.renderConfirmResult(card, result, previewData);
					delete self.pendingActions[actionId];
					delete self.pendingPreviews[actionId];
					delete self.pendingRunIds[actionId];
					delete self.pendingActionIndices[actionId];

					if (result && result.checkpoint && typeof result.checkpoint === 'object') {
						self.checkpoint = result.checkpoint;
					}

					// v3.7.3: Auto-resume with enriched continuation context.
					// Uses [Continue] prefix so server-side classify_task detects
					// this is a continuation and classifies based on the ORIGINAL
					// user request (from conversation history), selecting the right
					// model tier and domain skills for remaining steps.
					if (result && result.success && !result.cancelled
						&& self.shouldAutoResume(result)
						&& Object.keys(self.pendingActions).length === 0) {
						setTimeout(function () {
							self.sendMessage(self.buildContinuationMessage(result, result.message || 'Action applied.'));
						}, 600);
					} else if (result && result.success && result.continuation && result.continuation.pause_message) {
						self.addMessage('ai', result.continuation.pause_message);
						self.conversation.push({ role: 'assistant', content: result.continuation.pause_message });
					}
				});
			});

			card.querySelector('.pressark-preview-cancel').addEventListener('click', function () {
				var actionData = self.pendingActions[actionId];
				var actionRunId = self.pendingRunIds[actionId] || '';
				var actionPendingIndex = self.pendingActionIndices[actionId] || 0;
				var previewData = self.pendingPreviews[actionId];
				self.confirmAction(actionData, false, actionRunId, actionPendingIndex);
				card.innerHTML =
					'<div class="pressark-preview-cancelled">' +
					'<span>' + pwIcon('x') + ' Changes cancelled</span>' +
					'</div>';
				card.classList.add('pressark-preview-dismissed');
				// Save card summary for history rendering.
				if (previewData) {
					self.conversation.push({ role: 'assistant', content: self.buildCardSummary(previewData, 'cancelled') });
				}
				delete self.pendingActions[actionId];
				delete self.pendingPreviews[actionId];
				delete self.pendingRunIds[actionId];
				delete self.pendingActionIndices[actionId];
				self.autoSaveChat();
			});

			this.messagesEl.appendChild(card);
			this.scrollToBottom();
		},

		/**
		 * Build a serialisable card summary for conversation history.
		 * Format: [PRESSARK_CARD:<status>]<title>||<field:before→after>||...
		 */
		buildCardSummary: function (preview, status) {
			var title = preview.post_title
				? 'Proposed changes to "' + preview.post_title + '"'
				: 'Proposed changes';
			var parts = [title];
			if (preview.changes && preview.changes.length > 0) {
				for (var i = 0; i < preview.changes.length; i++) {
					var c = preview.changes[i];
					parts.push((c.field || '') + ': ' + (c.before || '(empty)') + ' \u2192 ' + (c.after || '(empty)'));
				}
			}
			return '[PRESSARK_CARD:' + status + ']' + parts.join('||');
		},

		getPreviewDocumentIconMarkup: function () {
			return '<span class="pressark-preview-icon" aria-hidden="true">' +
				'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">' +
				'<path d="M8 3.75h6l4 4v12.5a.75.75 0 0 1-.75.75H8a2.25 2.25 0 0 1-2.25-2.25V6A2.25 2.25 0 0 1 8 3.75Z"></path>' +
				'<path d="M14 3.75V8h4"></path>' +
				'<path d="M9 12h6"></path>' +
				'<path d="M9 15.5h6"></path>' +
				'</svg>' +
				'</span>';
		},

		getPreviewButtonContent: function (iconMarkup, label) {
			return '<span class="pressark-btn__icon" aria-hidden="true">' + iconMarkup + '</span>' +
				'<span class="pressark-btn__label">' + this.escapeHtml(label) + '</span>';
		},

		setPreviewFooterBusy: function (footerEl, isBusy, action) {
			if (!footerEl) return;

			var keepBtn = footerEl.querySelector('.pressark-btn--keep');
			var discardBtn = footerEl.querySelector('.pressark-btn--discard');

			if (keepBtn) {
				keepBtn.disabled = !!isBusy;
				keepBtn.setAttribute('aria-busy', isBusy && action === 'keep' ? 'true' : 'false');
				keepBtn.innerHTML = this.getPreviewButtonContent('&#10003;', isBusy && action === 'keep' ? 'Applying...' : 'Keep Changes');
			}

			if (discardBtn) {
				discardBtn.disabled = !!isBusy;
				discardBtn.setAttribute('aria-busy', isBusy && action === 'discard' ? 'true' : 'false');
				discardBtn.innerHTML = this.getPreviewButtonContent('&#10005;', isBusy && action === 'discard' ? 'Discarding...' : 'Discard');
			}
		},

		setPreviewFooterSettled: function (status) {
			if (!this.activePreviewFooterEl) return;

			var statusClass = status === 'keep'
				? 'pressark-preview-footer__status--keep'
				: 'pressark-preview-footer__status--discard';
			var label = status === 'keep' ? 'Preview approved' : 'Preview discarded';
			var icon = status === 'keep' ? '&#10003;' : '&#10005;';

			this.activePreviewFooterEl.classList.add('pressark-preview-footer--settled');
			this.activePreviewFooterEl.innerHTML =
				'<div class="pressark-preview-footer__status ' + statusClass + '">' +
				this.getPreviewButtonContent(icon, label) +
				'</div>';
			this.activePreviewFooterEl = null;
		},

		/**
		 * Render a static (non-interactive) card from a history summary string.
		 */
		renderStaticCard: function (content) {
			var match = content.match(/^\[PRESSARK_CARD:(applied|cancelled)\](.*)/);
			if (!match) return null;

			var status = match[1];
			var payload = match[2];
			var parts = payload.split('||');
			var title = parts[0] || 'Proposed changes';

			var card = document.createElement('div');
			card.className = 'pressark-preview-card ' +
				(status === 'applied' ? 'pressark-preview-applied' : 'pressark-preview-dismissed');

			var changesHTML = '';
			for (var i = 1; i < parts.length; i++) {
				var fieldMatch = parts[i].match(/^(.+?): (.+?) \u2192 (.+)$/);
				if (fieldMatch) {
					changesHTML +=
						'<div class="pressark-preview-change">' +
						'<div class="pressark-preview-field">' + this.escapeHtml(fieldMatch[1]) + '</div>' +
						'<div class="pressark-preview-before">' +
						'<span class="pressark-preview-label">Before: </span>' +
						'<span class="pressark-preview-value">' + this.escapeHtml(fieldMatch[2]) + '</span>' +
						'</div>' +
						'<div class="pressark-preview-after">' +
						'<span class="pressark-preview-label">After: </span>' +
						'<span class="pressark-preview-value">' + this.escapeHtml(fieldMatch[3]) + '</span>' +
						'</div>' +
						'</div>';
				}
			}

			var resultHTML = status === 'applied'
				? '<div class="pressark-action-result pressark-action-success"><span>' + pwIcon('check') + ' Changes applied successfully</span></div>'
				: '<div class="pressark-preview-cancelled"><span>' + pwIcon('x') + ' Changes cancelled</span></div>';

			card.innerHTML =
				'<div class="pressark-preview-header">' +
				this.getPreviewDocumentIconMarkup() +
				'<span class="pressark-preview-title">' + this.escapeHtml(title) + '</span>' +
				'</div>' +
				'<div class="pressark-preview-changes">' + changesHTML + '</div>' +
				'<div class="pressark-preview-actions">' + resultHTML + '</div>';

			return card;
		},

		confirmAction: function (actionData, confirmed, runId, actionIndex) {
			var data = window.pressarkData;
			var payload = {
				confirmed: confirmed,
				run_id: runId || '',
				action_index: (typeof actionIndex !== 'undefined') ? actionIndex : 0
			};
			return fetch(data.restUrl + 'confirm', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': data.nonce
				},
				body: JSON.stringify(payload)
			})
				.then(function (response) { return response.json(); })
				.catch(function () {
					return { success: false, message: 'Network error. Please try again.' };
				});
		},

		renderConfirmResult: function (card, result, previewData) {
			var actionsDiv = card.querySelector('.pressark-preview-actions');

			if (result.success) {
				var undoHtml = '';
				if (result.log_id) {
					undoHtml = '<button class="pressark-undo-btn" data-log-id="' + result.log_id + '">Undo</button>';
				}
				// v3.1.0: Surface post-apply verification summary.
				var verifyHtml = '';
				if (result.verification && result.verification.message) {
					var verifyClass = result.verification.is_error
						? 'pressark-verify-warning' : 'pressark-verify-ok';
					verifyHtml = '<div class="pressark-verification ' + verifyClass + '">' +
						this.escapeHtml(result.verification.message) + '</div>';
				}
				actionsDiv.innerHTML =
					'<div class="pressark-action-result pressark-action-success">' +
					'<span>' + pwIcon('check') + ' ' + this.escapeHtml(result.message || 'Changes applied successfully') + '</span>' +
					undoHtml +
					'</div>' + verifyHtml;
				card.classList.add('pressark-preview-applied');
				// Save card summary for history rendering.
				var cardContent = previewData
					? this.buildCardSummary(previewData, 'applied')
					: (result.message || 'Changes applied successfully.');
				this.conversation.push({ role: 'assistant', content: cardContent });
				if (result.usage) {
					this.updateUsage(result.usage);
				}

				var undoBtn = actionsDiv.querySelector('.pressark-undo-btn');
				if (undoBtn) this.bindUndoButton(undoBtn);
			} else if (result.upgrade_prompt) {
				var upgradeUrl = window.pressarkData.upgradeUrl || '#';
				actionsDiv.innerHTML =
					'<div class="pressark-upgrade-prompt">' +
					'<strong>Free edit limit reached</strong>' +
					'<p>You\'ve used all free tool actions this week. Scans are still unlimited. Resets every Monday.</p>' +
					'<a href="' + this.escapeHtml(upgradeUrl) + '" target="_blank" class="pressark-upgrade-btn">Upgrade to Pro</a>' +
					'</div>';
			} else {
				actionsDiv.innerHTML =
					'<div class="pressark-action-result pressark-action-fail">' +
					'<span>' + pwIcon('x') + ' ' + this.escapeHtml(result.message || 'Failed to apply changes') + '</span>' +
					'</div>';
			}

			this.autoSaveChat();
		},

		// ── Usage Display Update ──────────────────────────────────────

		buildContinuationMessage: function (result, fallbackSummary) {
			var continuation = (result && result.continuation) ? result.continuation : {};
			var execution = continuation.execution || ((result && result.checkpoint && result.checkpoint.execution) ? result.checkpoint.execution : {});
			var targets = (result && result.targets && result.targets.length)
				? result.targets
				: (continuation.targets || []);
			var primaryTarget = targets.length ? (targets[0] || {}) : {};
			var summary = fallbackSummary || (result && result.message) || 'Action applied.';
			var completed = [];
			var remaining = [];
			var postTitle = '';
			var postId = '';
			var url = '';
			var seoRemainingForTarget = false;

			if (result) {
				postTitle = result.post_title || '';
				postId = result.post_id || '';
				url = result.url || '';
			}

			if (!postTitle && continuation.post_title) postTitle = continuation.post_title;
			if (!postId && continuation.post_id) postId = continuation.post_id;
			if (!url && continuation.url) url = continuation.url;

			if (!postTitle && primaryTarget.post_title) postTitle = primaryTarget.post_title;
			if (!postId && primaryTarget.post_id) postId = primaryTarget.post_id;
			if (!url && primaryTarget.url) url = primaryTarget.url;

			if (execution && execution.current_target) {
				if (!postTitle && execution.current_target.post_title) postTitle = execution.current_target.post_title;
				if (!postId && execution.current_target.post_id) postId = execution.current_target.post_id;
				if (!url && execution.current_target.url) url = execution.current_target.url;
			}

			if (execution && execution.tasks && execution.tasks.length) {
				execution.tasks.forEach(function (task) {
					if (!task || !task.label) return;
					if (task.key === 'optimize_seo' && task.status !== 'done' && task.status !== 'completed') {
						seoRemainingForTarget = true;
					}
					if (task.status === 'done' || task.status === 'completed') {
						completed.push(task.label);
					} else {
						remaining.push(task.label);
					}
				});
			}

			if (completed.length) {
				summary = 'Completed: ' + completed.join('; ') + '.';
			}
			if (remaining.length) {
				summary += ' Remaining: ' + remaining.join('; ') + '.';
			}
			if (!remaining.length && completed.length) {
				summary += ' All requested steps appear complete. Do not continue automatically unless the user explicitly asks for more work.';
			}

			if (postTitle && summary.toLowerCase().indexOf(String(postTitle).toLowerCase()) === -1) {
				summary += ' Continue using "' + postTitle + '".';
			}
			if (seoRemainingForTarget && postId) {
				summary += ' Optimize SEO only for this existing post. Do not scan or change any other posts or pages.';
			}

			var extras = [];
			if (postId) extras.push('post_id=' + postId);
			if (url) extras.push('url=' + url);
			if (extras.length) summary += ' [' + extras.join(', ') + ']';

			return '[Continue] ' + summary + ' Do not repeat completed steps or recreate completed content. Please continue with the remaining steps from my original request.';
		},

		buildCompactionContinuationMessage: function (result) {
			var parts = ['[Continue] Context was compressed mid-task. The conversation summary above contains all prior decisions and progress.'];
			var cp = (result && result.checkpoint) ? result.checkpoint : {};
			var exec = cp.execution || {};
			var target = exec.current_target || {};

			if (target.post_title) {
				parts.push('Working on: "' + target.post_title + '" (post_id=' + (target.post_id || '?') + ').');
			}

			var completed = exec.completed_steps || [];
			if (!completed.length && exec.tasks && exec.tasks.length) {
				completed = exec.tasks.filter(function (task) {
					return task && (task.status === 'done' || task.status === 'completed') && task.label;
				}).map(function (task) {
					return task.label;
				});
			}
			if (completed.length) {
				parts.push('Already completed: ' + completed.join(', ') + '.');
			}

			// Include loaded tool groups so AI doesn't re-load them
			var loadedGroups = (result && result.loaded_groups) ? result.loaded_groups : (cp.loaded_tool_groups || []);
			if (loadedGroups.length) {
				parts.push('Tools already loaded: ' + loadedGroups.join(', ') + '. Do NOT call discover_tools or load_tools for these.');
			}

			parts.push('CRITICAL: Do NOT re-discover or re-load tools. Do NOT recreate content that already exists. Do NOT repeat completed steps. Continue with the NEXT remaining step only.');
			return parts.join(' ');
		},

		isSilentContinuationResult: function (result) {
			return !!(result && result.silent_continuation);
		},

		normalizeStepStatus: function (status) {
			return status === 'compressing_context' ? 'reading' : (status || 'done');
		},

		getSilentContinuationStep: function () {
			return {
				status: 'compressing_context',
				label: 'Compressing context…',
				tool: '_context_compaction',
			};
		},

		renderSilentContinuationStep: function (result, beforeEl) {
			var steps = (result && result.steps && result.steps.length > 0)
				? result.steps
				: [this.getSilentContinuationStep()];

			if (beforeEl) {
				this.renderActivityStripBefore(steps, beforeEl);
			} else {
				this.renderActivityStrip(steps);
			}
		},

		shouldAutoResume: function (result) {
			var continuation = (result && result.continuation) ? result.continuation : {};
			var progress = continuation.progress || {};

			if (typeof continuation.should_auto_resume === 'boolean') {
				return continuation.should_auto_resume;
			}
			if (typeof progress.should_auto_resume === 'boolean') {
				return progress.should_auto_resume;
			}
			if (typeof progress.remaining_count === 'number') {
				return progress.remaining_count > 0;
			}

			return false;
		},

		shouldAutoContinueCompactedRun: function (result) {
			if (!result || result.is_error || result.cancelled) {
				return false;
			}

			if ((result.type || 'final_response') !== 'final_response') {
				return false;
			}

			if ((result.exit_reason || '') !== 'max_request_icus_compacted') {
				return false;
			}

			return !!(result.checkpoint && typeof result.checkpoint === 'object');
		},

		applyResultState: function (result) {
			if (!result || typeof result !== 'object') {
				return;
			}

			if (result.usage) {
				this.updateUsage(result.usage);
			}
			if (result.token_status) {
				this.updateTokenDisplay(result.token_status);
			}
			if (result.chat_id && (!this.currentChatId || this.currentChatId !== result.chat_id)) {
				this.currentChatId = result.chat_id;
			}
			if (result.loaded_groups && Array.isArray(result.loaded_groups)) {
				this.loadedGroups = result.loaded_groups;
			}
			if (result.checkpoint && typeof result.checkpoint === 'object') {
				this.checkpoint = result.checkpoint;
			}
			if (result.plan_info) {
				window.pressarkData.plan_info = result.plan_info;
				this.renderQuotaBar();
			}
		},

		continueCompactedRun: function (result) {
			if (!this.shouldAutoContinueCompactedRun(result) || this.isSending) {
				return false;
			}

			this.applyResultState(result);
			this.autoSaveChat();

			var self = this;
			var resumeDelay = this.isSilentContinuationResult(result) ? 100 : 150;
			setTimeout(function () {
				self.sendMessage(self.buildCompactionContinuationMessage(result));
			}, resumeDelay);

			return true;
		},

		resolveContinuationPostId: function (message, fallbackPostId) {
			var execution = (this.checkpoint && this.checkpoint.execution) ? this.checkpoint.execution : {};
			var target = (execution && execution.current_target) ? execution.current_target : {};
			var targetId = parseInt(target.post_id || 0, 10);
			if (targetId > 0) return targetId;

			var match = String(message || '').match(/\bpost_id\s*=\s*(\d+)\b/i);
			if (match && match[1]) {
				return parseInt(match[1], 10) || fallbackPostId || 0;
			}

			return fallbackPostId || 0;
		},

		updateUsageDisplay: function () {
			this.renderQuotaBar();
		},

		// ── Undo ──────────────────────────────────────────────────────

		bindUndoButton: function (btn) {
			if (!btn) return;
			var self = this;
			btn.addEventListener('click', function () {
				var logId = parseInt(btn.dataset.logId, 10);
				if (!logId) return;

				btn.textContent = 'Undoing...';
				btn.disabled = true;

				var data = window.pressarkData;
				fetch(data.restUrl + 'undo', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': data.nonce,
					},
					body: JSON.stringify({ log_id: logId }),
				})
					.then(function (r) { return r.json(); })
					.then(function (result) {
						var parent = btn.parentNode;
						if (result.success) {
							var contentEl = parent.querySelector('.pressark-message-content') || parent.querySelector('span');
							if (contentEl) {
								contentEl.innerHTML = pwIcon('undo') + ' Undone \u2014 restored previous version';
							}
							btn.remove();
						} else {
							btn.textContent = result.message || 'Undo failed';
							btn.disabled = true;
						}
						self.autoSaveChat();
					})
					.catch(function () {
						btn.textContent = 'Undo failed';
						btn.disabled = true;
					});
			});
		},

		rebindUndoButtons: function () {
			var btns = this.messagesEl.querySelectorAll('.pressark-undo-btn');
			for (var i = 0; i < btns.length; i++) {
				this.bindUndoButton(btns[i]);
			}
			var retryBtns = this.messagesEl.querySelectorAll('.pressark-retry-btn');
			for (var j = 0; j < retryBtns.length; j++) {
				this.bindRetryButton(retryBtns[j]);
			}
		},

		// ── Retry ─────────────────────────────────────────────────────

		bindRetryButton: function (btn) {
			if (!btn) return;
			var self = this;
			btn.addEventListener('click', function () {
				if (self.lastUserMessage) {
					self.inputEl.value = self.lastUserMessage;
					self.sendMessage();
				}
			});
		},

		// ── Scroll & Typing ───────────────────────────────────────────

		scrollToBottom: function () {
			var el = this.messagesEl;
			setTimeout(function () {
				el.scrollTop = el.scrollHeight;
			}, 50);
		},

		showTyping: function (text) {
			this.removeTypingIndicator();

			var indicator = document.createElement('div');
			indicator.className = 'pressark-typing-indicator';
			indicator.innerHTML =
				'<div class="pressark-message-content">' +
				'<div class="pressark-typing-dots">' +
				'<span></span>' +
				'<span></span>' +
				'<span></span>' +
				'</div>' +
				'<span>' + this.escapeHtml(text || 'PressArk is thinking') + '</span>' +
				'</div>';

			this.messagesEl.appendChild(indicator);
			this.scrollToBottom();
		},

		removeTypingIndicator: function () {
			var existing = this.messagesEl.querySelector('.pressark-typing-indicator');
			if (existing) existing.remove();
		},

		hideTyping: function () {
			this.removeTypingIndicator();
		},

		// ── Quota Bar ─────────────────────────────────────────────────

		formatCredits: function (n) {
			if (n >= 1000000) {
				var m = Math.round(n / 100000) / 10;
				return (m % 1 === 0 ? m.toFixed(0) : m.toFixed(1)) + 'M';
			}
			if (n >= 1000) return Math.round(n / 1000) + 'K';
			return String(n);
		},

		formatTokens: function (n) {
			return this.formatCredits(n);
		},

		formatResetDate: function (isoString) {
			if (!isoString) return '';
			var parsed = new Date(isoString);
			if (isNaN(parsed.getTime())) return '';
			var monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
			return monthNames[parsed.getMonth()] + ' ' + parsed.getDate();
		},

		renderCreditPackLinks: function () {
			var packs = (window.pressarkData || {}).creditPacks || [];
			if (!packs.length) return '';

			var html = '<div class="pressark-upgrade-prompt"><strong>Your billing-cycle credits are used up.</strong>';
			for (var i = 0; i < packs.length; i++) {
				var pack = packs[i];
				var dollars = '$' + ((pack.price_cents || 0) / 100).toFixed(0);
				html += '<p><a href="' + this.escapeHtml(pack.checkoutUrl || (window.pressarkData || {}).creditStoreUrl || '#') + '" target="_blank" class="pressark-upgrade-btn">Buy ' + this.escapeHtml(pack.label || '') + ' - ' + dollars + '</a></p>';
			}
			html += '<p>Purchased credits last 12 months.</p></div>';
			return html;
		},

		getBillingState: function (info) {
			var state = (info && info.billing_state) || {};
			var isByok = !!(info && info.is_byok);
			var monthlyRemaining = info ? (info.monthly_included_remaining || info.monthly_remaining || 0) : 0;
			var purchasedRemaining = info ? (info.purchased_credits_remaining || info.credits_remaining || 0) : 0;
			var legacyRemaining = info ? (info.legacy_bonus_remaining || 0) : 0;
			var totalRemaining = info ? (info.total_remaining || info.icus_remaining || info.tokens_remaining || 0) : 0;
			var hasMonthly = monthlyRemaining > 0;
			var hasPurchased = purchasedRemaining > 0;
			var hasLegacy = legacyRemaining > 0;
			var spendSource = state.spend_source;

			if (!spendSource) {
				if (isByok) spendSource = 'byok';
				else if (hasMonthly && !hasPurchased && !hasLegacy) spendSource = 'monthly_included';
				else if (!hasMonthly && hasPurchased && !hasLegacy) spendSource = 'purchased_credits';
				else if (!hasMonthly && !hasPurchased && hasLegacy) spendSource = 'legacy_bonus';
				else if ((hasMonthly && hasPurchased) || (hasMonthly && hasLegacy) || (hasPurchased && hasLegacy)) spendSource = 'mixed';
				else spendSource = totalRemaining > 0 ? 'monthly_included' : 'depleted';
			}

			var authorityMode = state.authority_mode || (isByok ? 'byok' : ((info && info.verified_handshake) ? 'bank_verified' : 'bank_provisional'));
			var serviceState = state.service_state || ((info && info.offline) ? 'offline_assisted' : 'normal');
			var handshakeState = state.handshake_state || (isByok ? 'byok' : ((info && info.verified_handshake) ? 'verified' : 'provisional'));

			return {
				authority_mode: authorityMode,
				handshake_state: handshakeState,
				service_state: serviceState,
				spend_source: spendSource,
				authority_label: state.authority_label || (authorityMode === 'bank_verified' ? 'Bank verified' : (authorityMode === 'byok' ? 'BYOK' : 'Bank provisional')),
				service_label: state.service_label || (serviceState === 'offline_assisted' ? 'Offline assisted' : (serviceState === 'degraded' ? 'Degraded' : 'Normal')),
				spend_label: state.spend_label || (spendSource === 'purchased_credits' ? 'Purchased credits' : (spendSource === 'legacy_bonus' ? 'Legacy bonus' : (spendSource === 'mixed' ? 'Mixed sources' : (spendSource === 'depleted' ? 'Depleted' : (spendSource === 'byok' ? 'BYOK' : 'Monthly included'))))),
				service_notice: state.service_notice || (serviceState === 'offline_assisted' ? 'Using the last bank snapshot locally until the bank is reachable again.' : (serviceState === 'degraded' ? 'Bank truth is still authoritative while a dependency is degraded.' : '')),
				estimate_notice: state.estimate_notice || (isByok ? 'Provider usage is separate from bundled credits.' : 'Plugin-side token and ICU estimates are advisory until bank settlement.')
			};
		},

		renderQuotaBar: function () {
			if (!this.quotaBarEl) return;

			var info = (window.pressarkData || {}).plan_info;
			if (!info) return;

			var html = '';
			var billingState = this.getBillingState(info);
			var billingTag = billingState.authority_label + ' / ' + billingState.service_label + (billingState.spend_label ? ' / ' + billingState.spend_label : '');

			if (info.is_byok) {
				html = 'Using your own API key \u00B7 Bundled credits bypassed';
			} else if (info.tier === 'free') {
				// Total actions used across all groups this week.
				var gu = info.group_usage || {};
				var actionsUsed = gu.total_used || 0;
				var actionsLimit = gu.total_limit || 6;

				var monthlyRemaining = this.formatCredits(info.icus_remaining || info.tokens_remaining || 0);
				var monthlyBudget = this.formatCredits(info.icu_budget || info.token_budget || 0);

				html = actionsUsed + '/' + actionsLimit + ' actions used this week';
				html += ' \u00B7 ' + monthlyRemaining + '/' + monthlyBudget + ' credits remaining';
				html += ' \u00B7 ' + this.escapeHtml(billingTag);
				html += ' \u00B7 <a href="' + this.escapeHtml(info.upgrade_url || '#') + '">Upgrade to Pro \u2192</a>';

				if (actionsUsed >= actionsLimit) {
					this.quotaBarEl.classList.add('pressark-quota-depleted');
				} else {
					this.quotaBarEl.classList.remove('pressark-quota-depleted');
				}
			} else {
				// Paid tier.
				var creditsUsed = this.formatCredits(info.icus_used || info.tokens_used || 0);
				var monthlyBudgetPaid = this.formatCredits(info.icu_budget || info.token_budget || 0);
				var purchasedRemaining = this.formatCredits(info.purchased_credits_remaining || info.credits_remaining || 0);
				var legacyRemaining = this.formatCredits(info.legacy_bonus_remaining || 0);
				var totalRemaining = this.formatCredits(info.total_remaining || info.icus_remaining || info.tokens_remaining || 0);

				if (info.monthly_exhausted && (info.purchased_credits_remaining || info.credits_remaining || 0) > 0) {
					html = 'Billing-cycle credits used \u00B7 Using purchased credits (' + purchasedRemaining + ' remaining)';
				} else if (info.monthly_exhausted && (info.legacy_bonus_remaining || 0) > 0) {
					html = 'Billing-cycle credits used \u00B7 Using legacy bonus (' + legacyRemaining + ' remaining)';
				} else {
					html = creditsUsed + '/' + monthlyBudgetPaid + ' credits used \u00B7 ' + totalRemaining + ' total remaining';
				}

				var resetLabel = this.formatResetDate(info.next_reset_at || info.billing_period_end || '');
				if (resetLabel) {
					html += ' \u00B7 Billing cycle resets ' + resetLabel;
				}
				html += ' \u00B7 ' + this.escapeHtml(billingTag);
				if (billingState.service_state !== 'normal') {
					html += ' \u00B7 ' + this.escapeHtml(billingState.service_notice);
				}

				var settingsUrl = (window.pressarkData || {}).settings_url || '#';
				var creditStoreUrl = (window.pressarkData || {}).creditStoreUrl || settingsUrl;
				if (info.monthly_exhausted && !info.is_byok && info.can_buy_credits) {
					html += ' \u00B7 <a href="' + this.escapeHtml(creditStoreUrl) + '">Buy credits \u2192</a>';
				} else {
					html += ' \u00B7 <a href="' + this.escapeHtml(settingsUrl) + '">View usage \u2192</a>';
				}

				if (info.monthly_exhausted && (info.credits_remaining || 0) <= 0) {
					this.quotaBarEl.classList.add('pressark-quota-depleted');
				} else {
					this.quotaBarEl.classList.remove('pressark-quota-depleted');
				}
			}

			if (info.is_byok) {
				html += ' \u00B7 ' + this.escapeHtml(billingTag);
				html += ' \u00B7 ' + this.escapeHtml(billingState.estimate_notice);
			}

			this.quotaBarEl.innerHTML = html;
		},

		updateUsage: function (usage) {
			if (window.pressarkData) {
				window.pressarkData.usage = usage;
			}
			this.renderQuotaBar();
		},

		updateTokenDisplay: function (status) {
			if (window.pressarkData && window.pressarkData.plan_info && status) {
				window.pressarkData.plan_info = Object.assign({}, window.pressarkData.plan_info, {
					icus_used: status.icus_used,
					icus_remaining: status.icus_remaining,
					icu_budget: status.icu_budget || window.pressarkData.plan_info.icu_budget,
					credits_remaining: status.purchased_credits_remaining || status.credits_remaining,
					purchased_credits_remaining: status.purchased_credits_remaining || status.credits_remaining,
					legacy_bonus_remaining: status.legacy_bonus_remaining,
					monthly_remaining: status.monthly_remaining,
					monthly_included_remaining: status.monthly_included_remaining,
					monthly_exhausted: status.monthly_exhausted,
					using_purchased_credits: status.using_purchased_credits,
					using_legacy_bonus: status.using_legacy_bonus,
					total_available: status.total_available,
					total_remaining: status.total_remaining,
					raw_tokens_used: status.raw_tokens_used,
					next_reset_at: status.next_reset_at,
					billing_period_start: status.billing_period_start,
					billing_period_end: status.billing_period_end,
					uses_anniversary_reset: status.uses_anniversary_reset,
					billing_authority: status.billing_authority,
					billing_state: status.billing_state,
					billing_service_state: status.billing_service_state,
					billing_handshake_state: status.billing_handshake_state,
					billing_spend_source: status.billing_spend_source,
					verified_handshake: status.verified_handshake,
					provisional_handshake: status.provisional_handshake,
					offline: status.offline
				});
			}
			this.renderQuotaBar();
		},

		// ── Activity Strip (Agentic Loop Steps) ──────────────────────

		renderActivityStrip: function (steps) {
			if (!steps || !steps.length) return;

			var strip = document.createElement('div');
			strip.className = 'pressark-activity-strip';

			for (var i = 0; i < steps.length; i++) {
				var step = steps[i];
				var row = document.createElement('div');
				var status = this.normalizeStepStatus(step.status);
				row.className = 'pressark-step pressark-step--' + status;

				var icon = '';
				switch (status) {
					case 'reading':
						icon = '<span class="pressark-step-icon pressark-step-icon--reading">⟳</span>';
						break;
					case 'done':
						icon = '<span class="pressark-step-icon pressark-step-icon--done">✓</span>';
						break;
					case 'preparing_preview':
						icon = '<span class="pressark-step-icon pressark-step-icon--preview">◉</span>';
						break;
					case 'needs_confirm':
						icon = '<span class="pressark-step-icon pressark-step-icon--confirm">⚠</span>';
						break;
					default:
						icon = '<span class="pressark-step-icon">·</span>';
				}

				row.innerHTML = icon + '<span class="pressark-step-label">' + this.escapeHtml(step.label || step.tool) + '</span>';
				strip.appendChild(row);
			}

			this.messagesEl.appendChild(strip);
			this.scrollToBottom();
		},

		/**
		 * Render activity strip just before a specific element (used at stream finalize).
		 */
		renderActivityStripBefore: function (steps, beforeEl) {
			if (!steps || !steps.length) return;

			var strip = document.createElement('div');
			strip.className = 'pressark-activity-strip';

			for (var i = 0; i < steps.length; i++) {
				var step = steps[i];
				var row = document.createElement('div');
				var status = this.normalizeStepStatus(step.status);
				row.className = 'pressark-step pressark-step--' + status;

				var icon = '';
				switch (status) {
					case 'reading':
						icon = '<span class="pressark-step-icon pressark-step-icon--reading">' + pwIcons.loader + '</span>';
						break;
					case 'done':
						icon = '<span class="pressark-step-icon pressark-step-icon--done">' + pwIcons.check + '</span>';
						break;
					case 'preparing_preview':
						icon = '<span class="pressark-step-icon pressark-step-icon--preview">' + pwIcons.dot + '</span>';
						break;
					case 'needs_confirm':
						icon = '<span class="pressark-step-icon pressark-step-icon--confirm">' + pwIcons.warning + '</span>';
						break;
					default:
						icon = '<span class="pressark-step-icon">\u00B7</span>';
				}

				row.innerHTML = icon + '<span class="pressark-step-label">' + this.escapeHtml(step.label || step.tool) + '</span>';
				strip.appendChild(row);
			}

			this.messagesEl.insertBefore(strip, beforeEl);
			this.scrollToBottom();
		},

		// ── Live Preview System ──────────────────────────────────────

		openPreview: function (data) {
			var self = this;

			// Narrow the chat panel for preview mode.
			this.panel.classList.add('pressark-preview-mode');

			// Build the diff panel.
			var diffHTML = this.buildDiffHTML(data.diff || []);

			// Create preview overlay on wp-admin content area.
			var overlay = document.createElement('div');
			overlay.id = 'pressark-preview-overlay';
			overlay.className = 'pressark-preview-overlay';
			overlay.innerHTML =
				'<div class="pressark-preview-overlay__header">' +
					'<span class="pressark-preview-overlay__title">Preview Changes</span>' +
					'<span class="pressark-preview-overlay__badge">Unsaved</span>' +
				'</div>' +
				'<div class="pressark-preview-body">' +
					'<div class="pressark-preview-diff">' + diffHTML + '</div>' +
					'<iframe class="pressark-preview-iframe" src="' + this.escapeHtml(data.preview_url) + '"></iframe>' +
				'</div>';

			// Insert into wp-admin content area.
			var wpBody = document.getElementById('wpbody-content') || document.getElementById('wpbody') || document.body;
			wpBody.appendChild(overlay);

			// Show Keep/Discard buttons in chat panel.
			this.showPreviewActions(data.preview_session_id, data.diff);
		},

		buildDiffHTML: function (diff) {
			if (!diff || !diff.length) {
				return '<p class="pressark-diff-empty">No field-level changes to display.</p>';
			}

			// Flatten the nested diff format from PHP.
			// PHP sends: [{type, label, items: [{field, old, new}]}]
			// We need flat rows: [{field, before, after}]
			var rows = [];
			for (var i = 0; i < diff.length; i++) {
				var d = diff[i];
				if (d.items && Array.isArray(d.items)) {
					// Nested format from preview system
					for (var j = 0; j < d.items.length; j++) {
						var item = d.items[j];
						rows.push({
							field: item.field || '',
							before: item.old || item.before || '',
							after: item['new'] || item.after || ''
						});
					}
				} else if (d.changes && Array.isArray(d.changes)) {
					// Nested format from confirm card system
					for (var k = 0; k < d.changes.length; k++) {
						var change = d.changes[k];
						rows.push({
							field: change.field || '',
							before: change.before || '',
							after: change.after || ''
						});
					}
				} else {
					// Already flat format (field, before, after)
					rows.push({
						field: d.field || '',
						before: d.old || d.before || '',
						after: d['new'] || d.after || ''
					});
				}
			}

			if (!rows.length) {
				return '<p class="pressark-diff-empty">No field-level changes to display.</p>';
			}

			var html = '<table class="pressark-diff-table">';
			html += '<thead><tr><th>Field</th><th>Before</th><th>After</th></tr></thead><tbody>';

			for (var r = 0; r < rows.length; r++) {
				var row = rows[r];
				html += '<tr>';
				html += '<td class="pressark-diff-field">' + this.escapeHtml(row.field) + '</td>';
				html += '<td class="pressark-diff-before">' + this.escapeHtml(this.truncateText(row.before, 150)) + '</td>';
				html += '<td class="pressark-diff-after">' + this.escapeHtml(this.truncateText(row.after, 150)) + '</td>';
				html += '</tr>';
			}

			html += '</tbody></table>';
			return html;
		},

		truncateText: function (text, maxLen) {
			if (typeof text !== 'string') text = String(text);
			if (text.length <= maxLen) return text;
			return text.substring(0, maxLen) + '…';
		},

		showPreviewActions: function (sessionId, diff) {
			var self = this;
			if (this.activePreviewFooterEl && this.activePreviewFooterEl.parentNode) {
				this.activePreviewFooterEl.parentNode.removeChild(this.activePreviewFooterEl);
			}

			var actionsDiv = document.createElement('div');
			actionsDiv.className = 'pressark-preview-footer';
			actionsDiv.innerHTML =
				'<div class="pressark-preview-footer__actions">' +
					'<button type="button" class="pressark-btn pressark-btn--keep" data-session="' + this.escapeHtml(sessionId) + '">' +
						this.getPreviewButtonContent('&#10003;', 'Keep Changes') +
					'</button>' +
					'<button type="button" class="pressark-btn pressark-btn--discard" data-session="' + this.escapeHtml(sessionId) + '">' +
						this.getPreviewButtonContent('&#10005;', 'Discard') +
					'</button>' +
				'</div>';

			// Add Customizer handoff button if changes are Customizer-eligible.
			if (diff && this.isCustomizerEligible(diff) && window.pressarkData && window.pressarkData.admin_url) {
				var customizerUrl = window.pressarkData.admin_url + 'customize.php?autofocus[section]=title_tagline';
				actionsDiv.innerHTML +=
					'<a href="' + this.escapeHtml(customizerUrl) + '" target="_blank" rel="noopener noreferrer" class="pressark-preview-footer__link">' +
					'View &amp; fine-tune in Customizer</a>';
			}

			this.messagesEl.appendChild(actionsDiv);
			this.activePreviewFooterEl = actionsDiv;
			this.scrollToBottom();

			var keepBtn = actionsDiv.querySelector('.pressark-btn--keep');
			var discardBtn = actionsDiv.querySelector('.pressark-btn--discard');

			keepBtn.addEventListener('click', function () {
				self.setPreviewFooterBusy(actionsDiv, true, 'keep');
				self.confirmPreview(sessionId, 'keep');
			});

			discardBtn.addEventListener('click', function () {
				self.setPreviewFooterBusy(actionsDiv, true, 'discard');
				self.confirmPreview(sessionId, 'discard');
			});
		},

		confirmPreview: function (sessionId, action) {
			var self = this;
			var data = window.pressarkData;
			var endpoint = action === 'keep' ? 'preview/keep' : 'preview/discard';

			fetch(data.restUrl + endpoint, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': data.nonce,
				},
				body: JSON.stringify({ session_id: sessionId }),
			})
				.then(function (response) { return response.json(); })
				.then(function (result) {
					if (result.success) {
						self.closePreview(action);
						var displayMsg = action === 'keep'
							? 'Changes applied successfully.'
							: 'Changes discarded.';
						// v3.1.0: Append verification summary if present.
						if (action === 'keep' && result.verification && result.verification.message) {
							displayMsg += '\n\n' + result.verification.message;
						}
						self.addMessage('ai', displayMsg);
						// Save as card summary so history renders the nice status card.
						var cardStatus = action === 'keep' ? 'applied' : 'cancelled';
						var historyMsg = '[PRESSARK_CARD:' + cardStatus + ']' + (result.post_title || 'Changes') + '||' + displayMsg;
						self.conversation.push({ role: 'assistant', content: historyMsg });

						if (result.checkpoint && typeof result.checkpoint === 'object') {
							self.checkpoint = result.checkpoint;
						}

						// v3.7.3: Auto-resume with enriched continuation context.
						// Uses [Continue] prefix so classify_task routes based on
						// the original user request, not this continuation marker.
						if (action === 'keep' && self.shouldAutoResume(result)) {
							setTimeout(function () {
								self.sendMessage(self.buildContinuationMessage(result, 'Changes applied successfully.'));
							}, 600);
						} else if (action === 'keep' && result.continuation && result.continuation.pause_message) {
							self.addMessage('ai', result.continuation.pause_message);
							self.conversation.push({ role: 'assistant', content: result.continuation.pause_message });
						}
					} else {
						self.setPreviewFooterBusy(self.activePreviewFooterEl, false);
						self.addMessage('error', result.message || 'Something went wrong.');
					}

					if (result.usage) {
						self.updateUsage(result.usage);
					}
				})
				.catch(function (err) {
					self.setPreviewFooterBusy(self.activePreviewFooterEl, false);
					self.addMessage('error', 'Failed to ' + action + ' preview: ' + err.message);
				});
		},

		closePreview: function (status) {
			// Remove the preview overlay from wp-admin.
			var overlay = document.getElementById('pressark-preview-overlay');
			if (overlay) {
				overlay.remove();
			}

			// Restore chat panel width.
			this.panel.classList.remove('pressark-preview-mode');

			if (status) {
				this.setPreviewFooterSettled(status);
			} else if (this.activePreviewFooterEl) {
				var btns = this.activePreviewFooterEl.querySelectorAll('button');
				for (var i = 0; i < btns.length; i++) {
					btns[i].disabled = true;
				}
			}
		},

		// ── Request Lifecycle ─────────────────────────────────────────

		beginRequest: function () {
			var controller = new AbortController();
			this._activeRequest = { controller: controller, reader: null };
			this.isSending = true;
			this.setSendButtonState('stop');
			return controller;
		},

		finishRequest: function () {
			this._activeRequest = null;
			this._activeRunId = null;
			this.isSending = false;
			this.setSendButtonState('send');
			this.removeTypingIndicator();
		},

		sendCancelSignal: function (cancelPayload) {
			var data = window.pressarkData || {};
			var cancelUrl = (data.restUrl || '') + 'cancel';
			if (!cancelUrl) return;

			var params = new URLSearchParams();
			if (cancelPayload && cancelPayload.run_id) {
				params.append('run_id', String(cancelPayload.run_id));
			}
			if (cancelPayload && cancelPayload.chat_id) {
				params.append('chat_id', String(cancelPayload.chat_id));
			}
			if (data.nonce) {
				params.append('_wpnonce', String(data.nonce));
			}

			var beaconSent = false;
			try {
				if (navigator.sendBeacon) {
					beaconSent = navigator.sendBeacon(cancelUrl, params);
				}
			} catch (e) {
				beaconSent = false;
			}

			if (beaconSent) return;

			fetch(cancelUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
				},
				body: params.toString(),
				credentials: 'same-origin',
				keepalive: true,
			}).catch(function () { /* fire-and-forget */ });
		},

		abortActiveRequest: function () {
			var cancelPayload = {};
			if (this._activeRunId) {
				cancelPayload.run_id = this._activeRunId;
			}
			if (this.currentChatId) {
				cancelPayload.chat_id = this.currentChatId;
			}

			// Notify the backend before aborting the stream. This is more
			// reliable in browsers/proxies where aborting a long-lived fetch
			// can starve or cancel follow-up requests on the same origin.
			this.sendCancelSignal(cancelPayload);

			if (this._activeRequest && this._activeRequest.controller) {
				this._activeRequest.controller.abort();
				return;
			}

			// UI/state safety net: if the stop affordance is still visible but
			// the active request handle has already been torn down locally,
			// still collapse the stop state after signalling the backend.
			this.finishRequest();
		},

		setSendButtonState: function (mode) {
			if (!this.sendBtn) return;
			if (mode === 'stop') {
				this.sendBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><rect x="6" y="6" width="12" height="12" rx="2"/></svg>';
				this.sendBtn.classList.add('pressark-send-btn--stop');
				this.sendBtn.setAttribute('aria-label', 'Stop');
				this.sendBtn.setAttribute('title', 'Stop');
			} else {
				this.sendBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>';
				this.sendBtn.classList.remove('pressark-send-btn--stop');
				this.sendBtn.setAttribute('aria-label', 'Send');
				this.sendBtn.setAttribute('title', 'Send');
			}
		},

		// ── Send Message ──────────────────────────────────────────────

		sendMessage: function (internalMessage) {
			if (this.isSending) return;

			// Defensive: ensure message is always a string (guards against minifier
			// variable-shadowing bugs where a Response object could be passed).
			var message = String(internalMessage || this.inputEl.value.trim() || '');
			if (!message) return;

			if (!window.pressarkData.hasApiKey) {
				this.addMessage('error', 'API key not configured. Go to PressArk settings to add your key.');
				return;
			}

			// v4.4.0: Prefer streaming when enabled.
			if (window.pressarkData.streamingEnabled && typeof ReadableStream !== 'undefined') {
				return this.sendMessageStream(message, internalMessage);
			}

			if (!internalMessage) {
				this.lastUserMessage = message;
				this.addMessage('user', message);
				this.inputEl.value = '';
				this.inputEl.style.height = 'auto';
			}

			this.conversation.push({ role: 'user', content: message });

			var typingText = 'PressArk is thinking';
			var lowerMsg = message.toLowerCase();
			if (lowerMsg.indexOf('seo') !== -1 || lowerMsg.indexOf('scan') !== -1) {
				typingText = 'Running analysis';
			}
			if (lowerMsg.indexOf('security') !== -1) {
				typingText = 'Running security scan';
			}
			if (lowerMsg.indexOf('store') !== -1 || lowerMsg.indexOf('woocommerce') !== -1 || lowerMsg.indexOf('products') !== -1) {
				typingText = 'Analyzing store';
			}
			if (internalMessage) {
				typingText = 'Continuing';
			}

			var controller = this.beginRequest();
			this.showTyping(typingText);

			var self = this;
			var data = window.pressarkData;
			var requestPostId = data.postId || 0;
			if (/^\[(?:Continue|Confirmed)\]/i.test(message)) {
				requestPostId = self.resolveContinuationPostId(message, requestPostId);
			}

			fetch(data.restUrl + 'chat', {
				method: 'POST',
				signal: controller.signal,
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': data.nonce,
				},
				body: JSON.stringify({
					message: message,
					conversation: self.conversation.slice(-25),
					chat_id: self.currentChatId || 0,
					screen: data.screenId,
					post_id: requestPostId,
					deep_mode: self.deepModeActive,
					loaded_groups: self.loadedGroups,
					checkpoint: self.checkpoint,
				}),
			})
				.then(function (response) {
					if (response.status === 429) {
						return response.json().then(function (result) {
							self.finishRequest();
							var planInfo = result.plan_info || (window.pressarkData || {}).plan_info || {};
							var settingsUrl = (window.pressarkData || {}).settings_url || '#';
							var creditStoreUrl = result.credit_store_url || (window.pressarkData || {}).creditStoreUrl || settingsUrl;

							if (planInfo.can_buy_credits && !planInfo.is_byok) {
								self.addMessage('ai', result.message + '\n\n[Open Credit Store](' + creditStoreUrl + ')\n[Upgrade your plan](' + (result.upgrade_url || '#') + ')');
								var creditPrompt = document.createElement('div');
								creditPrompt.className = 'pressark-message pressark-message-system';
								creditPrompt.innerHTML = self.renderCreditPackLinks();
								self.messagesEl.appendChild(creditPrompt);
								self.scrollToBottom();
							} else {
								self.addMessage('ai',
									result.message + '\n\n' +
									'[Upgrade your plan](' + (result.upgrade_url || '#') + ')\n' +
									'[Use your own API key](' + settingsUrl + ')'
								);
							}
							if (result.usage) {
								self.updateTokenDisplay(result.usage);
							}
							return;
						});
					}
					if (!response.ok) {
						return response.json()
							.catch(function () { return null; })
							.then(function (result) {
								throw new Error('Server error (' + response.status + ')');
							});
					}
					return response.json();
				})
				.then(function (result) {
					if (!result) return; // Already handled (429)
					self.finishRequest();

					try {
						if (result.is_error) {
							self.addMessage('error', result.reply || 'An error occurred.');
							var msgs = self.messagesEl.querySelectorAll('.pressark-message-error');
							var lastError = msgs[msgs.length - 1];
							if (lastError) {
								self.bindRetryButton(lastError.querySelector('.pressark-retry-btn'));
							}
							return;
						}

					// v2.8.0: Handle entitlement denied at REST level.
					if (result.error === 'entitlement_denied') {
						var upgradeUrl = (result.upgrade_url || window.pressarkData.upgradeUrl || '#');
						self.addMessage('ai', result.message + '\n\n[Upgrade your plan](' + upgradeUrl + ')');
						return;
					}

					if (result.success === false && !result.reply) {
						result.reply = 'Something went wrong. Please try again.';
					}

					var silentContinuation = self.isSilentContinuationResult(result);

					// Render activity strip (agentic loop steps).
					if (result.steps && result.steps.length > 0) {
						self.renderActivityStrip(result.steps);
					} else if (silentContinuation) {
						self.renderSilentContinuationStep(result);
					}

					if (self.continueCompactedRun(result)) {
						return;
					}

					// Branch on response type.
					var responseType = result.type || 'final_response';

					if (responseType === 'queued') {
						// Background task queued — show message and start the PressArk poller.
						self.pendingTaskCount = Math.max(self.pendingTaskCount, 1);
						self.syncTaskPolling(true);
						self.addMessage('ai', result.message || 'Working on that in the background...');
						self.conversation.push({ role: 'assistant', content: result.message || '' });
					} else if (responseType === 'preview') {
						// Live preview — show reply, then open preview overlay.
						if (result.reply) {
							self.addMessage('ai', result.reply);
							self.conversation.push({ role: 'assistant', content: result.reply });
						}
						self.openPreview({
							preview_session_id: result.preview_session_id,
							preview_url: result.preview_url,
							diff: result.diff,
						});
					} else if (responseType === 'confirm_card') {
						// Confirm card — show reply + old-style confirm cards.
						if (result.reply) {
							self.addMessage('ai', result.reply);
							self.conversation.push({ role: 'assistant', content: result.reply });
						}
						if (result.pending_actions && result.pending_actions.length > 0) {
							for (var p = 0; p < result.pending_actions.length; p++) {
								self.renderPreviewCard(result.pending_actions[p], result.run_id || '', p);
							}
						}
					} else {
						// final_response — standard text reply.
						var reply = result.reply || result.message || (result.is_error ? 'Something went wrong.' : '');
						if (reply && !silentContinuation) {
							self.addMessage('ai', reply);
							self.conversation.push({ role: 'assistant', content: reply });
						}

						if (result.pending_actions && result.pending_actions.length > 0) {
							for (var p2 = 0; p2 < result.pending_actions.length; p2++) {
								self.renderPreviewCard(result.pending_actions[p2], result.run_id || '', p2);
							}
						}

						if (result.actions_performed && result.actions_performed.length > 0) {
							for (var i = 0; i < result.actions_performed.length; i++) {
								self.addActionResult(result.actions_performed[i]);
							}
						}
					}

					self.applyResultState(result);

					if (result.suggestions) {
						self.renderSuggestionChips(result.suggestions);
					}

					self.autoSaveChat();
					} catch (processingErr) {
						processingErr._pressarkResult = result;
						throw processingErr;
					}
				})
				.catch(function (err) {
					self.finishRequest();

					if (err && err.name === 'AbortError') {
						return;
					}

					var errorMessage = '';
					var responseResult = err && err._pressarkResult ? err._pressarkResult : null;
					if (responseResult) {
						console.error('PressArk response handling failed', err, responseResult);
						if (responseResult.type === 'preview' && responseResult.preview_session_id) {
							errorMessage = 'PressArk finished the request, but the panel could not render the preview. Refresh the page or open Activity to continue.';
						} else {
							errorMessage = 'PressArk received a response, but the panel could not render it. Refresh the page and try again.';
						}
					} else {
						console.error('PressArk request failed', err);
						errorMessage = "Couldn't reach PressArk. ";
						var errMsg = err.message || '';
						if (errMsg.indexOf('401') !== -1 || errMsg.indexOf('403') !== -1) {
							errorMessage += 'Your session may have expired. Try refreshing the page.';
						} else if (errMsg.indexOf('429') !== -1) {
							errorMessage += 'Too many requests. Please wait a moment and try again.';
						} else if (errMsg.indexOf('500') !== -1) {
							errorMessage += 'Server error. Check your API key in PressArk settings.';
						} else {
							errorMessage += 'Check your connection and try again.';
						}
					}

					self.addMessage('error', errorMessage);
					var msgs = self.messagesEl.querySelectorAll('.pressark-message-error');
					var lastError = msgs[msgs.length - 1];
					if (lastError) {
						self.bindRetryButton(lastError.querySelector('.pressark-retry-btn'));
					}
				});
		},
		// ── SSE Streaming (v4.4.0) ──────────────────────────────────

		sendMessageStream: function (message, internalMessage) {
			if (this.isSending) return;

			if (!internalMessage) {
				this.lastUserMessage = message;
				this.addMessage('user', message);
				this.inputEl.value = '';
				this.inputEl.style.height = 'auto';
			}

			this.conversation.push({ role: 'user', content: message });

			// Show thinking indicator while waiting for first token.
			var typingText = 'PressArk is thinking';
			var lowerMsg = message.toLowerCase();
			if (lowerMsg.indexOf('seo') !== -1 || lowerMsg.indexOf('scan') !== -1) {
				typingText = 'Running analysis';
			}
			if (lowerMsg.indexOf('security') !== -1) {
				typingText = 'Running security scan';
			}
			if (lowerMsg.indexOf('store') !== -1 || lowerMsg.indexOf('woocommerce') !== -1 || lowerMsg.indexOf('products') !== -1) {
				typingText = 'Analyzing store';
			}
			if (internalMessage) {
				typingText = 'Continuing';
			}

			var controller = this.beginRequest();
			this.showTyping(typingText);

			var bubble = null; // Created lazily on first content event.
			var textBuffer = '';
			this._streamGotContent = false;
			this._streamSegmentText = ''; // Text for the current segment only.
			var self = this;
			var data = window.pressarkData;
			var requestPostId = data.postId || 0;
			if (/^\[(?:Continue|Confirmed)\]/i.test(message)) {
				requestPostId = self.resolveContinuationPostId(message, requestPostId);
			}

			// Capture message string before fetch().then() to avoid minifier
			// variable-shadowing bugs (the .then(function(response){}) parameter
			// can shadow outer closure variables after minification).
			var messageText = message;

			fetch(data.restUrl + 'chat-stream', {
				method: 'POST',
				signal: controller.signal,
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': data.nonce,
				},
				body: JSON.stringify({
					message: message,
					conversation: self.conversation.slice(-25),
					chat_id: self.currentChatId || 0,
					screen: data.screenId,
					post_id: requestPostId,
					deep_mode: self.deepModeActive,
					loaded_groups: self.loadedGroups,
					checkpoint: self.checkpoint,
				}),
			}).then(function (response) {
				if (!response.ok || !response.body) {
					// Fall back to non-streaming.
					self.finishRequest();
					self.conversation.pop(); // Remove the user message we added.
					if (bubble && bubble.parentNode) bubble.parentNode.removeChild(bubble);
					// Disable streaming for this session and retry.
					// Pass the original message text so the fallback can resend it
					// (inputEl was already cleared by sendMessageStream).
					window.pressarkData.streamingEnabled = false;
					self.sendMessage(messageText);
					return;
				}

				var reader = response.body.getReader();
				if (self._activeRequest) self._activeRequest.reader = reader;
				var decoder = new TextDecoder();
				var sseBuffer = '';

				function ensureBubble() {
					if (!bubble) {
						self.removeTypingIndicator();
						bubble = self.createStreamingBubble();
						self._streamGotContent = true;
					}
					return bubble;
				}

				function pump() {
					return reader.read().then(function (result) {
						if (result.done) {
							// Stream ended — finalize.
							ensureBubble();
							self.finalizeStream(bubble, textBuffer);
							return;
						}

						sseBuffer += decoder.decode(result.value, { stream: true });

						// Parse SSE events from buffer.
						var parsed = self.parseSSEBuffer(sseBuffer);
						sseBuffer = parsed.remaining;

						for (var i = 0; i < parsed.events.length; i++) {
							var evt = parsed.events[i];
							// Create the bubble on first meaningful content.
							if (evt.type === 'token' || evt.type === 'plan' || evt.type === 'step' || evt.type === 'tool_call' || evt.type === 'tool_result' || evt.type === 'done' || evt.type === 'error') {
								ensureBubble();
							}
							self.handleStreamEvent(evt, bubble, textBuffer);
							if (evt.type === 'token' && evt.data && evt.data.text) {
								textBuffer += evt.data.text;
							}
						}

						return pump();
					});
				}

				return pump();
			}).catch(function (err) {
				self.finishRequest();

				if (err && err.name === 'AbortError') {
					// User-initiated stop — handle partial content gracefully.
					if (bubble) {
						bubble.classList.remove('pressark-message-streaming');
						self._streamText = '';
						self._streamSegmentText = '';
						var contentEl = bubble.querySelector('.pressark-message-content');
						var hasContent = contentEl && contentEl.textContent.trim();
						if (hasContent && textBuffer) {
							// Keep partial response, clean up inline step rows.
							var inlineSteps = contentEl.querySelectorAll('.pressark-inline-step');
							for (var si = 0; si < inlineSteps.length; si++) {
								inlineSteps[si].remove();
							}
							var segs = contentEl.querySelectorAll('.pressark-stream-text-segment');
							for (var sj = 0; sj < segs.length; sj++) {
								while (segs[sj].firstChild) {
									contentEl.insertBefore(segs[sj].firstChild, segs[sj]);
								}
								segs[sj].remove();
							}
							self.conversation.push({ role: 'assistant', content: textBuffer });
							self.autoSaveChat();
						} else {
							// No content — remove empty bubble.
							if (bubble.parentNode) bubble.parentNode.removeChild(bubble);
						}
					}
					return;
				}

				console.error('PressArk stream failed', err);
				if (bubble && bubble.parentNode && !bubble.querySelector('.pressark-message-content').textContent.trim()) {
					bubble.parentNode.removeChild(bubble);
				}
				self.addMessage('error', "Couldn't reach PressArk. Check your connection and try again.");
			});
		},

		createStreamingBubble: function () {
			this.maybeAddTimestamp();

			var msgEl = document.createElement('div');
			msgEl.className = 'pressark-message pressark-message-assistant pressark-message-streaming';
			msgEl.dataset.timestamp = String(Date.now());

			var images = (window.pressarkData && window.pressarkData.images) || {};
			var logoSrc = this.findImage(images, ['WHITE-APP-LOGO', 'icon', 'app-icon']);
			msgEl.innerHTML =
				'<div class="pressark-ai-label">' +
				(logoSrc ? '<img src="' + logoSrc + '" alt="PressArk">' : '') +
				'<span>PressArk</span>' +
				'</div>' +
				'<div class="pressark-message-content"></div>';

			this.messagesEl.appendChild(msgEl);
			this.scrollToBottom();
			return msgEl;
		},

		parseSSEBuffer: function (buffer) {
			var events = [];
			var parts = buffer.split('\n\n');
			var remaining = parts.pop(); // Incomplete event stays in buffer.

			for (var i = 0; i < parts.length; i++) {
				var block = parts[i].trim();
				if (!block) continue;

				var eventType = '';
				var dataLines = [];
				var lines = block.split('\n');

				for (var j = 0; j < lines.length; j++) {
					var line = lines[j];
					if (line.indexOf('event: ') === 0) {
						eventType = line.substring(7).trim();
					} else if (line.indexOf('data: ') === 0) {
						dataLines.push(line.substring(6));
					} else if (line.indexOf(':') === 0) {
						// Comment line (keep-alive), ignore.
					}
				}

				if (dataLines.length > 0) {
					var rawData = dataLines.join('\n');
					var parsedData;
					try {
						parsedData = JSON.parse(rawData);
					} catch (e) {
						parsedData = { text: rawData };
					}
					events.push({ type: eventType || 'message', data: parsedData });
				}
			}

			return { events: events, remaining: remaining };
		},

		handleStreamEvent: function (event, bubble) {
			// run_started arrives before the first token/step, so it must be
			// handled even when no streaming bubble exists yet.
			switch (event.type) {
				case 'run_started':
					if (event.data) {
						if (event.data.run_id) {
							this._activeRunId = event.data.run_id;
						}
						if (event.data.chat_id && !this.currentChatId) {
							this.currentChatId = event.data.chat_id;
						}
					}
					return;

				case 'status':
					// Connected — no action needed.
					return;
			}

			if (!bubble) return;
			var contentEl = bubble.querySelector('.pressark-message-content');

			switch (event.type) {
				case 'token':
					if (event.data && event.data.text) {
						this._streamText = (this._streamText || '') + event.data.text;
						this._streamSegmentText = (this._streamSegmentText || '') + event.data.text;
						// Get or create the current text segment (after the last step).
						var textSeg = this._getOrCreateTextSegment(contentEl);
						textSeg.innerHTML = this.renderFormattedMessage(this._streamSegmentText);
						this.scrollToBottom();
					}
					break;

				case 'plan':
					this._renderPlanCard(contentEl, event.data);
					this._streamSegmentText = '';
					break;

				case 'step':
					this._renderInlineStep(contentEl, event.data);
					this._streamSegmentText = ''; // Next tokens go in a new segment.
					break;

				case 'tool_call':
					this._renderInlineStep(contentEl, {
						status: 'reading',
						label: event.data.name ? event.data.name.replace(/_/g, ' ') : 'Processing',
						tool: event.data.name || '',
					});
					this._streamSegmentText = '';
					break;

				case 'tool_result':
					this._renderInlineStep(contentEl, {
						status: 'done',
						label: (event.data.name || '').replace(/_/g, ' ') + ' complete',
						tool: event.data.name || '',
					});
					this._streamSegmentText = '';
					break;

				case 'done':
					this.finalizeStreamResponse(event.data, bubble);
					break;

				case 'error':
					this.finishRequest();
					var msg = (event.data && event.data.message) || 'An error occurred.';
					contentEl.innerHTML = '<span class="pressark-error-icon">' + pwIcons.warning + '</span> ' + this.escapeHtml(msg);
					bubble.classList.add('pressark-message-error');
					bubble.classList.remove('pressark-message-streaming');
					break;
			}
		},

		/**
		 * Get or create a text segment element within the content area.
		 * Text segments are separated by inline step rows.
		 * When text arrives after a step, we need a new segment so
		 * re-rendering the formatted markdown doesn't clobber the step rows.
		 */
		_getOrCreateTextSegment: function (contentEl) {
			// If there are no inline steps or plan cards yet, the contentEl itself is the segment.
			var steps = contentEl.querySelectorAll('.pressark-inline-step');
			var planCards = contentEl.querySelectorAll('.pressark-plan-card');
			if (!steps.length && !planCards.length) return contentEl;

			// Find the last child. If it's a text segment div, reuse it.
			var last = contentEl.lastElementChild;
			if (last && last.classList.contains('pressark-stream-text-segment')) {
				return last;
			}

			// The last child is a step row — we need a new text segment after it.
			var seg = document.createElement('div');
			seg.className = 'pressark-stream-text-segment';
			contentEl.appendChild(seg);
			return seg;
		},

		/**
		 * Render a collapsible execution plan card inline within the bubble.
		 * @since 5.2.0
		 */
		_renderPlanCard: function (contentEl, planItems) {
			if (!Array.isArray(planItems) || !planItems.length) return;

			var card = document.createElement('div');
			card.className = 'pressark-plan-card';

			var header = document.createElement('div');
			header.className = 'pressark-plan-header';
			header.textContent = 'Plan';
			header.addEventListener('click', function () {
				card.classList.toggle('collapsed');
			});
			card.appendChild(header);

			var list = document.createElement('ol');
			list.className = 'pressark-plan-steps';

			for (var i = 0; i < planItems.length; i++) {
				var li = document.createElement('li');
				li.className = 'step-' + (planItems[i].status || 'pending');
				li.textContent = planItems[i].text || '';
				list.appendChild(li);
			}

			card.appendChild(list);
			contentEl.appendChild(card);
			this.scrollToBottom();
		},

		/**
		 * Update plan card step statuses as execution progresses.
		 * @since 5.2.0
		 */
		_updatePlanStep: function (bubble, stepIndex, status) {
			if (!bubble) return;
			var items = bubble.querySelectorAll('.pressark-plan-steps li');
			if (stepIndex < items.length) {
				items[stepIndex].className = 'step-' + status;
			}
		},

		/**
		 * Render a tool/step row inline within the bubble's content area,
		 * interleaved with text in the order events arrive.
		 */
		_renderInlineStep: function (contentEl, stepData) {
			var rawStatus = stepData.status || 'done';
			var status = this.normalizeStepStatus(rawStatus);
			var label = stepData.label || stepData.tool || '';
			var tool = stepData.tool || '';

			// If updating an existing 'reading' row for this tool to 'done', just update it.
			if (rawStatus === 'done' && tool) {
				var rows = contentEl.querySelectorAll('.pressark-inline-step');
				for (var i = rows.length - 1; i >= 0; i--) {
					if (rows[i].dataset.tool === tool && rows[i].dataset.status === 'reading') {
						rows[i].className = 'pressark-inline-step pressark-step--done';
						rows[i].dataset.status = 'done';
						var iconEl = rows[i].querySelector('.pressark-step-icon');
						if (iconEl) {
							iconEl.className = 'pressark-step-icon pressark-step-icon--done';
							iconEl.innerHTML = pwIcons.check;
						}
						return;
					}
				}
			}

			// Before inserting a step, if there's accumulated text directly in
			// contentEl (no segments yet), wrap it in a segment so it stays above the step.
			var steps = contentEl.querySelectorAll('.pressark-inline-step');
			if (!steps.length && this._streamText) {
				var textWrap = document.createElement('div');
				textWrap.className = 'pressark-stream-text-segment';
				// Move all current children into the wrapper.
				while (contentEl.firstChild) {
					textWrap.appendChild(contentEl.firstChild);
				}
				contentEl.appendChild(textWrap);
			}

			// Build the step row.
			var row = document.createElement('div');
			row.className = 'pressark-inline-step pressark-step--' + status;
			row.dataset.tool = tool;
			row.dataset.status = status;

			var icon = '';
			if (status === 'reading') {
				icon = '<span class="pressark-step-icon pressark-step-icon--reading">' + pwIcons.loader + '</span>';
			} else if (status === 'done') {
				icon = '<span class="pressark-step-icon pressark-step-icon--done">' + pwIcons.check + '</span>';
			} else if (status === 'preparing_preview') {
				icon = '<span class="pressark-step-icon pressark-step-icon--preview">' + pwIcons.sparkles + '</span>';
			} else if (status === 'needs_confirm') {
				icon = '<span class="pressark-step-icon pressark-step-icon--confirm">' + pwIcons.pencil + '</span>';
			} else {
				icon = '<span class="pressark-step-icon">\u2022</span>';
			}

			row.innerHTML = icon + '<span class="pressark-step-label">' + this.escapeHtml(label) + '</span>';
			contentEl.appendChild(row);
			this.scrollToBottom();
		},

		finalizeStreamResponse: function (result, bubble) {
			this.finishRequest();
			this._streamText = '';
			this._streamSegmentText = '';

			// Remove streaming class.
			bubble.classList.remove('pressark-message-streaming');
			var silentContinuation = this.isSilentContinuationResult(result);

			if (this.shouldAutoContinueCompactedRun(result)) {
				if (result.steps && result.steps.length > 0) {
					this.renderActivityStripBefore(result.steps, bubble);
				} else if (silentContinuation) {
					this.renderSilentContinuationStep(result, bubble);
				}
				if (bubble.parentNode) {
					bubble.parentNode.removeChild(bubble);
				}
				this.continueCompactedRun(result);
				return;
			}

			var responseType = result.type || 'final_response';
			var replyText = result.reply || result.message || '';
			var contentEl = bubble.querySelector('.pressark-message-content');

			// Rebuild the bubble content: final activity strip, then formatted text.
			if (silentContinuation) {
				if (result.steps && result.steps.length > 0) {
					this.renderActivityStripBefore(result.steps, bubble);
				} else {
					this.renderSilentContinuationStep(result, bubble);
				}
				if (bubble.parentNode) {
					bubble.parentNode.removeChild(bubble);
				}
			} else if (replyText && contentEl) {
				// Collect inline steps that were streamed, to rebuild as a proper strip.
				var inlineSteps = contentEl.querySelectorAll('.pressark-inline-step');
				var hadInlineSteps = inlineSteps.length > 0;

				// Detach plan cards before clearing — they survive the finalize.
				var planCards = contentEl.querySelectorAll('.pressark-plan-card');
				var savedPlanCards = [];
				for (var pc = 0; pc < planCards.length; pc++) {
					savedPlanCards.push(planCards[pc].parentNode.removeChild(planCards[pc]));
				}

				// Clear inline content and render final formatted text.
				contentEl.innerHTML = this.renderFormattedMessage(replyText);

				// Re-insert plan cards at the top.
				for (var pc2 = savedPlanCards.length - 1; pc2 >= 0; pc2--) {
					contentEl.insertBefore(savedPlanCards[pc2], contentEl.firstChild);
				}

				// If there were inline steps, render the final activity strip above the bubble.
				if (result.steps && result.steps.length > 0) {
					this.renderActivityStripBefore(result.steps, bubble);
				} else if (hadInlineSteps) {
					// No final steps from server — remove inline steps (they're already cleared).
				}

				this.conversation.push({ role: 'assistant', content: replyText });
			} else if (result.steps && result.steps.length > 0) {
				// No reply text but has steps.
				this.renderActivityStripBefore(result.steps, bubble);
			}

			// Branch on response type.
			if (responseType === 'queued') {
				this.pendingTaskCount = Math.max(this.pendingTaskCount, 1);
				this.syncTaskPolling(true);
			} else if (responseType === 'preview') {
				this.openPreview({
					preview_session_id: result.preview_session_id,
					preview_url: result.preview_url,
					diff: result.diff,
				});
			} else if (responseType === 'confirm_card') {
				if (result.pending_actions && result.pending_actions.length > 0) {
					for (var p = 0; p < result.pending_actions.length; p++) {
						this.renderPreviewCard(result.pending_actions[p], result.run_id || '', p);
					}
				}
			} else {
				// final_response
				if (result.pending_actions && result.pending_actions.length > 0) {
					for (var p2 = 0; p2 < result.pending_actions.length; p2++) {
						this.renderPreviewCard(result.pending_actions[p2], result.run_id || '', p2);
					}
				}
			}

			this.applyResultState(result);

			if (result.suggestions) {
				this.renderSuggestionChips(result.suggestions);
			}

			this.autoSaveChat();
		},

		finalizeStream: function (bubble, textBuffer) {
			// Called when the ReadableStream ends without a 'done' event.
			// This can happen if the connection drops.
			if (this.isSending) {
				this.finishRequest();
				this._streamText = '';
				this._streamSegmentText = '';
				bubble.classList.remove('pressark-message-streaming');

				// Clean up any inline step rows from the bubble.
				var contentEl = bubble.querySelector('.pressark-message-content');
				if (contentEl) {
					var inlineSteps = contentEl.querySelectorAll('.pressark-inline-step');
					for (var i = 0; i < inlineSteps.length; i++) {
						inlineSteps[i].remove();
					}
					// Unwrap text segments.
					var segs = contentEl.querySelectorAll('.pressark-stream-text-segment');
					for (var j = 0; j < segs.length; j++) {
						while (segs[j].firstChild) {
							contentEl.insertBefore(segs[j].firstChild, segs[j]);
						}
						segs[j].remove();
					}
				}

				if (textBuffer) {
					// Re-render the final text cleanly.
					if (contentEl) {
						contentEl.innerHTML = this.renderFormattedMessage(textBuffer);
					}
					this.conversation.push({ role: 'assistant', content: textBuffer });
					this.autoSaveChat();
				}
			}
		},

		// ── Notification Dot for Background Tasks ───────────────────

		updateActivityUi: function () {
			var count = this.unreadTaskCount || 0;
			var toggle = this.toggleBtn;
			var dot = toggle ? toggle.querySelector('.pressark-notif-dot') : null;

			if (this.activityBtn) {
				var title = count > 0
					? 'Open Activity inbox (' + count + ' unread)'
					: 'Open Activity inbox';
				this.activityBtn.setAttribute('title', title);
				this.activityBtn.setAttribute('aria-label', title);
			}

			if (this.activityCountEl) {
				this.activityCountEl.hidden = count < 1;
				this.activityCountEl.textContent = count > 99 ? '99+' : String(count);
			}

			if (!toggle) {
				return;
			}

			if (count < 1) {
				if (dot) {
					dot.remove();
				}
				return;
			}

			if (!dot) {
				dot = document.createElement('span');
				dot.className = 'pressark-notif-dot';
				toggle.appendChild(dot);
			}

			dot.textContent = count > 99 ? '99+' : String(count);
		},

		// ── Customizer Handoff ───────────────────────────────────────

		isCustomizerEligible: function (diff) {
			var customizerFields = ['blogname', 'blogdescription', 'header_image',
				'background_color', 'site_icon'];
			if (!diff || !diff.length) return false;
			for (var i = 0; i < diff.length; i++) {
				var entry = diff[i];
				if (entry.items) {
					for (var j = 0; j < entry.items.length; j++) {
						if (customizerFields.indexOf(entry.items[j].field) !== -1) return true;
					}
				}
				if (entry.field && customizerFields.indexOf(entry.field) !== -1) return true;
			}
			return false;
		},
	};

	// Background task polling is owned by PressArk itself.
	// The poll loop only runs while the panel is open or async work is pending.

	// Initialize when DOM is ready.
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () {
			PressArk.init();
		});
	} else {
		PressArk.init();
	}

	window.PressArk = PressArk;
})();
