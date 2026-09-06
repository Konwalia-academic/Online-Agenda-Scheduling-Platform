/* Agenda Platform front-end helpers */
(function () {
  'use strict';

  // Never let an AJAX call hang forever on "Loading…".
  var FETCH_TIMEOUT = 30000;

  function fetchWithTimeout(url, opts) {
    var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
    var timer = null;
    if (controller) {
      opts = opts || {};
      opts.signal = controller.signal;
      timer = setTimeout(function () { controller.abort(); }, FETCH_TIMEOUT);
    }
    return fetch(url, opts).then(function (res) {
      if (timer) clearTimeout(timer);
      return res;
    }, function (err) {
      if (timer) clearTimeout(timer);
      if (err && err.name === 'AbortError') {
        throw new Error('Request timed out');
      }
      throw err;
    });
  }

  // Parse a JSON response, throwing a readable error if the body is not valid
  // JSON (e.g. a PHP warning/fatal leaked into the response).
  function parseJson(res) {
    if (!res.ok && res.status === 403) {
      throw new Error('Access denied (403). Please reload the page.');
    }
    return res.text().then(function (text) {
      try {
        return JSON.parse(text);
      } catch (e) {
        throw new Error('Invalid server response (not JSON). A PHP error/warning is likely shown. HTTP ' + res.status + ': ' + text.slice(0, 200));
      }
    });
  }

  function getJson(url) {
    return fetchWithTimeout(url, {
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin'
    }).then(parseJson);
  }

  function postJson(url, data) {
    var body = new URLSearchParams();
    Object.keys(data || {}).forEach(function (k) { body.append(k, data[k]); });
    return fetchWithTimeout(url, {
      method: 'POST',
      headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      credentials: 'same-origin',
      body: body.toString()
    }).then(parseJson);
  }

  function qs(sel, root) { return (root || document).querySelector(sel); }
  function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  function escapeHtml(str) {
    var d = document.createElement('div');
    d.textContent = str == null ? '' : String(str);
    return d.innerHTML;
  }

  window.Agenda = { getJson: getJson, postJson: postJson, qs: qs, qsa: qsa, escapeHtml: escapeHtml };
})();
