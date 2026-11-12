import { api, local, session, encryptSecret, decryptSecret, usd, amt, pct, ApiError } from './common.js';
import { DEFAULT_SITE, APP_NAME } from './config.js';

const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];
const app = $('#app');
const h = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

const S = { site: null, token: null, me: null, wid: null, prices: {}, coins: {}, tab: 'assets', timer: null };

// ---------- icons ----------
const I = {
  gear: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg>',
  lock: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>',
  back: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>',
  ext: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></svg>',
};
const contactName = (addr) => (S.me?.contacts || []).find((c) => c.address === addr)?.name || '';
const coinIc = (sym) => { const c = S.coins[sym] || {}; return `<span class="ic" style="background:${h(c.bg || 'var(--card2)')};color:${h(c.ink || 'var(--txt)')}">${h(c.glyph || sym[0])}<i>x</i></span>`; };

// ---------- helpers ----------
let toastT;
function toast(msg, err = false) {
  const t = $('#toast'); t.textContent = msg; t.className = 'toast show' + (err ? ' err' : '');
  clearTimeout(toastT); toastT = setTimeout(() => (t.className = 'toast'), 2600);
}
function render(html) { clearInterval(S.timer); app.innerHTML = `<div class="fade" style="height:100%;display:flex;flex-direction:column">${html}</div>`; }
function busy(btn, on) { if (!btn) return; btn.disabled = on; if (on) { btn.dataset.t = btn.textContent; btn.innerHTML = '<span class="spin" style="width:16px;height:16px;margin:0"></span>'; } else btn.textContent = btn.dataset.t || btn.textContent; }
function showErr(msg) { const e = $('.err'); if (e) { e.textContent = msg; e.style.display = ''; } else toast(msg, true); }
const touch = () => session.set('lastActive', Date.now());
const wallet = () => S.me?.wallets.find((w) => w.id === S.wid) || S.me?.wallets[0];
const total = (w) => Object.entries(w.balances).reduce((s, [c, v]) => s + parseFloat(v) * (S.prices[c]?.usd || 0), 0);
const coinDec = (c) => S.coins[c]?.dec ?? 8;

async function authed(action, opts = {}) {
  try { return await api(action, { ...opts, token: S.token }); }
  catch (e) {
    if (e.status === 401) { await session.del('token'); S.token = null; toast(e.message, true); viewUnlock(); }
    throw e;
  }
}

// ---------- start ----------
async function init() {
  touch();
  chrome.action.setBadgeText({ text: '' }); session.set('unread', 0);
  S.site = await local.get('site');
  S.coins = (await local.get('coins')) || {};
  if (!S.site && /^https?:\/\/[^/]+/.test(DEFAULT_SITE)) {
    // the site wrote its address in at download time — connect without asking
    try {
      const r = await api('ping', { site: DEFAULT_SITE });
      await local.set('site', DEFAULT_SITE); await local.set('coins', r.coins);
      S.site = DEFAULT_SITE; S.coins = r.coins;
    } catch (e) { return viewSite(); }
  }
  if (!S.site) return viewSite();
  S.token = await session.get('token');
  api('ping').then((r) => { S.coins = r.coins; local.set('coins', r.coins); }).catch(() => {});
  if (S.token) return openWallet();
  if (await session.get('pendingToken')) return viewSetPw();
  const link = await local.get('link');
  if (link?.status === 'waiting') return viewLinkWait();
  if (link?.status === 'expired') { await local.del('link'); toast('The confirmation timed out. Please try again.', true); }
  if (await local.get('vault')) return viewUnlock();
  viewWelcome();
}

