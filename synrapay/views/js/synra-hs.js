/* ─────────────────────────────────────────────────────────────────────────────
   SynraPay hosted session on the PrestaShop 8 payment step — the WooCommerce
   synra-hs-core.js flow:

     page   → module/synrapay/session   shop creates the Direct order + hosted session → clientToken
     page   → sdk.js                    SynraPayFields.create: the card is typed into SynraPay's frame
     "Order with an obligation to pay"  PrestaShop submits our payment form → intercepted here
            → fields.submit()           3-D Secure in an overlay; redirect:false
     page   → module/synrapay/confirm   the shop reads the ORDER's status, creates the order → order-confirmation

   Nothing the frame says about the outcome is trusted; only the status read
   settles the order. Config comes from Media::addJsDef → window.synrapayConfig.
   ───────────────────────────────────────────────────────────────────────────── */
(function (w, d) {
  'use strict';
  var cfg = w.synrapayConfig || {};
  var i18n = cfg.i18n || {};
  var state = { fields: null, session: null, complete: false, busy: false, resultWaiter: null, sdkPromise: null, started: false, failedOnce: false };

  function $(id) { return d.getElementById(id); }
  function mountEl() { return $('synra-pay-mount'); }
  function setStatus(msg, kind) { var el = $('synra-pay-status'); if (!el) return; el.textContent = msg || ''; el.className = 'synra-pay-status' + (kind ? ' is-' + kind : ''); }
  function setError(msg) { skeleton(false); setStatus(msg, 'error'); }
  function skeleton(on) { var s = $('synra-pay-skeleton'), h = $('synra-pay-fields'); if (s) s.style.display = on ? '' : 'none'; if (h) h.style.display = on ? 'none' : ''; }
  function setBusy(on, msg) { state.busy = on; var o = $('synra-pay-overlay'); if (o) { o.className = 'synra-pay-overlay' + (on ? ' on' : ''); $('synra-pay-overlay-text').textContent = msg || ''; } }
  function expired() { if (!state.session || !state.session.expiresAt) return false; var t = Date.parse(state.session.expiresAt); return isFinite(t) && t - Date.now() < 15000; }
  function form() { return d.querySelector('form[data-synra-form]'); }
  function selected() { var f = form(); if (!f) return false; var wrap = f.closest('[id$="-form"]'); if (!wrap) return true; var id = wrap.id.replace(/-form$/, ''); var radio = d.getElementById(id); return !radio || radio.checked; }

  function loadSdk(url) {
    if (w.SynraPayFields) return Promise.resolve(w.SynraPayFields);
    if (state.sdkPromise) return state.sdkPromise;
    state.sdkPromise = new Promise(function (resolve, reject) {
      var s = d.createElement('script'); s.src = url || cfg.sdkUrl; s.async = true;
      s.onload = function () { w.SynraPayFields ? resolve(w.SynraPayFields) : reject(new Error(i18n.sdkFailed)); };
      s.onerror = function () { state.sdkPromise = null; reject(new Error(i18n.sdkFailed)); };
      d.head.appendChild(s);
    });
    return state.sdkPromise;
  }

  function post(url, data) {
    var body = new FormData();
    body.append('ajax', '1');
    Object.keys(data || {}).forEach(function (k) { if (data[k] != null) body.append(k, String(data[k])); });
    return fetch(url, { method: 'POST', credentials: 'same-origin', body: body, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json().catch(function () { return { ok: false, status: 'error', error: 'The store did not answer (HTTP ' + r.status + ').' }; }); });
  }

  /* Open (or re-open) the session for this cart and mount SynraPay's fields. */
  function sync(opts) {
    opts = opts || {};
    if (!mountEl()) return Promise.resolve();
    setStatus('', ''); skeleton(true); state.complete = false;
    return post(cfg.sessionUrl, { fresh: opts.fresh ? '1' : '' }).then(function (r) {
      if (!r || !r.ok || !r.clientToken) {
        if (r && r.redirect) { w.location.assign(r.redirect); return; }
        throw new Error((r && r.error) || 'Could not start the card session.');
      }
      state.session = r;
      return loadSdk(r.sdkUrl).then(function (SDK) {
        var host = $('synra-pay-fields');
        if (state.fields) { try { state.fields.destroy(); } catch (e) {} }
        host.textContent = '';
        var f = SDK.create({
          clientToken: r.clientToken,
          container: host,
          name: 'optional',
          theme: 'auto',
          challenge: 'overlay',   // 3-D Secure in a box over the checkout; the page never leaves
          redirect: false,        // the shop confirms the ORDER; the frame's result is only shown
          style: { fontFamily: 'inherit', borderRadius: '8px' }
        });
        state.fields = f;
        f.on('ready', function () { skeleton(false); setStatus('', ''); });
        f.on('change', function (s) { state.complete = !!(s && s.complete); });
        f.on('enter', function () { if (selected()) pay(); });
        f.on('result', function (res) { if (state.resultWaiter) { var fn = state.resultWaiter; state.resultWaiter = null; fn(res || {}); } });
        f.on('error', function (e) {
          if (e && e.code === 'HOSTED_FIELDS_SESSION_EXPIRED') { setStatus(i18n.expired, 'muted'); sync({ fresh: false }); return; }
          setError((e && e.message) || 'The card fields reported a problem.' + (e && e.reference ? ' Reference: ' + e.reference : ''));
        });
      });
    }).catch(function (e) { setError(e.message); });
  }

  function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
  function confirm(maxTries, frameOk) {
    var tries = maxTries || 60;
    function once() {
      return post(cfg.confirmUrl, { frame_ok: frameOk ? '1' : '' }).then(function (r) {
        if (r && r.status === 'paid' && r.redirect) return { paid: true, redirect: r.redirect };
        if (r && (r.status === 'failed' || r.status === 'error')) return { paid: false, failed: true, message: r.error, redirect: r.redirect, refresh: !!r.refresh };
        if (--tries <= 0) return { paid: false, failed: false, message: i18n.declined };
        return sleep(1500).then(once);
      });
    }
    return once();
  }

  function pay() {
    if (state.busy) return;
    if (!state.fields || expired()) { setError(i18n.notReady); sync({ fresh: false }); return; }
    if (!state.complete) { setError(i18n.incomplete); return; }
    setBusy(true, i18n.processing); setStatus(i18n.processing, 'muted');

    var f = state.fields;
    var viaEvent = new Promise(function (resolve) { state.resultWaiter = resolve; });
    var viaSubmit = f.submit(); viaSubmit.catch(function () {});
    Promise.race([viaSubmit, viaEvent]).then(function (r) {
      state.resultWaiter = null; r = r || {};
      if (r.ok || r.retry === 'check_status' || r.navigating) { setStatus(i18n.confirming, 'muted'); setBusy(true, i18n.confirming); return confirm(0, !!r.ok); }
      // retry: new_order (the order is closed) — one quick read settles it; anything else is a decline on an order that stays open.
      if (r.retry === 'new_order') return confirm(2).then(function (c) { return c.paid ? c : { paid: false, failed: true, message: r.error_message || c.message }; });
      return { paid: false, failed: true, message: r.error_message || i18n.declined };
    }).then(function (out) {
      if (out.paid) { setStatus(i18n.confirming, 'muted'); w.location.assign(out.redirect); return; }
      if (out.redirect) { w.location.assign(out.redirect); return; }
      setBusy(false);
      setError(out.message || i18n.declined);
      // The frame shows its own "declined" screen; open a clean session (a new Direct order) for the next try.
      sync({ fresh: true });
    }).catch(function (e) { setBusy(false); setError((e && e.message) || 'The payment could not be sent.'); });
  }

  /* PrestaShop submits our (hidden) payment form when the customer confirms: take over. */
  function hookForm() {
    var f = form();
    if (!f || f.getAttribute('data-synra-hooked')) return;
    f.setAttribute('data-synra-hooked', '1');
    f.addEventListener('submit', function (e) { e.preventDefault(); e.stopImmediatePropagation(); pay(); return false; }, true);
    if (w.jQuery) { w.jQuery(f).on('submit', function (e) { e.preventDefault(); e.stopImmediatePropagation(); pay(); return false; }); }
  }

  function start() {
    if (state.started || !mountEl()) return;
    state.started = true;
    hookForm();
    sync();
  }

  if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', start); else start();
  // The payment step can be (re)rendered after load: watch for our mount to appear.
  if (w.MutationObserver) {
    new MutationObserver(function () { if (!state.started) start(); else hookForm(); }).observe(d.documentElement, { childList: true, subtree: true });
  }
})(window, document);
