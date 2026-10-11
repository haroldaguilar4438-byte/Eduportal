/* EduLive — centralized live-update manager (adaptive polling transport).
 *
 * One timer per page, shared by every widget. Adaptive behavior:
 *   visible tab   -> base interval (default 15s)
 *   hidden tab    -> slow interval (default 120s), instant catch-up on visible
 *   offline/error -> exponential backoff, silent resume on reconnect
 *   multiple tabs -> BroadcastChannel sync (no duplicate server work per tab
 *                    beyond their own lightweight polls; notifications are
 *                    created server-side exactly once, tabs only read)
 *
 * Pages opt in:
 *   EduLive.onNotification(fn)        — new notification objects {id,title,type,message,link,created_at}
 *   EduLive.onDigest(fn)              — {unread, counts:{...}} role counters
 *   EduLive.refreshOnEvent({match})   — "N new updates — Refresh" pill
 *   data-live-count="key"             — element text auto-synced from digest
 *
 * No page reloads are ever forced; refresh pills are always user-initiated
 * so search/filter/pagination state is never destroyed.
 */
(function () {
  'use strict';
  if (window.EduLive) return;

  var cfg = window.EduLiveConfig || {};
  var feedUrl = cfg.feedUrl || '';
  var digestUrl = cfg.digestUrl || '';
  var BASE = Math.max(5000, cfg.baseInterval || 15000);
  var HIDDEN = Math.max(BASE, cfg.hiddenInterval || 120000);
  var MAX_BACKOFF = cfg.maxBackoff || 300000;

  var state = {
    lastId: 0, seen: {}, subsN: [], subsD: [], subsU: [],
    timer: null, delay: BASE, failures: 0, running: false,
    bc: null, statusEls: []
  };

  function debug() {
    try {
      if (window.localStorage && localStorage.getItem('edulive_debug') === '1') {
        // eslint-disable-next-line no-console
        console.log.apply(console, ['[EduLive]'].concat([].slice.call(arguments)));
      }
    } catch (e) {}
  }

  // Cross-tab coordination: a tab that just synced nudges the others.
  try {
    if ('BroadcastChannel' in window) {
      state.bc = new BroadcastChannel('edulive-sync');
      state.bc.onmessage = function (ev) {
        var d = ev && ev.data;
        if (d && d.t === 'sync' && d.uid === cfg.userId) {
          debug('cross-tab nudge');
          schedule(1500);
        }
      };
    }
  } catch (e) { state.bc = null; }

  function nudgeTabs() {
    try {
      if (state.bc) state.bc.postMessage({ t: 'sync', uid: cfg.userId, at: Date.now() });
    } catch (e) {}
    try {
      if (window.localStorage) localStorage.setItem('edulive-state', JSON.stringify({ uid: cfg.userId, lastId: state.lastId, at: Date.now() }));
    } catch (e) {}
  }

  function setStatus(mode) {
    // mode: live | connecting | reconnecting | offline
    state.statusEls.forEach(function (el) {
      if (!el || !el.isConnected) return;
      var dot = el.querySelector('[data-live-dot]');
      var txt = el.querySelector('[data-live-text]');
      var map = {
        live: ['LIVE', '#10b981'], connecting: ['Connecting…', '#f59e0b'],
        reconnecting: ['Reconnecting…', '#f59e0b'], offline: ['Offline', '#ef4444']
      };
      var m = map[mode] || map.live;
      if (txt) txt.textContent = m[0];
      if (dot) dot.style.background = m[1];
      el.setAttribute('data-live-state', mode);
      el.title = mode === 'live' ? 'Live updates active' : 'Live updates: ' + m[0] + ' — page still works, will catch up automatically';
    });
  }

  function fetchJson(u) {
    return fetch(u, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { if (!r.ok) throw new Error('http ' + r.status); return r.json(); });
  }

  function emitUnread(n) {
    state.subsU.forEach(function (fn) { try { fn(n); } catch (e) {} });
  }

  function emitDigest(d) {
    // data-live-count bindings (dashboard cards, queue badges).
    try {
      document.querySelectorAll('[data-live-count]').forEach(function (el) {
        var k = el.getAttribute('data-live-count');
        if (k && d.counts && Object.prototype.hasOwnProperty.call(d.counts, k)) {
          var raw = String(d.counts[k]);
          // Preserve thousands formatting when the server-rendered value has it.
          var v = /,/.test(el.textContent || '') ? Number(d.counts[k]).toLocaleString('en-US') : raw;
          if (el.textContent !== v) el.textContent = v;
        }
      });
    } catch (e) {}
    state.subsD.forEach(function (fn) { try { fn(d); } catch (e) {} });
  }

  function emitNotifications(items) {
    var fresh = [];
    items.forEach(function (n) {
      if (!n || !n.id || state.seen[n.id]) return;
      state.seen[n.id] = 1;
      // First sync primes the pump: server-rendered rows already show these,
      // so mark seen without emitting (prevents duplicate rows/phantom pills).
      if (!state.primed) return;
      fresh.push(n);
    });
    if (!fresh.length) return;
    fresh.sort(function (a, b) { return a.id - b.id; });
    state.subsN.forEach(function (fn) { try { fn(fresh); } catch (e) {} });
  }

  function tick() {
    state.timer = null;
    if (!feedUrl && !digestUrl) return;
    if (!navigator.onLine) {
      setStatus('offline');
      schedule(HIDDEN);
      return;
    }
    var hidden = document.hidden === true;
    var jobs = [];
    if (feedUrl) {
      jobs.push(fetchJson(feedUrl + (feedUrl.indexOf('?') >= 0 ? '&' : '?') + 'since_id=' + state.lastId)
        .then(function (d) {
          if (!d) return;
          var hadItems = !!(d.items && d.items.length);
          if (d.max_id && d.max_id > state.lastId) state.lastId = d.max_id;
          if (typeof d.unread !== 'undefined') emitUnread(parseInt(d.unread, 10) || 0);
          if (hadItems) { emitNotifications(d.items); nudgeTabs(); }
          state.primed = true;
        }));
    }
    // Digest every tick while visible; every 3rd tick while hidden (cheap anyway).
    state._n = (state._n || 0) + 1;
    if (digestUrl && (!hidden || state._n % 3 === 1)) {
      jobs.push(fetchJson(digestUrl).then(function (d) {
        if (!d) return;
        if (typeof d.unread !== 'undefined') emitUnread(parseInt(d.unread, 10) || 0);
        emitDigest(d);
      }));
    }
    setStatus(state.failures > 0 ? 'reconnecting' : (hidden ? 'live' : 'live'));
    Promise.all(jobs.map(function (p) { return p.catch(function (e) { throw e; }); })).then(function () {
      state.failures = 0;
      state.delay = hidden ? HIDDEN : BASE;
      setStatus('live');
      schedule(state.delay);
    }).catch(function (err) {
      debug('tick failed', err);
      state.failures++;
      state.delay = Math.min(MAX_BACKOFF, (hidden ? HIDDEN : BASE) * Math.pow(2, Math.min(state.failures, 4)));
      setStatus(!navigator.onLine ? 'offline' : 'reconnecting');
      schedule(state.delay);
    });
  }

  function schedule(ms) {
    if (state.timer) clearTimeout(state.timer);
    state.timer = setTimeout(tick, Math.max(1000, ms));
  }

  function syncNow() {
    if (!state.running) return;
    schedule(300);
  }

  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) syncNow();
  });
  window.addEventListener('focus', syncNow);
  window.addEventListener('online', function () { state.failures = 0; syncNow(); });
  window.addEventListener('offline', function () { setStatus('offline'); });

  var api = {
    start: function () {
      if (state.running) return api;
      state.running = true;
      try {
        if (window.localStorage) {
          var s = JSON.parse(localStorage.getItem('edulive-state') || 'null');
          if (s && s.uid === cfg.userId && s.lastId > state.lastId) state.lastId = s.lastId;
        }
      } catch (e) {}
      setStatus('connecting');
      schedule(800);
      return api;
    },
    syncNow: syncNow,
    onNotification: function (fn) { if (typeof fn === 'function') state.subsN.push(fn); return api; },
    onDigest: function (fn) { if (typeof fn === 'function') state.subsD.push(fn); return api; },
    onUnread: function (fn) { if (typeof fn === 'function') state.subsU.push(fn); return api; },
    bindStatus: function (el) { if (el) { state.statusEls.push(el); setStatus(state.failures ? 'reconnecting' : 'live'); } return api; },
    getLastId: function () { return state.lastId; },
    /* Non-destructive refresh pill: counts new matching events, user clicks to reload
       (preserves filters/pagination unlike a forced reload). */
    refreshOnEvent: function (opts) {
      opts = opts || {};
      var match = opts.match || [];
      if (typeof match === 'string') match = [match];
      var mount = null;
      try { mount = opts.mount ? document.querySelector(opts.mount) : document.body; } catch (e) { mount = document.body; }
      if (!mount || mount.__eduPill) return api;
      var pill = document.createElement('button');
      pill.type = 'button';
      pill.className = 'btn btn-sm btn-warning shadow';
      pill.setAttribute('role', 'status');
      pill.setAttribute('aria-live', 'polite');
      pill.style.cssText = 'position:fixed;bottom:76px;left:50%;transform:translateX(-50%);z-index:1050;display:none;border-radius:999px;';
      var n = 0;
      pill.addEventListener('click', function () { window.location.reload(); });
      mount.appendChild(pill);
      mount.__eduPill = pill;
      function hay(n2) { return ((n2.title || '') + ' ' + (n2.message || '') + ' ' + (n2.link || '') + ' ' + (n2.type || '')).toLowerCase(); }
      api.onNotification(function (items) {
        var hit = 0;
        items.forEach(function (it) {
          var h = hay(it);
          for (var i = 0; i < match.length; i++) {
            if (h.indexOf(String(match[i]).toLowerCase()) >= 0) { hit++; break; }
          }
        });
        if (hit > 0) {
          n += hit;
          pill.innerHTML = '<i class="bi bi-arrow-clockwise"></i> ' + n + ' new update' + (n === 1 ? '' : 's') + ' — Refresh';
          pill.style.display = '';
        }
      });
      return api;
    }
  };

  window.EduLive = api;
})();