// ---------- 1. site address ----------
function viewSite() {
  render(`<div class="center">
    <img class="hero-logo" src="icons/coin-96.png" alt=""><h1>${h(APP_NAME)}</h1><p class="sub">Enter your wallet site address</p>
    <div class="err" style="display:none"></div>
    <div class="field"><label>Site address</label><input id="site" value="${h(S.site || DEFAULT_SITE)}" placeholder="https://your-site.com" spellcheck="false"></div>
    <button class="btn btn-p btn-block btn-lg" id="go">Continue</button>
    <p class="muted" style="font-size:11px;text-align:center;margin-top:12px">Chrome will ask you to let the extension access this site.</p>
  </div>`);
  const go = async () => {
    let v = $('#site').value.trim().replace(/\/+$/, '');
    if (!/^https?:\/\//.test(v)) v = 'https://' + v;
    let origin; try { origin = new URL(v).origin; } catch (e) { return showErr('Invalid address'); }
    if (!/^https?:\/\/[^/]+\.[^/]+/.test(origin) && !/localhost|127\.0\.0\.1/.test(origin)) return showErr('Invalid address');
    const granted = await chrome.permissions.contains({ origins: [origin + '/*'] }).catch(() => false)
      || await chrome.permissions.request({ origins: [origin + '/*'] }).catch(() => false);
    if (!granted) return showErr('The extension can’t work without access to the site.');
    const btn = $('#go'); busy(btn, true);
    try {
      const r = await api('ping', { site: v });
      await local.set('site', v); await local.set('coins', r.coins); S.site = v; S.coins = r.coins;
      viewWelcome();
    } catch (e) { busy(btn, false); showErr(e.status === 404 ? 'No wallet site found at this address.' : e.message); }
  };
  $('#go').onclick = go; $('#site').onkeydown = (e) => e.key === 'Enter' && go();
}

// ---------- 2. choose how to log in ----------
function viewWelcome() {
  render(`<div class="center">
    <img class="hero-logo" src="icons/coin-96.png" alt=""><h1>Log in to your wallet</h1>
    <p class="sub">${h(new URL(S.site).host)}</p>
    <div class="stack">
      <button class="btn btn-p btn-block btn-lg" id="bySeed">With recovery phrase</button>
      <div class="or">or</div>
      <button class="btn btn-block btn-lg" id="bySite">Log in via website</button>
    </div>
    <p class="muted" style="font-size:11px;text-align:center;margin-top:12px">“Via website” works if you’re already logged in on the site in this browser. A tab opens where you confirm the connection.</p>
    <div class="foot-links"><button class="link" id="chSite">Change site address</button></div>
  </div>`);
  $('#bySeed').onclick = viewSeed;
  $('#bySite').onclick = async () => {
    busy($('#bySite'), true);
    const r = await chrome.runtime.sendMessage({ type: 'link_start' });
    if (r?.error) { busy($('#bySite'), false); return toast(r.error, true); }
    viewLinkWait(); // the popup may close — the site tab is already open
  };
  $('#chSite').onclick = viewSite;
}

// ---------- 3a. log in with recovery phrase ----------
function viewSeed() {
  render(`<div class="top"><button class="ib" id="back">${I.back}</button><div class="title">Recovery phrase login</div></div>
  <div class="scroll">
    <div class="err" style="display:none"></div>
    <div class="field"><label>Recovery phrase (12 words)</label><textarea id="seed" rows="4" spellcheck="false" autocapitalize="off" placeholder="word1 word2 … word12"></textarea></div>
    <div class="field"><label>Wallet password</label><input type="password" id="pw"></div>
    <div class="field" id="codeF" style="display:none"><label>2FA code from your authenticator app</label><input type="text" id="code" inputmode="numeric" maxlength="7" autocomplete="one-time-code" placeholder="000000"></div>
    <div class="note">The same password will unlock the extension. The recovery phrase itself is not saved in the extension.</div>
    <button class="btn btn-p btn-block btn-lg" id="go">Log in</button>
  </div>`);
  $('#back').onclick = viewWelcome;
  $('#go').onclick = async () => {
    const seed = $('#seed').value.trim(), pw = $('#pw').value;
    if (seed.split(/\s+/).length < 12 || !pw) return showErr('Enter the 12 words and your password.');
    const btn = $('#go'); busy(btn, true);
    try {
      const code = $('#code').value.replace(/\D/g, '');
      const r = await api('login', { body: { seed, password: pw, ...(code ? { code } : {}) } });
      await saveToken(r.token, pw);
      $('#seed').value = '';
      openWallet();
    } catch (e) {
      busy(btn, false); showErr(e.message);
      if (e.status === 428) { $('#codeF').style.display = ''; $('#code').value = ''; $('#code').focus(); }
    }
  };
}

async function saveToken(token, pw) {
  await local.set('vault', await encryptSecret(token, pw));
  await session.set('token', token); S.token = token;
  await local.del('lastTx'); // notifications start from now
  chrome.runtime.sendMessage({ type: 'poll_now' }).catch(() => {});
}

// ---------- 3b. log in via website ----------
async function viewLinkWait() {
  render(`<div class="center">
    <div class="spin" style="width:40px;height:40px;margin-bottom:22px"></div>
    <h1>Confirm on the website</h1>
    <p class="sub">In the site tab, click “Connect extension”. Then open the extension again.</p>
    <button class="btn btn-block" id="cancel">Cancel</button>
  </div>`);
  $('#cancel').onclick = async () => { await local.del('link'); viewWelcome(); };
  // wait until the background script receives the key
  const iv = setInterval(async () => {
    if (await session.get('pendingToken')) { clearInterval(iv); viewSetPw(); }
    const l = await local.get('link');
    if (!l) clearInterval(iv);
    else if (l.status === 'expired') { clearInterval(iv); await local.del('link'); toast('The confirmation timed out', true); viewWelcome(); }
  }, 1000);
  S.timer = iv;
}

function viewSetPw() {
  render(`<div class="center">
    <img class="hero-logo" src="icons/coin-96.png" alt=""><h1>Extension connected</h1>
    <p class="sub">Choose a password to unlock the extension</p>
    <div class="err" style="display:none"></div>
    <div class="field"><label>Password (at least 8 characters)</label><input type="password" id="p1"></div>
    <div class="field"><label>Repeat password</label><input type="password" id="p2"></div>
    <button class="btn btn-p btn-block btn-lg" id="go">Save and open</button>
  </div>`);
  $('#p1').focus();
  $('#go').onclick = async () => {
    const p1 = $('#p1').value, p2 = $('#p2').value;
    if (p1.length < 8) return showErr('At least 8 characters.');
    if (p1 !== p2) return showErr('The passwords don’t match.');
    busy($('#go'), true);
    const t = await session.get('pendingToken');
    await saveToken(t, p1);
    await session.del('pendingToken'); await local.del('link');
    openWallet();
  };
}

// ---------- 4. unlock ----------
function viewUnlock() {
  render(`<div class="center">
    <img class="hero-logo" src="icons/coin-96.png" alt=""><h1>Wallet locked</h1><p class="sub">Enter your extension password</p>
    <div class="err" style="display:none"></div>
    <div class="field"><input type="password" id="pw" placeholder="Password" autofocus></div>
    <button class="btn btn-p btn-block btn-lg" id="go">Unlock</button>
    <div class="foot-links"><button class="link" id="reset">Forgot the password? Log in again</button></div>
  </div>`);
  const go = async () => {
    const btn = $('#go'); busy(btn, true);
    try {
      const t = await decryptSecret(await local.get('vault'), $('#pw').value);
      await session.set('token', t); S.token = t; touch();
      openWallet();
    } catch (e) { busy(btn, false); showErr('Wrong password.'); $('#pw').select(); }
  };
  $('#go').onclick = go; $('#pw').onkeydown = (e) => e.key === 'Enter' && go(); $('#pw').focus();
  $('#reset').onclick = async () => { if (confirm('The extension will forget this login. You can log in again with your recovery phrase or via the website.')) { await signOut(false); viewWelcome(); } };
}

async function signOut(revoke = true) {
  if (revoke && S.token) await api('logout', { token: S.token, body: {} }).catch(() => {});
  await session.del('token'); await local.del('vault'); await local.del('lastTx'); await local.del('seenReq'); await local.del('seenAlerts');
  S.token = null; S.me = null;
}

// ---------- 5. wallet ----------
async function openWallet() {
  render('<div class="center"><div class="spin"></div></div>');
  try { await loadMe(); } catch (e) { if (e.status !== 401) render(`<div class="center"><p class="sub">${h(e.message)}</p><button class="btn btn-block" id="retry">Retry</button></div>`), $('#retry').onclick = openWallet; return; }
  viewMain();
}
async function loadMe() {
  S.me = await authed('me');
  S.prices = S.me.prices;
  const saved = await local.get('wid');
  S.wid = S.me.wallets.some((w) => w.id === saved) ? saved : S.me.wallets[0]?.id;
}

function topBar() {
  return `<div class="top">
    <img class="logo" src="icons/coin-96.png" alt="">
    <select class="wsel" id="wsel">${S.me.wallets.map((w) => `<option value="${w.id}" ${w.id === S.wid ? 'selected' : ''}>${h(w.name)}</option>`).join('')}</select>
    <button class="ib" id="set" title="Settings">${I.gear}</button>
    <button class="ib" id="lockb" title="Lock">${I.lock}</button>
  </div>`;
}

function viewMain() {
  const w = wallet();
  render(`${topBar()}
  <div class="scroll">
    ${(S.me.alerts || []).map((a) => `<div class="ann alert"><div class="n"><b>🔔 ${h(a.text)}</b><p>Now ${h(a.now)}</p></div><button data-alert="${a.id}" aria-label="Dismiss">×</button></div>`).join('')}
    ${(S.me.announcements || []).map((a) => `<div class="ann ${h(a.level)}"><div class="n"><b>${h(a.title)}</b>${a.body ? `<p>${h(a.body)}</p>` : ''}</div><button data-ann="${a.id}" aria-label="Dismiss">×</button></div>`).join('')}
    <div class="bal">
      <div class="lbl"><span class="dot"></span>${h(w.name)} · total balance</div>
      <div class="big" id="tot">${usd(total(w))}</div>
      <div class="acts"><button class="btn btn-p" id="send">↑ Send</button><button class="btn" id="recv">↓ Receive</button></div>
    </div>
    <div class="tabs">
      <button data-t="assets" class="${S.tab === 'assets' ? 'on' : ''}">Assets</button>
      <button data-t="rates" class="${S.tab === 'rates' ? 'on' : ''}">Prices</button>
      <button data-t="history" class="${S.tab === 'history' ? 'on' : ''}">History</button>
    </div>
    <div id="tabc"></div>
    <div class="foot-links"><button class="link" id="site">Open your account on the site</button></div>
  </div>`);
  $$('[data-alert]').forEach((b) => (b.onclick = () => { const id = +b.dataset.alert; b.closest('.ann').remove(); S.me.alerts = S.me.alerts.filter((x) => x.id !== id); authed('dismiss', { body: { alert: id } }).catch(() => {}); }));
  $$('[data-ann]').forEach((b) => (b.onclick = () => { const id = +b.dataset.ann; b.closest('.ann').remove(); S.me.announcements = S.me.announcements.filter((x) => x.id !== id); authed('dismiss', { body: { id } }).catch(() => {}); }));
  $('#wsel').onchange = (e) => { S.wid = +e.target.value; local.set('wid', S.wid); viewMain(); };
  $('#set').onclick = viewSettings;
  $('#lockb').onclick = async () => { await session.del('token'); S.token = null; viewUnlock(); };
  $('#send').onclick = () => viewSend();
  $('#recv').onclick = () => viewRecv();
  $('#site').onclick = () => chrome.tabs.create({ url: `${S.site}/wallet.php?w=${S.wid}` });
  $$('.tabs button').forEach((b) => (b.onclick = () => { S.tab = b.dataset.t; $$('.tabs button').forEach((x) => x.classList.toggle('on', x === b)); renderTab(); }));
  renderTab();
  // live prices and balance
  S.timer = setInterval(async () => {
    touch();
    try { S.prices = await api('prices'); } catch (e) { return; }
    const t = $('#tot'); if (t) t.textContent = usd(total(wallet()));
    if (S.tab !== 'history') renderTab();
  }, 15000);
}

async function renderTab() {
  const w = wallet(), box = $('#tabc'); if (!box) return;
  if (S.tab === 'assets') {
    let list = Object.keys(S.coins);
    if (S.me.hide_zero) { const nz = list.filter((c) => parseFloat(w.balances[c] || 0) > 0); if (nz.length) list = nz; }
    box.innerHTML = `<div class="list">${list.map((c) => {
      const v = w.balances[c] || '0', p = S.prices[c] || { usd: 0, ch: 0 };
      return `<div class="item" data-c="${c}">${coinIc(c)}<div class="n"><b>${h(S.coins[c].name)}‑x</b><small>${amt(v, coinDec(c))} ${c}‑x</small></div>
        <div class="v">${usd(parseFloat(v) * p.usd)}<small class="${p.ch >= 0 ? 'up' : 'down'}">${pct(p.ch)}</small></div></div>`;
    }).join('')}</div>`;
  } else if (S.tab === 'rates') {
    box.innerHTML = `<div class="list">${Object.keys(S.coins).map((c) => {
      const p = S.prices[c] || { usd: 0, ch: 0 };
      return `<div class="item" data-c="${c}">${coinIc(c)}<div class="n"><b>${c}‑x</b><small>${h(S.coins[c].name)}</small></div>
        <div class="v">${usd(p.usd)}<small class="${p.ch >= 0 ? 'up' : 'down'}">${pct(p.ch)}</small></div></div>`;
    }).join('')}</div>`;
  } else {
    box.innerHTML = '<div class="empty"><div class="spin"></div></div>';
    let r; try { r = await authed('txs', { params: { w: S.wid } }); } catch (e) { box.innerHTML = `<div class="empty">${h(e.message)}</div>`; return; }
    if (S.tab !== 'history') return;
    box.innerHTML = r.txs.length ? `<div class="list">${r.txs.map((t) => {
      const d = new Date(t.time * 1000).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false });
      if (t.type === 'swap') return `<div class="item nohover"><span class="ic tx">⇄</span>
        <div class="n"><b>Swap ${h(t.coin)}‑x → ${h(t.coin2)}‑x</b><small>${h(t.note || '')}</small></div>
        <div class="v">−${amt(t.amount, coinDec(t.coin))} ${h(t.coin)}<small class="up">+${amt(t.amount2, coinDec(t.coin2))} ${h(t.coin2)}</small></div></div>`;
      const title = t.type === 'credit' ? 'Credited' : t.type === 'debit' ? 'Debited' : t.in ? 'Received' : 'Sent';
      const who = t.peer_name ? t.peer_name : t.peer;
      const sub = t.peer ? (t.in ? 'from ' : 'to ') + who : (S.me.site || '') + ' ledger';
      return `<div class="item nohover"><span class="ic tx ${t.in ? 'in' : ''}">${t.in ? '↓' : '↑'}</span>
        <div class="n"><b>${title} · ${h(t.coin)}‑x</b><small title="${h(sub)}">${h(sub)}${t.note ? ' · «' + h(t.note) + '»' : ''}</small></div>
        <div class="v ${t.in ? 'up' : ''}">${t.in ? '+' : '−'}${amt(t.amount, coinDec(t.coin))}<small>${d}</small></div></div>`;
    }).join('')}</div>` : '<div class="empty">No transactions yet</div>';
    return;
  }
  $$('.item[data-c]', box).forEach((el) => (el.onclick = () => viewChart(el.dataset.c)));
}

// ---------- 6. send ----------
function viewSend(prefill = {}) {
  const w = wallet();
  render(`<div class="top"><button class="ib" id="back">${I.back}</button><div class="title">Send</div><span class="muted" style="font-size:11px">${h(w.name)}</span></div>
  <div class="scroll">
    <div class="err" style="display:none"></div>
    <div class="field"><label>Coin</label><select id="coin">${Object.keys(S.coins).map((c) => `<option value="${c}" ${prefill.coin === c ? 'selected' : ''}>${c}‑x — ${amt(w.balances[c] || '0', coinDec(c))}</option>`).join('')}</select></div>
    ${(S.me.contacts || []).length ? `<div class="field"><label>From contacts</label><select id="ct"><option value="">— choose —</option>${S.me.contacts.map((c) => `<option value="${h(c.address)}" data-coin="${h(c.coin)}">${h(c.name)} · ${h(c.coin)}‑x</option>`).join('')}</select></div>` : ''}
    <div class="field"><label>Recipient address <span class="chk" id="chk"></span></label><input id="to" spellcheck="false" placeholder="Paste an address" value="${h(prefill.to || '')}"></div>
    <div class="field"><label>Amount <span class="lbl-r" id="max">Max</span></label><input id="amount" inputmode="decimal" placeholder="0.00" value="${h(prefill.amount || '')}"></div>
    <div class="field"><label>Note</label><input id="note" maxlength="140" value="${h(prefill.note || '')}"></div>
    <button class="btn btn-p btn-block btn-lg" id="next">Next</button>
  </div>`);
  $('#back').onclick = viewMain;
  $('#max').onclick = () => ($('#amount').value = w.balances[$('#coin').value] || '0');
  let tm;
  const check = () => { clearTimeout(tm); const v = $('#to').value.trim(), el = $('#chk'); el.textContent = ''; if (v.length < 20) return;
    tm = setTimeout(async () => { try { const r = await authed('check_addr', { params: { addr: v } });
      if (r.ok) { el.textContent = '✓ ' + r.coin + '‑x'; el.className = 'chk up'; $('#coin').value = r.coin; } else { el.textContent = 'not found'; el.className = 'chk down'; } } catch (e) {} }, 300); };
  $('#to').oninput = check; if (prefill.to) check();
  if ($('#ct')) $('#ct').onchange = (e) => { const o = e.target.selectedOptions[0]; if (!o.value) return; $('#to').value = o.value; $('#coin').value = o.dataset.coin; check(); };
  $('#next').onclick = () => {
    const d = { coin: $('#coin').value, to: $('#to').value.trim(), amount: $('#amount').value.trim().replace(',', '.'), note: $('#note').value.trim() };
    if (!d.to) return showErr('Enter an address.');
    if (!(parseFloat(d.amount) > 0)) return showErr('Enter an amount greater than zero.');
    if (parseFloat(d.amount) > parseFloat(w.balances[d.coin] || 0)) return showErr(`Not enough ${d.coin}‑x in this wallet.`);
    viewConfirm(d);
  };
}
function viewConfirm(d) {
  const p = S.prices[d.coin]?.usd || 0;
  render(`<div class="top"><button class="ib" id="back">${I.back}</button><div class="title">Confirm</div></div>
  <div class="scroll">
    <div style="text-align:center;margin:10px 0 16px"><div class="ch-price">${amt(d.amount, coinDec(d.coin))} ${d.coin}‑x</div><div class="muted">≈ ${usd(parseFloat(d.amount) * p)}</div></div>
    <div class="sum">
      <div><span>From wallet</span><b>${h(wallet().name)}</b></div>
      <div><span>Recipient</span><b>${h(contactName(d.to) ? contactName(d.to) + ' · ' : '')}${h(d.to)}</b></div>
      ${d.note ? `<div><span>Note</span><b>${h(d.note)}</b></div>` : ''}
      <div><span>Fee</span><b>0</b></div>
    </div>
    <div class="field"><label>🔒 Wallet password</label><input type="password" id="cpw" autocomplete="current-password"></div>
    <div class="err" style="display:none"></div>
    <button class="btn btn-p btn-block btn-lg" id="go">Confirm and send</button>
  </div>`);
  $('#back').onclick = () => viewSend(d);
  $('#cpw').focus();
  $('#cpw').onkeydown = (e) => e.key === 'Enter' && $('#go').click();
  $('#go').onclick = async () => {
    const pw = $('#cpw').value; if (!pw) return showErr('Enter your wallet password to confirm.');
    const btn = $('#go'); busy(btn, true);
    try {
      const r = await authed('send', { body: { w: S.wid, ...d, password: pw } });
      toast(r.msg); await loadMe(); S.tab = 'history'; viewMain();
    } catch (e) { busy(btn, false); showErr(e.message); }
  };
}

// ---------- 7. receive ----------
function viewRecv(coin = Object.keys(S.coins)[0]) {
  const w = wallet(), a = w.addresses[coin] || '';
  render(`<div class="top"><button class="ib" id="back">${I.back}</button><div class="title">Receive</div><span class="muted" style="font-size:11px">${h(w.name)}</span></div>
  <div class="scroll">
    <div class="coins">${Object.keys(S.coins).map((c) => `<button data-c="${c}" class="${c === coin ? 'on' : ''}">${c}‑x</button>`).join('')}</div>
    <div class="qr" id="qr"></div>
    <div class="addr">${h(a)}</div>
    <button class="btn btn-p btn-block" id="copy">Copy address</button>
    <p class="muted" style="font-size:11px;text-align:center;margin-top:10px">This address accepts only ${coin}‑x inside the ${h(S.me.site || '')} ledger.</p>
  </div>`);
  $('#back').onclick = viewMain;
  $$('.coins button').forEach((b) => (b.onclick = () => viewRecv(b.dataset.c)));
  try { const q = qrcode(0, 'M'); q.addData(a); q.make(); $('#qr').innerHTML = q.createSvgTag({ cellSize: 4, margin: 0, scalable: true }); } catch (e) {}
  $('#copy').onclick = () => navigator.clipboard.writeText(a).then(() => toast('Address copied'));
}

// ---------- 8. chart ----------
function viewChart(sym, range = '7d') {
  const p = S.prices[sym] || { usd: 0, ch: 0 };
  render(`<div class="top"><button class="ib" id="back">${I.back}</button>${coinIc(sym)}<div class="title">${h(S.coins[sym]?.name || sym)}‑x <span class="muted" style="font-weight:400;font-size:11px">/ USD</span></div></div>
  <div class="scroll">
    <div class="ch-price" id="now">${usd(p.usd)}</div>
    <div class="ch-d" id="dl">&nbsp;</div>
    <div class="tabs">${[['1d', '1D'], ['7d', '7D'], ['1m', '1M'], ['1y', '1Y']].map(([k, l]) => `<button data-r="${k}" class="${k === range ? 'on' : ''}">${l}</button>`).join('')}</div>
    <div class="ch-box"><canvas id="cv"></canvas><div class="ch-tip" id="tip"></div><div class="ch-load on" id="ld"><div class="spin"></div></div></div>
    <div class="stats"><div><small>Low</small><b id="mn">—</b></div><div><small>High</small><b id="mx">—</b></div><div><small>24h</small><b class="${p.ch >= 0 ? 'up' : 'down'}">${pct(p.ch)}</b></div></div>
    <div class="acts mt"><button class="btn btn-p" id="s">Send ${sym}‑x</button><button class="btn" id="r">Receive</button></div>
  </div>`);
  $('#back').onclick = viewMain;
  $('#s').onclick = () => viewSend({ coin: sym });
  $('#r').onclick = () => viewRecv(sym);
  $$('.tabs button').forEach((b) => (b.onclick = () => viewChart(sym, b.dataset.r)));
  api('chart', { params: { sym, range } }).then((d) => drawChart(d.points || [], range)).catch(() => ($('#ld').textContent = 'Couldn’t load the chart'));
}

function drawChart(pts, range) {
  const cv = $('#cv'); if (!cv) return;
  const ld = $('#ld');
  if (pts.length < 2) { ld.textContent = 'No data'; return; }
  ld.classList.remove('on');
  const css = (n) => getComputedStyle(document.documentElement).getPropertyValue(n).trim();
  const ys = pts.map((p) => p[1]), d = (ys[ys.length - 1] - ys[0]) / ys[0] * 100;
  $('#mn').textContent = usd(Math.min(...ys)); $('#mx').textContent = usd(Math.max(...ys));
  const dl = $('#dl'); dl.textContent = pct(d) + ' over ' + { '1d': 'the day', '7d': 'the week', '1m': 'the month', '1y': 'the year' }[range]; dl.className = 'ch-d ' + (d >= 0 ? 'up' : 'down');
  const fmtT = (ms) => { const x = new Date(ms); return range === '1d' ? x.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: false }) : x.toLocaleDateString('en-US', { day: '2-digit', month: 'short' }); };
  const ctx = cv.getContext('2d');
  const geo = () => { const W = cv.clientWidth, H = cv.clientHeight, pt = 10, pb = 18; let lo = Math.min(...ys), hi = Math.max(...ys); if (hi === lo) { hi += 1; lo -= 1; }
    const t0 = pts[0][0], t1 = pts[pts.length - 1][0];
    return { W, H, pb, x: (t) => 4 + (t - t0) / (t1 - t0 || 1) * (W - 8), y: (v) => pt + (1 - (v - lo) / (hi - lo)) * (H - pt - pb) }; };
  const draw = (hov) => {
    const dpr = devicePixelRatio || 1; cv.width = cv.clientWidth * dpr; cv.height = cv.clientHeight * dpr; ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    const g = geo(), col = css('--chart');
    ctx.strokeStyle = css('--chart-grid'); ctx.lineWidth = 1;
    for (let i = 0; i < 3; i++) { const yy = 10 + i * (g.H - 28) / 2; ctx.beginPath(); ctx.moveTo(0, yy); ctx.lineTo(g.W, yy); ctx.stroke(); }
    const line = () => { ctx.beginPath(); pts.forEach((p, i) => (i ? ctx.lineTo(g.x(p[0]), g.y(p[1])) : ctx.moveTo(g.x(p[0]), g.y(p[1])))); };
    line(); ctx.lineTo(g.x(pts[pts.length - 1][0]), g.H - g.pb); ctx.lineTo(g.x(pts[0][0]), g.H - g.pb); ctx.closePath();
    const f = ctx.createLinearGradient(0, 0, 0, g.H); f.addColorStop(0, css('--chart-fill')); f.addColorStop(1, 'rgba(0,0,0,0)'); ctx.fillStyle = f; ctx.fill();
    line(); ctx.strokeStyle = col; ctx.lineWidth = 1.6; ctx.lineJoin = 'round'; ctx.stroke();
    ctx.fillStyle = css('--chart-txt'); ctx.font = '10px ui-monospace, monospace';
    ctx.textAlign = 'left'; ctx.fillText(fmtT(pts[0][0]), 4, g.H - 4); ctx.textAlign = 'right'; ctx.fillText(fmtT(pts[pts.length - 1][0]), g.W - 4, g.H - 4);
    if (hov) { const hx = g.x(hov[0]), hy = g.y(hov[1]);
      ctx.strokeStyle = css('--chart-txt'); ctx.setLineDash([3, 3]); ctx.beginPath(); ctx.moveTo(hx, 8); ctx.lineTo(hx, g.H - g.pb); ctx.stroke(); ctx.setLineDash([]);
      ctx.beginPath(); ctx.arc(hx, hy, 4, 0, 7); ctx.fillStyle = css('--card'); ctx.fill(); ctx.lineWidth = 2; ctx.strokeStyle = col; ctx.stroke(); }
  };
  draw();
  const tip = $('#tip');
  cv.onmousemove = (e) => { const g = geo(), mx = e.clientX - cv.getBoundingClientRect().left;
    let best = pts[0], bd = 1e9; for (const p of pts) { const dd = Math.abs(g.x(p[0]) - mx); if (dd < bd) { bd = dd; best = p; } }
    draw(best); tip.innerHTML = `<b>${usd(best[1])}</b>${new Date(best[0]).toLocaleString('en-US', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })}`;
    tip.style.transform = `translate(${Math.min(Math.max(g.x(best[0]) - 55, 0), g.W - 110)}px,${Math.max(g.y(best[1]) - 46, 0)}px)`; tip.style.opacity = 1; };
  cv.onmouseleave = () => { tip.style.opacity = 0; draw(); };
}

