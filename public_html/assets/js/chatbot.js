/* EduPortal AI assistant — UI only. All logic lives in /api/chatbot.php.
   Kept dependency-free and styled with the existing navy brand so it matches
   the portal. Upgraded: suggestion chips, typing indicator, Enter to send,
   Shift+Enter for a new line, duplicate-submit guard, retry that preserves the
   user's message, a conversation reset, and a mobile-safe layout. */
(function () {
  if (document.getElementById('eduChatRoot')) return;

  var endpoint = (function () {
    var s = document.currentScript && document.currentScript.src || '';
    if (s) {
      try {
        var u = new URL(s, location.href);
        // .../assets/js/chatbot.js  ->  .../api/chatbot.php
        return u.pathname.replace(/\/assets\/js\/chatbot\.js$/, '/api/chatbot.php');
      } catch (e) {}
    }
    var m = location.pathname.match(/^(.*?\/public_html)/);
    return (m ? m[1] : '') + '/api/chatbot.php';
  })();

  var NAVY = '#0F172A';
  var css = [
    '#eduChatBtn{position:fixed;right:18px;bottom:18px;z-index:1040;width:56px;height:56px;border-radius:50%;',
      'background:' + NAVY + ';color:#fff;border:0;box-shadow:0 8px 24px rgba(0,0,0,.18);display:flex;',
      'align-items:center;justify-content:center;font-size:22px;cursor:pointer;transition:transform .15s}',
    '#eduChatBtn:hover{transform:translateY(-2px)}',
    '#eduChatBtn:focus-visible{outline:3px solid rgba(46,109,164,.55);outline-offset:2px}',
    '#eduChatPanel{position:fixed;right:18px;bottom:80px;z-index:1040;width:370px;max-width:94vw;height:min(560px,78vh);',
      'background:#fff;border:1px solid #e2e8f0;border-radius:16px;box-shadow:0 16px 40px rgba(0,0,0,.18);',
      'display:none;flex-direction:column;overflow:hidden}',
    '#eduChatPanel.is-open{display:flex}',
    '.ec-head{background:' + NAVY + ';color:#fff;padding:11px 13px;display:flex;align-items:center;gap:8px;justify-content:space-between;flex:none}',
    '.ec-headbtn{background:rgba(255,255,255,.14);border:0;color:#fff;width:30px;height:30px;border-radius:8px;cursor:pointer;flex:none;font-size:14px;line-height:1}',
    '.ec-headbtn:hover{background:rgba(255,255,255,.26)}',
    '#eduChatMsgs{flex:1;overflow-y:auto;padding:12px;display:flex;flex-direction:column;gap:8px;background:#F8FAFC;min-height:0}',
    '.ec-msg{max-width:85%;padding:9px 11px;border-radius:12px;font-size:13.5px;line-height:1.55;white-space:pre-wrap;overflow-wrap:anywhere}',
    '.ec-bot{background:#fff;border:1px solid #e2e8f0;color:#1e293b;align-self:flex-start}',
    '.ec-user{background:' + NAVY + ';color:#fff;align-self:flex-end}',
    '.ec-note{align-self:center;background:#f0f9ff;border:1px solid #bae6fd;padding:7px 10px;border-radius:10px;font-size:11px;color:#0c4a6e;text-align:center;max-width:92%}',
    '.ec-typing{align-self:flex-start;background:#fff;border:1px solid #e2e8f0;padding:9px 12px;border-radius:12px;font-size:13px;color:#64748b}',
    '.ec-dot{display:inline-block;width:5px;height:5px;border-radius:50%;background:#94a3b8;margin-right:3px;animation:ecp 1.2s infinite}',
    '.ec-dot:nth-child(2){animation-delay:.18s}.ec-dot:nth-child(3){animation-delay:.36s}',
    '@keyframes ecp{0%,60%,100%{opacity:.25;transform:translateY(0)}30%{opacity:1;transform:translateY(-3px)}}',
    '#eduChatChips{display:flex;gap:6px;padding:8px 10px 0;flex-wrap:wrap;flex:none;background:#fff;border-top:1px solid #f1f5f9}',
    '.ec-chip{background:#f1f5f9;border:1px solid #e2e8f0;color:#334155;border-radius:999px;padding:5px 10px;font-size:11.5px;cursor:pointer;white-space:normal;text-align:left}',
    '.ec-chip:hover{background:#e2e8f0;border-color:#cbd5e1}',
    '#eduChatForm{display:flex;gap:7px;padding:9px 10px;border-top:1px solid #e2e8f0;background:#fff;flex:none;align-items:flex-end}',
    '#eduChatInput{flex:1;border:1px solid #cbd5e1;border-radius:10px;padding:9px 11px;font-size:16px;font-family:inherit;',
      'max-height:110px;overflow-y:auto;resize:none;outline:none;line-height:1.45}',
    '#eduChatInput:focus{border-color:#2E6DA4}',
    '#eduChatSend{background:' + NAVY + ';color:#fff;border:0;border-radius:10px;padding:10px 14px;font-weight:600;cursor:pointer;min-height:40px}',
    '#eduChatSend:disabled{opacity:.5;cursor:not-allowed}',
    '.ec-foot{font-size:10px;color:#94a3b8;text-align:center;padding:3px 8px;background:#fff;flex:none}',
    '@media (max-width:576px){#eduChatPanel{right:8px;left:8px;bottom:76px;width:auto;height:min(72vh,560px)}',
      '#eduChatBtn{right:12px;bottom:12px}}'
  ].join('');

  var st = document.createElement('style');
  st.textContent = css;
  document.head.appendChild(st);

  var root = document.createElement('div');
  root.id = 'eduChatRoot';
  root.innerHTML =
    '<button id="eduChatBtn" aria-label="Open EduPortal assistant" aria-expanded="false" aria-controls="eduChatPanel">💬</button>' +
    '<div id="eduChatPanel" role="dialog" aria-label="EduPortal assistant">' +
      '<div class="ec-head"><div><div style="font-weight:700;font-size:14px">EduPortal Assistant</div>' +
        '<div style="font-size:11px;opacity:.8">Datamex College of Saint Adeline</div></div>' +
        '<div style="display:flex;gap:6px">' +
          '<button id="eduChatReset" class="ec-headbtn" title="Start a new conversation" aria-label="Start a new conversation">⟳</button>' +
          '<button id="eduChatClose" class="ec-headbtn" aria-label="Close assistant">×</button>' +
        '</div></div>' +
      '<div id="eduChatMsgs" role="log" aria-live="polite" aria-atomic="false"></div>' +
      '<div id="eduChatChips"></div>' +
      '<form id="eduChatForm"><label class="visually-hidden" for="eduChatInput">Message</label>' +
        '<textarea id="eduChatInput" rows="1" maxlength="800" placeholder="Ask about enrollment, documents, payments, grades…"></textarea>' +
        '<button id="eduChatSend" type="submit">Send</button></form>' +
      '<div class="ec-foot">AI assistant — I can explain and guide, but every action is done by you in the portal.</div>' +
    '</div>';
  document.body.appendChild(root);

  var btn = document.getElementById('eduChatBtn'),
      panel = document.getElementById('eduChatPanel'),
      closeBtn = document.getElementById('eduChatClose'),
      resetBtn = document.getElementById('eduChatReset'),
      msgs = document.getElementById('eduChatMsgs'),
      chips = document.getElementById('eduChatChips'),
      form = document.getElementById('eduChatForm'),
      input = document.getElementById('eduChatInput'),
      send = document.getElementById('eduChatSend');

  var isGuest = false, busy = false;

  function csrf() {
    var m = document.querySelector('meta[name="csrf-token"]');
    return m ? m.content : '';
  }
  function scroll() { msgs.scrollTop = msgs.scrollHeight; }

  function addMsg(text, who) {
    var d = document.createElement('div');
    d.className = 'ec-msg ' + (who === 'user' ? 'ec-user' : 'ec-bot');
    d.textContent = text;
    msgs.appendChild(d);
    scroll();
    return d;
  }

  function setChips(list) {
    chips.innerHTML = '';
    if (!list || !list.length) return;
    list.forEach(function (label) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'ec-chip';
      b.textContent = label;
      b.addEventListener('click', function () { if (!busy) send2(label); });
      chips.appendChild(b);
    });
  }

  function renderHistory(rows) {
    msgs.innerHTML = '';
    if (!rows.length) {
      addMsg("Hi! I'm the EduPortal assistant. I can walk you through enrollment, documents, payments, grades, permits and clearances — in plain language. What do you need?", 'bot');
      return;
    }
    rows.forEach(function (r) { addMsg(r.text, r.role === 'user' ? 'user' : 'bot'); });
  }

  function loadHistory() {
    return fetch(endpoint + '?action=history', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        isGuest = !!j.guest;
        renderHistory(j.history || []);
        setChips(j.suggestions);
        if (isGuest) {
          var n = document.createElement('div');
          n.className = 'ec-note';
          var base = (location.pathname.match(/^(.*?\/public_html)/) || [''])[1];
          n.innerHTML = 'Browsing as guest. <a href="' + base + '/login" style="color:#2563eb;font-weight:600">Sign in</a> to keep this conversation.';
          msgs.insertBefore(n, msgs.firstChild);
        }
      })
      .catch(function () {
        isGuest = true;
        renderHistory([]);
      });
  }

  function send2(text) {
    if (busy || !text) return;
    busy = true;
    send.disabled = true;
    input.value = ''; input.style.height = 'auto';

    var userNode = addMsg(text, 'user');
    var typing = document.createElement('div');
    typing.className = 'ec-typing';
    typing.innerHTML = '<span class="ec-dot"></span><span class="ec-dot"></span><span class="ec-dot"></span>';
    msgs.appendChild(typing);
    scroll();

    fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() },
      credentials: 'same-origin',
      body: JSON.stringify({ message: text })
    })
      .then(function (r) { return r.json().then(function (j) { return { status: r.status, j: j }; }); })
      .then(function (res) {
        typing.remove();
        if (res.j.error) {
          // Keep the user's message and offer a real retry.
          var note = document.createElement('div');
          note.className = 'ec-note';
          note.style.background = '#fef2f2';
          note.style.borderColor = '#fecaca';
          note.style.color = '#991b1b';
          note.textContent = res.j.error;
          var retry = document.createElement('button');
          retry.type = 'button';
          retry.className = 'ec-chip';
          retry.style.marginTop = '6px';
          retry.textContent = 'Try again';
          retry.addEventListener('click', function () {
            note.remove();
            userNode.remove();
            input.value = text;
            send2(text);
          });
          note.appendChild(retry);
          msgs.appendChild(note);
          scroll();
          return;
        }
        addMsg(res.j.reply || "Sorry, I couldn't answer that. Could you rephrase?", 'bot');
        if (res.j.suggestions) setChips(res.j.suggestions);
      })
      .catch(function () {
        typing.remove();
        var note = document.createElement('div');
        note.className = 'ec-note';
        note.style.background = '#fef2f2';
        note.style.borderColor = '#fecaca';
        note.style.color = '#991b1b';
        note.textContent = "I couldn't reach the assistant. Check your connection and try again.";
        msgs.appendChild(note);
        scroll();
      })
      .finally(function () { busy = false; send.disabled = false; input.focus(); });
  }

  form.addEventListener('submit', function (e) { e.preventDefault(); send2(input.value.trim()); });

  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send2(input.value.trim()); }
  });
  input.addEventListener('input', function () {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 110) + 'px';
  });

  resetBtn.addEventListener('click', function () {
    if (busy) return;
    busy = true;
    fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() },
      credentials: 'same-origin',
      body: JSON.stringify({ reset: true })
    })
      .then(function (r) { return r.json(); })
      .then(function (j) { renderHistory([]); setChips(j.suggestions); })
      .catch(function () { renderHistory([]); })
      .finally(function () { busy = false; });
  });

  function toggle(force) {
    var open = typeof force === 'boolean' ? force : !panel.classList.contains('is-open');
    panel.classList.toggle('is-open', open);
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) { input.focus(); scroll(); }
  }
  btn.addEventListener('click', function () { toggle(); });
  closeBtn.addEventListener('click', function () { toggle(false); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && panel.classList.contains('is-open')) toggle(false);
  });

  loadHistory();
})();