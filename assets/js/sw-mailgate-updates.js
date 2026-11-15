/* Backend-only deferred release checks. Installation remains in WordPress core. */
(function () {
  'use strict';
  const config = window.swmgVerifiedUpdates;
  if (!config) return;
  const messages = config.messages || {};
  let pending = null;
  function check(manual) {
    if (pending) return pending;
    const button = document.getElementById('swmg-verified-updates-check');
    const result = document.getElementById('swmg-verified-updates-result');
    if (manual && result) { result.hidden = false; result.textContent = messages.checking || ''; }
    if (button) button.disabled = true;
    const controller = new AbortController();
    const timer = window.setTimeout(() => controller.abort(), 90000);
    pending = fetch(config.url, {
      signal: controller.signal,
      method: 'POST',
      credentials: 'same-origin',
      body: new URLSearchParams({ action: config.action, nonce: config.nonce, manual: manual ? '1' : '0' }),
    })
      .then((response) => { if (!response.ok) throw new Error('failed'); return response.json(); })
      .then((response) => { if (manual && result) result.textContent = (response.data && response.data.message) || messages.done || ''; })
      .catch(() => { if (manual && result) result.textContent = messages.failed || ''; })
      .finally(() => { window.clearTimeout(timer); pending = null; if (button) button.disabled = false; });
    return pending;
  }
  const trigger = document.getElementById('swmg-verified-updates-check');
  if (trigger) trigger.addEventListener('click', () => check(true));
  function deferredCheck() {
    if ('requestIdleCallback' in window) window.requestIdleCallback(() => check(false), { timeout: 5000 });
    else window.setTimeout(() => check(false), 1500);
  }
  if (config.due) {
    if (document.readyState === 'complete') deferredCheck();
    else window.addEventListener('load', deferredCheck, { once: true });
  }
})();