// ---------- 9. settings ----------
async function viewSettings() {
  const st = (await local.get('settings')) || {};
  const dark = document.documentElement.dataset.theme === 'dark';
  render(`<div class="top"><button class="ib" id="back">${I.back}</button><div class="title">Settings</div></div>
  <div class="scroll">
    <div class="list">
      <div class="set"><span>Dark theme</span><label class="sw"><input type="checkbox" id="dark" ${dark ? 'checked' : ''}><span></span></label></div>
      <div class="set"><span>Notifications<br><small class="muted">incoming coins and price alerts</small></span><label class="sw"><input type="checkbox" id="notify" ${st.notify !== false ? 'checked' : ''}><span></span></label></div>
      <div class="set"><span>Auto-lock</span><select id="al">
        ${[[0, 'When the browser closes'], [15, 'After 15 minutes'], [60, 'After 1 hour'], [240, 'After 4 hours']].map(([v, l]) => `<option value="${v}" ${(st.autolock ?? 0) == v ? 'selected' : ''}>${l}</option>`).join('')}</select></div>
    </div>
    <div class="list mt">
      <div class="set"><span>Account<br><small class="muted">${h(S.me?.email || '')}</small></span></div>
      <div class="set"><span>Site<br><small class="muted">${h(new URL(S.site).host)}</small></span><button class="ib" id="open" title="Open site">${I.ext}</button></div>
    </div>
    <button class="btn btn-block mt" id="out">Log out of the wallet</button>
    <p class="muted" style="font-size:11px;text-align:center;margin-top:12px">${h(APP_NAME)} · version ${chrome.runtime.getManifest().version}</p>
  </div>`);
  $('#back').onclick = viewMain;
  $('#dark').onchange = (e) => { const t = e.target.checked ? 'dark' : 'light'; document.documentElement.dataset.theme = t; try { localStorage.setItem('theme', t); } catch (x) {} };
  $('#notify').onchange = async (e) => { st.notify = e.target.checked; await local.set('settings', st); };
  $('#al').onchange = async (e) => { st.autolock = +e.target.value; await local.set('settings', st); };
  $('#open').onclick = () => chrome.tabs.create({ url: S.site });
  $('#out').onclick = async () => { if (!confirm('Log out? To open the wallet again you’ll need your recovery phrase or the website login.')) return; await signOut(true); viewWelcome(); };
}

init();
