// Background: website login, notifications, auto-lock
import { api, local, session } from './common.js';

const POLL = 'poll', LINK = 'link';

chrome.runtime.onInstalled.addListener(setupAlarms);
chrome.runtime.onStartup.addListener(setupAlarms);
function setupAlarms() {
  chrome.alarms.create(POLL, { periodInMinutes: 1 });
}

chrome.alarms.onAlarm.addListener(async (a) => {
  if (a.name === POLL) { await autoLock(); await pollEvents(); }
  if (a.name === LINK) await claimLink();
});

// ---------- log in via website ----------
chrome.runtime.onMessage.addListener((msg, _s, reply) => {
  if (msg?.type === 'link_start') { startLink().then(reply, (e) => reply({ error: e.message })); return true; }
  if (msg?.type === 'poll_now') { pollEvents().then(() => reply({ ok: true })); return true; }
});

async function startLink() {
  const site = await local.get('site');
  const r = await api('link_start', { site });
  const tab = await chrome.tabs.create({ url: `${site}/${r.path}` });
  await local.set('link', { status: 'waiting', code: r.code, tabId: tab.id, started: Date.now() });
  chrome.alarms.create(LINK, { periodInMinutes: 0.5 });
  return { ok: true };
}

// the confirm page reloaded after the click — check right away
chrome.tabs.onUpdated.addListener(async (tabId, info) => {
  if (info.status !== 'complete') return;
  const link = await local.get('link');
  if (link?.status === 'waiting' && link.tabId === tabId) claimLink();
});

async function claimLink() {
  const link = await local.get('link');
  if (!link || link.status !== 'waiting') { chrome.alarms.clear(LINK); return; }
  if (Date.now() - link.started > 10 * 60 * 1000) { await local.set('link', { status: 'expired' }); chrome.alarms.clear(LINK); return; }
  let r;
  try { r = await api('link_claim', { params: { code: link.code } }); } catch (e) { return; }
  if (r.status === 'pending') return;
  chrome.alarms.clear(LINK);
  if (r.status !== 'ok') { await local.set('link', { status: 'expired' }); return; }
  await session.set('pendingToken', r.token);
  await local.set('link', { status: 'ok' });
  setTimeout(() => chrome.tabs.remove(link.tabId).catch(() => {}), 1800);
  chrome.notifications.create('linked', { type: 'basic', iconUrl: 'icons/icon128.png', title: 'Extension connected', message: 'Open the extension and choose a password for it.' });
  try { await chrome.action.openPopup(); } catch (e) {}
}

// ---------- auto-lock ----------
async function autoLock() {
  const mins = (await local.get('settings'))?.autolock ?? 0; // 0 — only when the browser closes
  if (!mins) return;
  const last = await session.get('lastActive');
  if (last && Date.now() - last > mins * 60000) await session.del('token');
}

// ---------- notifications ----------
async function pollEvents() {
  const settings = (await local.get('settings')) || {};
  const token = await session.get('token');
  if (!token || settings.notify === false) return;
  let r;
  try { r = await api('events', { token, params: { since: (await local.get('lastTx')) ?? 0 } }); }
  catch (e) { if (e.status === 401) await session.del('token'); return; }

  const first = (await local.get('lastTx')) === undefined;
  const seenReq = new Set((await local.get('seenReq')) || []);
  if (!first) {
    for (const ev of r.events) {
      const title = ev.type === 'credit' ? `Credited ${ev.amount} ${ev.coin}‑x` : `Received ${ev.amount} ${ev.coin}‑x`;
      notify('tx' + ev.id, title, `Wallet “${ev.wallet}”` + (ev.note ? ` · ${ev.note}` : ''));
    }
    for (const q of r.requests) if (!seenReq.has(q.id)) {
      const what = q.type === 'deposit' ? 'deposit' : 'withdrawal';
      notify('rq' + q.id, q.status === 'approved' ? `Your ${what} request was approved` : `Your ${what} request was declined`,
        `${q.amount} ${q.coin}‑x` + (q.note ? ` · ${q.note}` : ''));
    }
  }
  const seenAl = new Set((await local.get('seenAlerts')) || []);
  for (const a of r.alerts || []) if (!seenAl.has(a.id)) {
    if (!first) notify('al' + a.id, '🔔 ' + a.text, 'Now ' + a.now);
    seenAl.add(a.id);
  }
  await local.set('seenAlerts', [...seenAl].slice(-200));
  r.requests.forEach((q) => seenReq.add(q.id));
  await local.set('seenReq', [...seenReq].slice(-200));
  await local.set('lastTx', r.last);
  if (!first && r.events.length) {
    const n = ((await session.get('unread')) || 0) + r.events.length;
    await session.set('unread', n);
    chrome.action.setBadgeText({ text: String(n) });
    chrome.action.setBadgeBackgroundColor({ color: '#121212' });
  }
}

function notify(id, title, message) {
  chrome.notifications.create(id, { type: 'basic', iconUrl: 'icons/icon128.png', title, message, priority: 1 });
}
chrome.notifications.onClicked.addListener(async (id) => {
  chrome.notifications.clear(id);
  try { await chrome.action.openPopup(); } catch (e) {}
});
