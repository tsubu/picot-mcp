(function () {
	function fallbackCopy(text) {
		try {
			var ta = document.createElement('textarea');
			ta.value = text;
			ta.setAttribute('readonly', '');
			ta.style.position = 'fixed';
			ta.style.left = '-9999px';
			document.body.appendChild(ta);
			ta.select();
			var ok = document.execCommand('copy');
			document.body.removeChild(ta);
			return ok;
		} catch (e) {
			return false;
		}
	}

	function copyText(text) {
		if (!text) return Promise.resolve(false);
		if (navigator.clipboard && navigator.clipboard.writeText) {
			return navigator.clipboard.writeText(text).then(
				function () {
					return true;
				},
				function () {
					return fallbackCopy(text);
				}
			);
		}
		return Promise.resolve(fallbackCopy(text));
	}

	function textFromTarget(el) {
		if (!el) return '';
		if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') {
			return el.value || '';
		}
		return el.textContent || '';
	}

	function buildConnectionBundle(key, label) {
		var cfg = window.picotMcpAdmin || {};
		var site = cfg.siteName || '';
		var url = cfg.mcpUrl || '';
		var line1 = 'Wordpress <' + site + '>';
		if (label) {
			line1 += ' ' + label;
		}
		return line1 + '\nMCP-URL ' + url + '\nMCP-KEY ' + key;
	}

	function markCopied(btn) {
		var label = btn.getAttribute('data-label') || btn.textContent || 'Copy';
		btn.textContent = btn.getAttribute('data-copied-label') || 'Copied';
		setTimeout(function () {
			btn.textContent = label;
		}, 1500);
	}

	function closeAllModals() {
		document.querySelectorAll('.picot-mcp-modal:not([hidden])').forEach(function (modal) {
			modal.hidden = true;
		});
		document.body.classList.remove('picot-mcp-modal-open');
	}

	function openModal(id) {
		var modal = document.getElementById(id);
		if (!modal) return;
		closeAllModals();
		modal.hidden = false;
		document.body.classList.add('picot-mcp-modal-open');
		var focusEl = modal.querySelector('input, select, button');
		if (focusEl) focusEl.focus();
	}

	document.addEventListener('click', function (event) {
		var copyBtn = event.target.closest('.picot-mcp-copy');
		if (copyBtn) {
			event.preventDefault();
			if (copyBtn.disabled) return;

			var tokenId = copyBtn.getAttribute('data-copy-token-id');
			if (tokenId) {
				var msg =
					(window.picotMcpAdmin && window.picotMcpAdmin.i18n && window.picotMcpAdmin.i18n.keyNotRevealed) ||
					'API keys cannot be shown again after issue.';
				alert(msg);
				return;
			}

			var asBundle = copyBtn.getAttribute('data-copy-bundle') === '1';
			var keyLabel = copyBtn.getAttribute('data-key-label') || '';

			if (asBundle) {
				var bundleKey = '';
				var targetId = copyBtn.getAttribute('data-copy-target');
				if (targetId) {
					bundleKey = textFromTarget(document.getElementById(targetId));
				}
				copyText(buildConnectionBundle(bundleKey, keyLabel)).then(function (ok) {
					if (ok) markCopied(copyBtn);
				});
				return;
			}

			var text = copyBtn.getAttribute('data-copy-text');
			if (!text) {
				var id = copyBtn.getAttribute('data-copy-target');
				text = textFromTarget(document.getElementById(id));
			}
			copyText(text).then(function (ok) {
				if (ok) markCopied(copyBtn);
			});
			return;
		}

		var openBtn = event.target.closest('.picot-mcp-open-modal');
		if (openBtn) {
			event.preventDefault();
			openModal(openBtn.getAttribute('data-modal'));
			return;
		}

		if (event.target.closest('[data-close-modal]')) {
			event.preventDefault();
			closeAllModals();
		}
	});

	document.addEventListener('keydown', function (event) {
		if (event.key === 'Escape') {
			closeAllModals();
		}
	});

	document.addEventListener('DOMContentLoaded', function () {
		var form = document.querySelector('.wrap form');
		if (!form) return;

		var hidden = form.querySelector('input[name="picot_mcp_token_id"]');
		if (!hidden) {
			hidden = document.createElement('input');
			hidden.type = 'hidden';
			hidden.name = 'picot_mcp_token_id';
			hidden.value = '';
			form.appendChild(hidden);
		}

		form.addEventListener('click', function (event) {
			var btn = event.target.closest('.picot-mcp-revoke, .picot-mcp-update-key');
			if (!btn) return;
			hidden.value = btn.getAttribute('data-token-id') || '';
		});
	});
})();
