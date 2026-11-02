/* PicSqueeze admin behaviour: bulk runner + per-image actions. */
(function () {
  'use strict';

  function post(action, data) {
    var body = new FormData();
    body.append('action', action);
    body.append('nonce', picsqueezeAdmin.nonce);
    Object.keys(data || {}).forEach(function (key) {
      body.append(key, data[key]);
    });

    return fetch(picsqueezeAdmin.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: body
    }).then(function (response) {
      return response.json();
    });
  }

  /* --- Bulk runner --- */

  var startBtn = document.getElementById('picsqueeze-bulk-start');
  var wrap = document.getElementById('picsqueeze-bulk-progress');
  var bar = document.getElementById('picsqueeze-bulk-bar');
  var status = document.getElementById('picsqueeze-bulk-status');

  function setProgress(percent, text) {
    if (wrap) { wrap.style.display = 'block'; }
    if (bar) { bar.style.width = percent + '%'; }
    if (status) { status.textContent = text; }
  }

  function step() {
    post('picsqueeze_bulk_step', {}).then(function (res) {
      if (!res || !res.success) {
        setProgress(0, picsqueezeAdmin.i18n.failed);
        if (startBtn) { startBtn.disabled = false; }
        return;
      }
      var d = res.data;
      setProgress(
        d.percent,
        picsqueezeAdmin.i18n.running + ' ' + d.processed + ' / ' + d.total
      );
      if (d.finished) {
        status.textContent = picsqueezeAdmin.i18n.done;
        if (startBtn) { startBtn.disabled = false; }
      } else {
        step();
      }
    }).catch(function () {
      setProgress(0, picsqueezeAdmin.i18n.failed);
      if (startBtn) { startBtn.disabled = false; }
    });
  }

  if (startBtn) {
    startBtn.addEventListener('click', function () {
      startBtn.disabled = true;
      setProgress(0, picsqueezeAdmin.i18n.running);
      post('picsqueeze_bulk_start', {}).then(function (res) {
        if (!res || !res.success) {
          setProgress(0, picsqueezeAdmin.i18n.failed);
          startBtn.disabled = false;
          return;
        }
        if (res.data.empty) {
          setProgress(100, picsqueezeAdmin.i18n.done);
          startBtn.disabled = false;
          return;
        }
        step();
      }).catch(function () {
        setProgress(0, picsqueezeAdmin.i18n.failed);
        startBtn.disabled = false;
      });
    });
  }

  /* --- Per-image actions in the media list --- */

  document.addEventListener('click', function (event) {
    var btn = event.target.closest('.picsqueeze-action');
    if (!btn) { return; }
    event.preventDefault();
    btn.disabled = true;

    var task = btn.getAttribute('data-task');
    var action = 'optimize' === task ? 'picsqueeze_optimize_one' : 'picsqueeze_restore_one';

    post(action, { id: btn.getAttribute('data-id') }).then(function (res) {
      if (res && res.success) {
        window.location.reload();
        return;
      }
      btn.disabled = false;
      window.alert(res && res.data ? res.data.message : picsqueezeAdmin.i18n.error);
    }).catch(function () {
      btn.disabled = false;
      window.alert(picsqueezeAdmin.i18n.error);
    });
  });
})();
