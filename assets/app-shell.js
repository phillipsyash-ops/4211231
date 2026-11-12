// Loyal — phone app shell: service worker, bottom tab bar, "More" sheet, pull-to-refresh,
// and the "Install the app" banner for phone browsers.
(() => {
  'use strict';
  const CFG = window.LOYAL_APP || {};
  const html = document.documentElement;
  const isApp = html.classList.contains('is-app');
  const page = CFG.page || '';
  const ls = { get: (k) => { try { return localStorage.getItem(k); } catch (e) { return null; } }, set: (k, v) => { try { localStorage.setItem(k, v); } catch (e) {} } };

  // service worker (needed for "Install" on Android and the offline screen)
  if ('serviceWorker' in navigator && location.protocol !== 'file:') {
    addEventListener('load', () => navigator.serviceWorker.register('sw.js').catch(() => {}));
  }

  // in the app the landing page isn't needed — straight to the wallet (or login)
  if (isApp && (page === 'index.php' || page === '')) {
    location.replace(CFG.user ? 'wallet.php' : 'unlock.php');
    return;
  }

  // home-screen shortcut "Send"
  if (/[?&]do=send\b/.test(location.search)) addEventListener('load', () => setTimeout(() => document.querySelector('[data-open="m-send"]')?.click(), 300));

  const I = {
    wallet: '<svg viewBox="0 0 24 24"><rect x="3" y="6" width="18" height="14" rx="3"/><path d="M3 10h18M16 15h2"/><path d="M6 6V5a2 2 0 0 1 2-2h9"/></svg>',
    swap: '<svg viewBox="0 0 24 24"><path d="M7 4 3 8l4 4M3 8h14M17 20l4-4-4-4M21 16H7"/></svg>',
    news: '<svg viewBox="0 0 24 24"><rect x="3" y="4" width="15" height="16" rx="2"/><path d="M18 8h3v10a2 2 0 0 1-2 2M7 8h7M7 12h7M7 16h4"/></svg>',
    alerts: '<svg viewBox="0 0 24 24"><path d="M6 16V11a6 6 0 1 1 12 0v5l1.5 2h-15z"/><path d="M10 20a2 2 0 0 0 4 0"/></svg>',
    more: '<svg viewBox="0 0 24 24"><circle cx="5" cy="12" r="1.3"/><circle cx="12" cy="12" r="1.3"/><circle cx="19" cy="12" r="1.3"/></svg>',
    stats: '<svg viewBox="0 0 24 24"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg>',
    contacts: '<svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0M16 4.5a3.5 3.5 0 0 1 0 7M18 14a5.5 5.5 0 0 1 3.5 6"/></svg>',
    settings: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg>',
    theme: '<svg viewBox="0 0 24 24"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>',
    out: '<svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>',
    share: '<svg viewBox="0 0 24 24"><path d="M12 3v12M8 7l4-4 4 4"/><path d="M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-7"/></svg>',
    plus: '<svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="4"/><path d="M12 8v8M8 12h8"/></svg>',
    refresh: '<svg viewBox="0 0 24 24"><path d="M20 11a8 8 0 1 0-2.3 5.7M20 4v7h-7"/></svg>',
  };
  // phones: several announcements fold into one line "+N more"
  if (matchMedia('(max-width: 760px)').matches) document.querySelectorAll('.anns').forEach((box) => {
    const n = box.querySelectorAll('.ann').length;
    if (n < 2) return;
    box.classList.add('folded');
    const b = document.createElement('button'); b.type = 'button'; b.className = 'anns-more';
    b.textContent = `+${n - 1} more notification${n > 2 ? 's' : ''}`;
    b.onclick = () => { box.classList.remove('folded'); b.remove(); };
    box.appendChild(b);
    new MutationObserver(() => { const left = box.querySelectorAll('.ann').length; if (left < 2) b.remove(); else b.textContent = `+${left - 1} more notification${left > 2 ? 's' : ''}`; }).observe(box, { childList: true });
  });

  const tabs = document.querySelector('.cab-tabs');
  const loggedIn = !!tabs;

  // ---------- bottom tab bar + "More" (inside the app, cabinet pages) ----------
  if (isApp && loggedIn) {
    const cur = (tabs.querySelector('a.on')?.getAttribute('href') || page);
    const link = (href) => tabs.querySelector(`a[href="${href}"]`);
    const main = [['wallet.php', 'Wallet', I.wallet], ['swap.php', 'Swap', I.swap], ['news.php', 'News', I.news], ['alerts.php', 'Alerts', I.alerts]]
      .filter(([h]) => link(h));
    const extra = [['stats.php', 'Insights', I.stats], ['contacts.php', 'Contacts', I.contacts], ['settings.php', 'Settings', I.settings]].filter(([h]) => link(h));
    const bar = document.createElement('nav');
    bar.className = 'app-bar';
    bar.style.gridTemplateColumns = `repeat(${main.length + 1}, 1fr)`;
    bar.innerHTML = main.map(([h, t, ic]) => `<a href="${h}" class="${cur === h ? 'on' : ''}">${ic}<span>${link(h)?.textContent || t}</span></a>`).join('')
      + `<button type="button" class="${extra.some(([h]) => h === cur) ? 'on' : ''}" data-more>${I.more}<span>More</span></button>`;
    document.body.appendChild(bar);
    // tapping the current tab again scrolls up / refreshes
    bar.querySelectorAll('a').forEach((a) => a.addEventListener('click', (e) => {
      if (a.classList.contains('on')) { e.preventDefault(); scrollY > 40 ? scrollTo({ top: 0, behavior: 'smooth' }) : location.reload(); }
    }));

    const more = document.createElement('div');
    more.className = 'app-more';
    more.innerHTML = `<div class="sheet"><div class="grab"></div>
      ${extra.map(([h, t, ic]) => `<a href="${h}">${ic}${link(h)?.textContent || t}</a>`).join('')}
      <hr><button type="button" class="row" data-theme-row>${I.theme}<span>Dark mode</span></button>
      <a href="logout.php" class="danger">${I.out}Log out</a></div>`;
    document.body.appendChild(more);
    const openMore = (on) => more.classList.toggle('open', on);
    bar.querySelector('[data-more]').onclick = () => openMore(true);
    more.addEventListener('click', (e) => { if (e.target === more) openMore(false); });
    const themeRow = more.querySelector('[data-theme-row] span');
    const paint = () => { themeRow.textContent = html.dataset.theme === 'dark' ? 'Light mode' : 'Dark mode'; };
    more.querySelector('[data-theme-row]').onclick = () => { document.querySelector('[data-theme-toggle]')?.click(); setTimeout(paint, 30); };
    paint();
    // swipe the sheet down to close
    let y0 = null; const sheet = more.querySelector('.sheet');
    sheet.addEventListener('touchstart', (e) => { y0 = e.touches[0].clientY; }, { passive: true });
    sheet.addEventListener('touchend', (e) => { if (y0 !== null && e.changedTouches[0].clientY - y0 > 60) openMore(false); y0 = null; });
  }

  // ---------- instant navigation in the app ----------
  // the site's desktop loading screen waits ~0.4 s before every page change; in the app we go right away
  if (isApp) {
    const prog = document.createElement('div'); prog.className = 'app-progress'; document.body.appendChild(prog);
    const start = () => { prog.style.opacity = 1; requestAnimationFrame(() => prog.classList.add('go')); };
    addEventListener('pageshow', () => { prog.classList.remove('go'); prog.style.opacity = 0; });
    document.addEventListener('click', (e) => {
      const a = e.target.closest('a[href]');
      if (!a || e.defaultPrevented || e.metaKey || e.ctrlKey || e.shiftKey || a.target === '_blank' || a.hasAttribute('download') || a.hasAttribute('onclick')) return;
      const href = a.getAttribute('href');
      if (!href || href.startsWith('#') || href.startsWith('javascript') || a.origin !== location.origin) return;
      if (a.closest('.app-bar') && a.classList.contains('on')) return;   // handled by the tab bar (scroll up / refresh)
      e.preventDefault();
      if (a.closest('.app-bar')) setTimeout(() => { a.parentNode.querySelectorAll('.on').forEach((x) => x.classList.remove('on')); a.classList.add('on'); }, 0);
      start();
      location.href = a.href;
    }, true);
    document.addEventListener('submit', (e) => { if (!e.defaultPrevented) start(); });
    // Android/Chrome: start loading a tab as soon as the finger touches it
    if (HTMLScriptElement.supports && HTMLScriptElement.supports('speculationrules')) {
      const sr = document.createElement('script'); sr.type = 'speculationrules';
      sr.textContent = JSON.stringify({ prerender: [{ source: 'document', where: { selector_matches: '.app-bar a, .app-more a:not(.danger)' }, eagerness: 'moderate' }] });
      document.body.appendChild(sr);
    }
  }

  // ---------- pull to refresh (iPhone apps have no reload button) ----------
  if (isApp) {
    const ptr = document.createElement('div');
    ptr.className = 'app-ptr'; ptr.innerHTML = I.refresh; document.body.appendChild(ptr);
    let y0 = null, d = 0;
    const blocked = () => document.querySelector('.modal.open, .app-more.open');
    addEventListener('touchstart', (e) => { y0 = (scrollY <= 0 && !blocked()) ? e.touches[0].clientY : null; d = 0; }, { passive: true });
    addEventListener('touchmove', (e) => {
      if (y0 === null) return;
      d = Math.max(0, Math.min(110, (e.touches[0].clientY - y0) * 0.55));
      ptr.style.opacity = d > 8 ? Math.min(1, d / 60) : 0;
      ptr.style.transform = `translateY(${d - 40}px) rotate(${d * 3}deg)`;
    }, { passive: true });
    addEventListener('touchend', () => {
      if (y0 === null) return;
      if (d >= 62) { ptr.classList.add('spin'); ptr.style.transform = 'translateY(14px)'; location.reload(); }
      else { ptr.style.opacity = 0; ptr.style.transform = 'translateY(-80px)'; }
      y0 = null;
    });
  }

  // =====================================================================
  //  QR scanner — "scan" button next to address fields (Send, Contacts)
  // =====================================================================
  const canScan = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia) && window.isSecureContext && (isApp || matchMedia('(pointer: coarse)').matches);
  const SCAN_IC = '<svg viewBox="0 0 24 24"><path d="M4 8V5a1 1 0 0 1 1-1h3M16 4h3a1 1 0 0 1 1 1v3M20 16v3a1 1 0 0 1-1 1h-3M8 20H5a1 1 0 0 1-1-1v-3"/><path d="M7 12h10"/></svg>';
  function parseScan(t) {
    t = String(t || '').trim();
    try { const u = new URL(t); const q = u.searchParams.get('send') || u.searchParams.get('to') || u.searchParams.get('address'); if (q) return q.trim(); } catch (e) {}
    const m = t.match(/^[a-z][a-z0-9+.-]*:(?!\/\/)([^?\s]+)/i);   // coin:ADDRESS?amount=…
    return m ? m[1] : t;
  }
  let jsqrLoading = null;
  const loadJsQR = () => jsqrLoading || (jsqrLoading = new Promise((ok, no) => {
    const s = document.createElement('script'); s.src = 'assets/app/jsQR.js'; s.onload = ok; s.onerror = no; document.head.appendChild(s);
  }));
  async function scanQR() {
    const ov = document.createElement('div');
    ov.className = 'app-scan';
    ov.innerHTML = `<video playsinline muted autoplay></video><div class="frame"><i></i><i></i><i></i><i></i><b></b></div>
      <div class="top"><button type="button" class="x" aria-label="Close">×</button></div>
      <div class="bot"><p>Point the camera at a ${CFG.name || 'Loyal'} QR code</p><button type="button" class="torch" hidden>Flashlight</button></div>`;
    document.body.appendChild(ov);
    const video = ov.querySelector('video'), msg = ov.querySelector('.bot p');
    let stream = null, done = false, raf = 0;
    const stop = () => { done = true; cancelAnimationFrame(raf); stream && stream.getTracks().forEach((t) => t.stop()); ov.remove(); };
    return new Promise(async (resolve) => {
      ov.querySelector('.x').onclick = () => { stop(); resolve(null); };
      try {
        stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } }, audio: false });
      } catch (e) {
        msg.innerHTML = 'No access to the camera.<br><small>Allow camera access for this site in your phone’s settings and try again.</small>';
        return;
      }
      video.srcObject = stream; await video.play().catch(() => {});
      const track = stream.getVideoTracks()[0];
      try { if (track.getCapabilities && track.getCapabilities().torch) { const tb = ov.querySelector('.torch'); let on = false; tb.hidden = false; tb.onclick = () => { on = !on; track.applyConstraints({ advanced: [{ torch: on }] }); tb.classList.toggle('on', on); }; } } catch (e) {}
      let detector = null;
      try { if ('BarcodeDetector' in window && (await BarcodeDetector.getSupportedFormats()).includes('qr_code')) detector = new BarcodeDetector({ formats: ['qr_code'] }); } catch (e) {}
      if (!detector) { try { await loadJsQR(); } catch (e) { msg.textContent = 'Couldn’t load the scanner. Check your connection.'; return; } }
      const cv = document.createElement('canvas'), cx = cv.getContext('2d', { willReadFrequently: true });
      let busy = false;
      const tick = async () => {
        if (done) return;
        raf = requestAnimationFrame(tick);
        if (busy || video.readyState < 2) return;
        busy = true;
        try {
          let text = null;
          if (detector) { const r = await detector.detect(video); if (r && r[0]) text = r[0].rawValue; }
          else {
            const w = video.videoWidth, h = video.videoHeight, k = Math.min(1, 720 / Math.max(w, h));
            cv.width = Math.round(w * k); cv.height = Math.round(h * k);
            cx.drawImage(video, 0, 0, cv.width, cv.height);
            const img = cx.getImageData(0, 0, cv.width, cv.height);
            const r = window.jsQR && window.jsQR(img.data, img.width, img.height, { inversionAttempts: 'dontInvert' });
            if (r && r.data) text = r.data;
          }
          if (text) { navigator.vibrate && navigator.vibrate(40); stop(); resolve(parseScan(text)); return; }
        } catch (e) {}
        busy = false;
      };
      tick();
    });
  }
  window.loyalScan = scanQR;
  if (canScan) ['s-to', 'c-addr'].forEach((id) => {
    const inp = document.getElementById(id); if (!inp) return;
    const wrap = document.createElement('div'); wrap.className = 'scan-wrap';
    inp.parentNode.insertBefore(wrap, inp); wrap.appendChild(inp);
    const b = document.createElement('button'); b.type = 'button'; b.className = 'scan-btn'; b.title = 'Scan QR code'; b.innerHTML = SCAN_IC;
    b.onclick = async () => {
      const v = await scanQR();
      if (!v) return;
      inp.value = v;
      inp.dispatchEvent(new Event('input', { bubbles: true }));
      if (inp.oninput) inp.oninput();
      document.getElementById('s-amt')?.focus();
    };
    wrap.appendChild(b);
  });

  // =====================================================================
  //  PIN code — locks the installed app on this phone
  // =====================================================================
  const PIN_K = 'loyal-pin', FAIL_K = 'loyal-pin-fail', HID_K = 'loyal-hidden-at', OK_K = 'loyal-unlocked', RELOCK = 60e3;
  const ss = { get: (k) => { try { return sessionStorage.getItem(k); } catch (e) { return null; } }, set: (k, v) => { try { sessionStorage.setItem(k, v); } catch (e) {} } };
  const pinData = () => { try { return JSON.parse(ls.get(PIN_K) || 'null'); } catch (e) { return null; } };
  const canPin = isApp && !!(window.crypto && crypto.subtle);
  const b64 = (buf) => btoa(String.fromCharCode(...new Uint8Array(buf)));
  async function pinHash(pin, salt) {
    const key = await crypto.subtle.importKey('raw', new TextEncoder().encode(pin), 'PBKDF2', false, ['deriveBits']);
    return b64(await crypto.subtle.deriveBits({ name: 'PBKDF2', hash: 'SHA-256', salt: Uint8Array.from(atob(salt), (c) => c.charCodeAt(0)), iterations: 150000 }, key, 256));
  }
  async function pinSave(pin) { const salt = b64(crypto.getRandomValues(new Uint8Array(16))); ls.set(PIN_K, JSON.stringify({ salt, hash: await pinHash(pin, salt), len: pin.length })); }
  async function pinCheck(pin) { const d = pinData(); return !!d && (await pinHash(pin, d.salt)) === d.hash; }
  const pinClear = () => { try { localStorage.removeItem(PIN_K); localStorage.removeItem(FAIL_K); } catch (e) {} };

  // keypad screen; mode: 'unlock' | 'verify' | 'set'
  function keypad({ mode, title, sub, len = 4, onDone, onCancel }) {
    const el = document.createElement('div');
    el.className = 'pin-lock';
    el.innerHTML = `<div class="pin-box"><img src="assets/logo/coin-128.png" alt=""><h2></h2><p class="sub"></p>
      <div class="pin-dots"></div><p class="pin-err"></p>
      <div class="pad">${[1, 2, 3, 4, 5, 6, 7, 8, 9].map((n) => `<button type="button" data-n="${n}">${n}</button>`).join('')}
        <button type="button" class="ghost" data-a="cancel">${mode === 'unlock' ? '' : 'Cancel'}</button><button type="button" data-n="0">0</button>
        <button type="button" class="ghost" data-a="del" aria-label="Delete">⌫</button></div>
      ${mode === 'unlock' ? '<button type="button" class="forgot">Forgot PIN? Log out</button>' : ''}
      ${mode === 'set' ? '<div class="len"><button type="button" data-len="4">4 digits</button><button type="button" data-len="6">6 digits</button></div>' : ''}</div>`;
    document.body.appendChild(el);
    const $t = el.querySelector('h2'), $s = el.querySelector('.sub'), $d = el.querySelector('.pin-dots'), $e = el.querySelector('.pin-err');
    let val = '', first = null, busy = false;
    const paintDots = () => { $d.innerHTML = Array.from({ length: len }, (_, i) => `<i class="${i < val.length ? 'on' : ''}"></i>`).join(''); };
    const setText = () => { $t.textContent = mode === 'set' ? (first ? 'Repeat the PIN' : title) : title; $s.textContent = mode === 'set' && first ? 'Enter the same PIN again' : sub; };
    const lenBox = el.querySelector('.len');
    const paintLen = () => lenBox && lenBox.querySelectorAll('button').forEach((b) => b.classList.toggle('on', +b.dataset.len === len));
    if (lenBox) lenBox.onclick = (e) => { const b = e.target.closest('button'); if (!b || first) return; len = +b.dataset.len; val = ''; paintDots(); paintLen(); };
    const shake = (msg) => { $e.textContent = msg; $d.classList.remove('shake'); void $d.offsetWidth; $d.classList.add('shake'); navigator.vibrate && navigator.vibrate([30, 40, 30]); val = ''; paintDots(); };
    const close = () => { el.classList.add('out'); setTimeout(() => el.remove(), 220); document.removeEventListener('keydown', onKey); };
    const failInfo = () => { try { return JSON.parse(ls.get(FAIL_K) || '{"n":0,"until":0}'); } catch (e) { return { n: 0, until: 0 }; } };
    async function complete() {
      if (mode === 'set') {
        if (!first) { first = val; val = ''; setText(); paintDots(); if (lenBox) lenBox.style.visibility = 'hidden'; return; }
        if (val !== first) { first = null; setText(); if (lenBox) lenBox.style.visibility = ''; return shake('PINs didn’t match — try again'); }
        busy = true; await pinSave(val); close(); onDone && onDone(); return;
      }
      const f = failInfo();
      if (f.until > Date.now()) return shake(`Too many tries. Wait ${Math.ceil((f.until - Date.now()) / 1000)} s`);
      busy = true; const ok = await pinCheck(val); busy = false;
      if (ok) { ls.set(FAIL_K, '{"n":0,"until":0}'); close(); onDone && onDone(); return; }
      f.n++; if (f.n >= 10) { pinClear(); location.href = 'logout.php'; return; }
      if (f.n % 5 === 0) f.until = Date.now() + 30e3;
      ls.set(FAIL_K, JSON.stringify(f));
      shake(f.n >= 5 ? `Wrong PIN · ${10 - f.n} tries left before log out` : 'Wrong PIN');
    }
    const press = (n) => { if (busy || val.length >= len) return; $e.textContent = ''; val += n; paintDots(); if (val.length === len) setTimeout(complete, 120); };
    const del = () => { val = val.slice(0, -1); paintDots(); };
    el.querySelector('.pad').addEventListener('click', (e) => {
      const b = e.target.closest('button'); if (!b) return;
      if (b.dataset.n !== undefined) press(b.dataset.n);
      else if (b.dataset.a === 'del') del();
      else if (b.dataset.a === 'cancel' && mode !== 'unlock') { close(); onCancel && onCancel(); }
    });
    const fg = el.querySelector('.forgot');
    if (fg) fg.onclick = () => { if (confirm('Log out of the app? You’ll log in again with your recovery phrase and password, then you can set a new PIN.')) { pinClear(); location.href = 'logout.php'; } };
    const onKey = (e) => { if (/^\d$/.test(e.key)) press(e.key); else if (e.key === 'Backspace') del(); };
    document.addEventListener('keydown', onKey);
    setText(); paintDots(); paintLen();
    return el;
  }

  if (canPin) {
    // logged out (or someone else will log in) — the old PIN goes away
    if (!CFG.user) { pinClear(); html.classList.remove('pin-locked'); }
    const lockNow = () => {
      if (!pinData() || document.querySelector('.pin-lock')) return;
      html.classList.add('pin-locked');
      keypad({ mode: 'unlock', title: 'Enter your PIN', sub: `Unlock ${CFG.name || 'Loyal'}`, len: pinData().len || 4,
        onDone: () => { ss.set(OK_K, '1'); html.classList.remove('pin-locked'); } });
    };
    if (CFG.user && pinData() && !ss.get(OK_K)) lockNow(); else html.classList.remove('pin-locked');
    document.addEventListener('visibilitychange', () => {
      if (document.hidden) { ls.set(HID_K, String(Date.now())); return; }
      const at = +(ls.get(HID_K) || 0);
      if (CFG.user && pinData() && at && Date.now() - at > RELOCK) { try { sessionStorage.removeItem(OK_K); } catch (e) {} lockNow(); }
    });

    // Settings → Security: App PIN
    if (page === 'settings.php' && CFG.user) {
      const sec = document.getElementById('security');
      if (sec) {
        const row = document.createElement('div');
        row.className = 'set-row app-pin';
        const paint = () => {
          const on = !!pinData();
          row.innerHTML = `<span><b>App PIN <span class="badge ${on ? 'okb' : ''}">${on ? 'on' : 'off'}</span></b><small class="muted">${on ? 'The app asks for your PIN when you open it and after a minute in the background.' : 'Lock the app on this phone with a 4- or 6-digit PIN. Asked when you open it and after a minute in the background.'}</small></span>
            <span class="inline" style="gap:6px">${on ? '<button type="button" class="btn btn-sm" data-p="change">Change</button><button type="button" class="btn btn-sm" data-p="off">Turn off</button>' : '<button type="button" class="btn btn-sm btn-p" data-p="set">Set PIN</button>'}</span>`;
        };
        const setNew = () => keypad({ mode: 'set', title: 'Create a PIN', sub: 'You’ll enter it each time you open the app', onDone: () => { ss.set(OK_K, '1'); paint(); } });
        row.addEventListener('click', (e) => {
          const b = e.target.closest('[data-p]'); if (!b) return;
          if (b.dataset.p === 'set') setNew();
          else keypad({ mode: 'verify', title: 'Enter your current PIN', sub: b.dataset.p === 'off' ? 'to turn the PIN off' : 'to change it', len: pinData().len || 4,
            onDone: () => { if (b.dataset.p === 'off') { pinClear(); paint(); } else setNew(); } });
        });
        paint();
        const h3 = sec.querySelector('.set-h');
        h3 ? h3.after(row) : sec.prepend(row);
      }
    }
  } else html.classList.remove('pin-locked');

  // ---------- "Install the app" (phone browser only) ----------
  if (isApp) return;
  const ua = navigator.userAgent;
  const isIOS = /iPhone|iPad|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  const isMobile = isIOS || /Android/i.test(ua);
  const iosSafari = isIOS && /Safari/.test(ua) && !/CriOS|FxiOS|EdgiOS|OPiOS|YaBrowser/.test(ua);
  let deferred = null;

  function iosGuide() {
    const el = document.createElement('div');
    el.className = 'app-ios';
    el.innerHTML = `<div class="sheet"><div class="grab" style="width:38px;height:5px;border-radius:3px;background:var(--line2);margin:0 auto 10px"></div>
      <h3>Install ${CFG.name || 'Loyal'} on your iPhone</h3>
      <p>${iosSafari ? 'Takes 10 seconds — the app icon appears on your Home Screen.' : 'Open this page in <b>Safari</b> first — only Safari can add apps to the Home Screen.'}</p>
      <ol><li><span>Tap <span class="ic">${I.share}</span> <b>Share</b> at the bottom of Safari</span></li>
          <li><span>Scroll down and tap <span class="ic">${I.plus}</span> <b>Add to Home Screen</b></span></li>
          <li><span>Tap <b>Add</b> — then open ${CFG.name || 'Loyal'} from your Home Screen</span></li></ol>
      <button class="btn btn-p btn-block btn-lg" type="button">Got it</button></div>${iosSafari ? '<div class="arrow">↓</div>' : ''}`;
    el.addEventListener('click', (e) => { if (e.target === el || e.target.closest('button')) el.remove(); });
    document.body.appendChild(el);
  }
  async function install() {
    if (deferred) { deferred.prompt(); const r = await deferred.userChoice.catch(() => null); deferred = null; if (r && r.outcome === 'accepted') banner?.remove(); return; }
    if (isIOS) return iosGuide();
    alert('Open your browser menu (⋮) and choose “Install app” or “Add to Home screen”.');
  }
  window.loyalInstall = install;
  addEventListener('beforeinstallprompt', (e) => { e.preventDefault(); deferred = e; showBanner(); });
  addEventListener('appinstalled', () => { banner?.remove(); ls.set('app-installed', '1'); });

  let banner = null;
  function showBanner() {
    if (banner || !isMobile || !loggedIn || ls.get('app-banner-x') > Date.now() || ls.get('app-installed')) return;
    banner = document.createElement('div');
    banner.className = 'app-install';
    banner.innerHTML = `<img src="assets/app/icon-96.png" alt=""><div class="t"><b>${CFG.name || 'Loyal'} app</b><small>Your wallet on the Home Screen</small></div>
      <button class="go" type="button">Install</button><button class="x" type="button" aria-label="Close">×</button>`;
    banner.querySelector('.go').onclick = install;
    banner.querySelector('.x').onclick = () => { banner.remove(); ls.set('app-banner-x', String(Date.now() + 7 * 864e5)); };
    document.body.appendChild(banner);
  }
  if (isIOS) setTimeout(showBanner, 1200);

  // Settings: a "Loyal app" row with an Install button (any device)
  if (page === 'settings.php') {
    const sec = document.getElementById('display');
    if (sec) {
      const row = document.createElement('div');
      row.className = 'set-row app-set';
      row.innerHTML = `<span><b>${CFG.name || 'Loyal'} app</b><small class="muted">Install the wallet on your phone’s Home Screen — opens full-screen like a regular app and stays logged in.</small></span>
        <button type="button" class="btn btn-sm btn-p">Install</button>`;
      row.querySelector('button').onclick = install;
      sec.appendChild(row);
    }
  }
})();
