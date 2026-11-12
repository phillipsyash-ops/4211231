// Loyal — Support chat widget (bottom-right) and the "swap unavailable" notice.
(() => {
  'use strict';
  const CFG = window.LOYAL_APP || {};
  if (!CFG.user) return;
  const API = 'support.php';
  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const fmt = (s) => esc(s).replace(/\n/g, '<br>').replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>');
  const api = async (a, body, q = '') => {
    const r = await fetch(`${API}?a=${a}${q}`, body ? { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF': CFG.csrf || '' }, body: JSON.stringify(body) } : { credentials: 'same-origin' });
    const d = await r.json().catch(() => ({ error: 'Connection problem — try again.' }));
    if (!r.ok || d.error) throw new Error(d.error || 'Something went wrong.');
    return d;
  };
  const IC = {
    chat: '<svg viewBox="0 0 24 24"><path d="M21 12a8 8 0 0 1-11.6 7.1L4 20.5l1.4-4.9A8 8 0 1 1 21 12z"/><path d="M8.5 11h.01M12 11h.01M15.5 11h.01"/></svg>',
    back: '<svg viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg>',
    x: '<svg viewBox="0 0 24 24"><path d="M18 6 6 18M6 6l12 12"/></svg>',
    send: '<svg viewBox="0 0 24 24"><path d="M5 12h13M13 6l6 6-6 6"/></svg>',
    plus: '<svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>',
  };

  // ---------- launcher ----------
  const fab = document.createElement('button');
  fab.type = 'button'; fab.className = 'sup-fab'; fab.setAttribute('aria-label', 'Support');
  fab.innerHTML = `${IC.chat}<span class="t">Support</span><b class="n" hidden></b>`;
  document.body.appendChild(fab);
  const badge = fab.querySelector('.n');
  let moreRow = null;
  const setBadge = (n) => { badge.hidden = !n; badge.textContent = n > 9 ? '9+' : n; moreRow && (moreRow.querySelector('b').hidden = !n); };

  // ---------- panel ----------
  const panel = document.createElement('div');
  panel.className = 'sup-panel'; panel.setAttribute('role', 'dialog'); panel.setAttribute('aria-label', 'Support');
  panel.innerHTML = `<div class="sup-head"><button type="button" class="sup-ib sup-back" hidden aria-label="Back">${IC.back}</button>
      <div class="sup-ttl"><b>Support</b><small>We usually reply within a few hours</small></div>
      <button type="button" class="sup-ib sup-close" aria-label="Close">${IC.x}</button></div>
    <div class="sup-body"></div><div class="sup-foot"></div>`;
  document.body.appendChild(panel);
  const $b = panel.querySelector('.sup-body'), $f = panel.querySelector('.sup-foot'), $back = panel.querySelector('.sup-back'), $ttl = panel.querySelector('.sup-ttl');
  let view = null, tid = 0, lastId = 0, poll = 0;
  const setTitle = (t, s) => { $ttl.innerHTML = `<b>${esc(t)}</b><small>${esc(s)}</small>`; };
  const stopPoll = () => { clearInterval(poll); poll = 0; };

  function open(opts = {}) {
    panel.classList.add('open'); fab.classList.add('hide'); document.documentElement.classList.add('sup-open');
    if (opts.compose) compose(opts.compose); else list();
  }
  function close() { panel.classList.remove('open'); fab.classList.remove('hide'); document.documentElement.classList.remove('sup-open'); stopPoll(); view = null; refreshUnread(); }
  fab.onclick = () => open();
  panel.querySelector('.sup-close').onclick = close;
  $back.onclick = () => list();
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && panel.classList.contains('open')) close(); });

  async function list() {
    view = 'list'; stopPoll(); $back.hidden = true;
    setTitle('Support', 'We usually reply within a few hours');
    $b.innerHTML = '<div class="sup-load"><i></i><i></i><i></i></div>'; $f.innerHTML = '';
    try {
      const d = await api('list');
      if (view !== 'list') return;
      if (!d.tickets.length) {
        $b.innerHTML = `<div class="sup-empty"><div class="ic">${IC.chat}</div><h4>How can we help?</h4><p>Ask about transfers, swaps, your wallet or anything else — a real person will answer here.</p></div>`;
      } else {
        $b.innerHTML = `<div class="sup-list">${d.tickets.map((t) => `
          <button type="button" class="sup-t${t.unread ? ' unread' : ''}" data-id="${t.id}">
            <span class="r1"><b>${esc(t.subject)}</b><span class="st ${t.status}">${{ open: 'Open', answered: 'Answered', closed: 'Closed' }[t.status] || esc(t.status)}</span></span>
            <span class="r2">${esc(t.last)}</span><span class="r3">${esc(t.at)}${t.unread ? ' · <em>new reply</em>' : ''}</span></button>`).join('')}</div>`;
        $b.querySelectorAll('.sup-t').forEach((el) => (el.onclick = () => thread(+el.dataset.id)));
      }
      $f.innerHTML = `<button type="button" class="sup-new">${IC.plus}New request</button>`;
      $f.querySelector('.sup-new').onclick = () => compose();
      setBadge(d.tickets.reduce((s, t) => s + t.unread, 0));
    } catch (e) { $b.innerHTML = `<div class="sup-err">${esc(e.message)}</div>`; }
  }

  function compose(pre = {}) {
    view = 'new'; stopPoll(); $back.hidden = false;
    setTitle('New request', 'Describe the issue — we’ll reply here');
    const topics = ['Swap', 'Transfer', 'My wallet', 'Login & security', 'Other'];
    $b.innerHTML = `<div class="sup-new-f">
      <label>Topic</label><div class="sup-chips">${topics.map((t) => `<button type="button" data-t="${esc(t)}"${pre.subject === t ? ' class="on"' : ''}>${esc(t)}</button>`).join('')}</div>
      <label for="sup-subj">Subject</label><input id="sup-subj" maxlength="140" placeholder="Short summary" value="${esc(pre.subject || '')}">
      <label for="sup-msg">Message</label><textarea id="sup-msg" maxlength="2000" rows="6" placeholder="What happened? Add the coin, amount or address if it helps.">${esc(pre.body || '')}</textarea>
      <p class="sup-err" hidden></p></div>`;
    $f.innerHTML = `<button type="button" class="sup-new sup-send-new">${IC.send}Send</button>`;
    const subj = $b.querySelector('#sup-subj'), msg = $b.querySelector('#sup-msg'), err = $b.querySelector('.sup-err');
    $b.querySelectorAll('.sup-chips button').forEach((c) => (c.onclick = () => {
      $b.querySelectorAll('.sup-chips button').forEach((x) => x.classList.toggle('on', x === c));
      if (!subj.value || topics.includes(subj.value)) subj.value = c.dataset.t;
      msg.focus();
    }));
    setTimeout(() => (pre.subject ? msg : subj).focus(), 60);
    const btn = $f.querySelector('button');
    btn.onclick = async () => {
      err.hidden = true;
      if (!msg.value.trim()) { err.textContent = 'Write a message.'; err.hidden = false; return; }
      btn.disabled = true;
      try { const d = await api('new', { subject: subj.value, body: msg.value }); thread(d.id); }
      catch (e) { err.textContent = e.message; err.hidden = false; btn.disabled = false; }
    };
  }

  function bubble(m) { return `<div class="sup-m ${m.me ? 'me' : 'them'}" data-id="${m.id}"><div class="bb">${fmt(m.body)}</div><small>${m.me ? '' : 'Support · '}${esc(m.at)}</small></div>`; }
  async function thread(id) {
    view = 'thread'; tid = id; lastId = 0; stopPoll(); $back.hidden = false;
    $b.innerHTML = '<div class="sup-load"><i></i><i></i><i></i></div>';
    $f.innerHTML = `<div class="sup-input"><textarea rows="1" maxlength="2000" placeholder="Message…"></textarea><button type="button" aria-label="Send">${IC.send}</button></div>`;
    const ta = $f.querySelector('textarea'), sb = $f.querySelector('button');
    const grow = () => { ta.style.height = 'auto'; ta.style.height = Math.min(120, ta.scrollHeight) + 'px'; };
    ta.addEventListener('input', grow);
    const send = async () => {
      const body = ta.value.trim(); if (!body || sb.disabled) return;
      sb.disabled = true;
      try { const d = await api('send', { id: tid, body, after: lastId }); ta.value = ''; grow(); add(d.messages); }
      catch (e) { alert(e.message); }
      sb.disabled = false; ta.focus();
    };
    sb.onclick = send;
    ta.addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.shiftKey && !matchMedia('(pointer: coarse)').matches) { e.preventDefault(); send(); } });
    try {
      const d = await api('get', null, `&id=${id}`);
      if (view !== 'thread' || tid !== id) return;
      setTitle(d.ticket.subject, `Request #${d.ticket.id} · ${{ open: 'open', answered: 'answered', closed: 'closed' }[d.ticket.status] || d.ticket.status}`);
      $b.innerHTML = `<div class="sup-thread"></div>${d.ticket.status === 'closed' ? '<div class="sup-closed">This request is closed. Write a message to reopen it.</div>' : ''}`;
      add(d.messages);
      refreshUnread();
      poll = setInterval(async () => {
        if (document.hidden) return;
        try { const n = await api('get', null, `&id=${tid}&after=${lastId}`); if (view === 'thread') add(n.messages); } catch (e) {}
      }, 6000);
    } catch (e) { $b.innerHTML = `<div class="sup-err">${esc(e.message)}</div>`; }
  }
  function add(msgs) {
    const box = $b.querySelector('.sup-thread'); if (!box || !msgs || !msgs.length) return;
    msgs.forEach((m) => { if (m.id > lastId && !box.querySelector(`[data-id="${m.id}"]`)) { box.insertAdjacentHTML('beforeend', bubble(m)); lastId = m.id; } });
    $b.scrollTop = $b.scrollHeight;
  }

  // unread replies → badge on the button
  async function refreshUnread() { try { const d = await api('unread'); setBadge(d.unread); } catch (e) {} }
  setBadge(CFG.supportUnread || 0);
  setInterval(() => { if (!document.hidden && !panel.classList.contains('open')) refreshUnread(); }, 45000);

  // app: "Support" in the More sheet as well
  const addMore = () => {
    const sheet = document.querySelector('.app-more .sheet'); if (!sheet || moreRow) return;
    moreRow = document.createElement('button'); moreRow.type = 'button'; moreRow.className = 'row';
    moreRow.innerHTML = `${IC.chat}<span>Support</span><b class="sup-dot" hidden></b>`;
    moreRow.onclick = () => { document.querySelector('.app-more')?.classList.remove('open'); open(); };
    const hr = sheet.querySelector('hr'); hr ? sheet.insertBefore(moreRow, hr) : sheet.appendChild(moreRow);
    setBadge(CFG.supportUnread || 0);
  };
  addMore(); setTimeout(addMore, 300);

  window.loyalSupport = { open, compose: (pre) => open({ compose: pre || {} }) };

  // ---------- swap unavailable for this account ----------
  if (CFG.swapBlocked) {
    const show = () => {
      if (document.querySelector('.sup-block')) return;
      const m = document.createElement('div'); m.className = 'sup-block';
      m.innerHTML = `<div class="card"><div class="ic">!</div><h3>Swap unavailable</h3>
        <p>An error occurred: swapping is unavailable for your account. Please contact support — we’ll sort it out.</p>
        <button type="button" class="btn btn-p btn-block btn-lg" data-go>Contact support</button>
        <button type="button" class="btn btn-block" data-x style="margin-top:8px">Close</button></div>`;
      m.addEventListener('click', (e) => {
        if (e.target.closest('[data-go]')) { m.remove(); window.loyalSupport.compose({ subject: 'Swap', body: 'Hi! Swap shows an error on my account. ' }); }
        else if (e.target === m || e.target.closest('[data-x]')) m.remove();
      });
      document.body.appendChild(m);
    };
    const form = document.getElementById('swap');
    if (form) form.addEventListener('submit', (e) => { e.preventDefault(); e.stopImmediatePropagation(); show(); }, true);
    if (/[?&]blocked=1\b/.test(location.search)) setTimeout(show, 200);
  }
})();
