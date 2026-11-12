// «Сборка сайта»: команда курсоров на глазах собирает страницу —
// растягивает рамки, печатает текст, расчерчивает сетки, выделяет и меняет значения.
(() => {
  'use strict';
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

  // какие элементы и как строить; порядок важен — внешние блоки раньше внутренних
  const PLAN = [
    ['.nav .logo', 'draw'],
    ['.nav-links', 'type'],
    ['.nav-cta', 'table', { cells: ':scope > *' }],
    ['.hero .pill', 'draw'],
    ['.hero h1', 'type', { frame: true }],
    ['.hero .lead', 'type', { frame: true }],
    ['.hero-cta', 'table', { cells: '.btn' }],
    ['.hero-stats', 'table', { cells: ':scope > div' }],
    ['.coin-stage .stage-tag', 'pop'],
    ['.orbit', 'pop'],
    ['.ticker', 'grow'],
    ['.coins-grid', 'table', { cells: '.coin-card', sub: [['.price', 'swap']] }],
    ['.chain', 'draw'],
    ['.chain-list', 'table', { cells: 'li' }],
    ['.steps', 'table', { cells: '.step', sub: [['h3', 'type'], ['p', 'type']] }],
    ['.cta-box', 'draw', { children: ':scope > *' }],
    ['.eyebrow', 'type'],
    ['h2', 'type', { frame: true }],
    ['.sec-head p, .chain-wrap > div > p', 'type'],
    ['.foot > div', 'type'],
  ];
  const TEAM = [['Дизайнер', '#ff5c39'], ['Верстальщик', '#7b61ff'], ['Копирайтер', '#0ea5e9'], ['Разработчик', '#10b981'], ['Арт‑директор', '#f43f5e']];

  const R = document.documentElement;
  R.classList.add('vb-on');
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  const clamp = (v, a, b) => Math.max(a, Math.min(b, v));
  let speed = 1, skipping = false, started = false, finished = false;
  const T = (ms) => (skipping ? 0 : ms * speed);
  const wait = (ms) => (skipping ? Promise.resolve() : sleep(T(ms)));
  const prect = (el) => { const r = el.getBoundingClientRect(); return { x: r.left + scrollX, y: r.top + scrollY, w: r.width, h: r.height }; };
  const EASE = 'cubic-bezier(.45,.05,.25,1)';

  const layer = document.createElement('div'); layer.className = 'vb-layer'; document.body.appendChild(layer);
  const mk = (cls, color, parent = layer) => { const d = document.createElement('div'); d.className = cls; if (color) d.style.setProperty('--c', color); parent.appendChild(d); return d; };
  const place = (el, r) => Object.assign(el.style, { left: r.x + 'px', top: r.y + 'px', width: r.w + 'px', height: r.h + 'px' });
  const fadeOut = (el, delay = 0) => setTimeout(() => { if (!el.isConnected) return; el.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 320 }).finished.then(() => el.remove()); }, skipping ? 0 : delay);

  // ---------- курсоры ----------
  const ARROW = '<svg class="arrow" viewBox="0 0 22 22"><path d="M3 2 L3 18 L7.4 13.9 L10.3 20.3 L13.2 19 L10.4 12.7 L16.4 12.4 Z" fill="var(--c)" stroke="#fff" stroke-width="1.4" stroke-linejoin="round"/></svg>';
  const CROSS = '<svg class="cross" viewBox="0 0 22 22"><path d="M11 1v20M1 11h20" stroke="#fff" stroke-width="4"/><path d="M11 1v20M1 11h20" stroke="var(--c)" stroke-width="1.6"/></svg>';

  class Cursor {
    constructor(name, color) {
      this.color = color; this.busy = false;
      this.el = mk('vb-cur', color);
      this.el.innerHTML = ARROW + CROSS;
      this.el.style.opacity = 0;
      this.place(scrollX + innerWidth * (0.1 + 0.8 * Math.random()), scrollY + innerHeight * (0.35 + 0.55 * Math.random()));
    }
    place(x, y) { this.x = x; this.y = y; this.el.style.transform = `translate(${x}px,${y}px)`; }
    show(v = true) { this.el.style.opacity = v ? 1 : 0; }
    mode(cross) { this.el.classList.toggle('cross', !!cross); }
    async move(x, y, raw) {
      this.show();
      const d = Math.hypot(x - this.x, y - this.y);
      const dur = T(raw ?? clamp(240 + d * 0.5, 240, 900));
      const from = `translate(${this.x}px,${this.y}px)`;
      this.place(x, y);
      if (!dur) return;
      this.anim = this.el.animate([{ transform: from }, { transform: `translate(${x}px,${y}px)` }], { duration: dur, easing: EASE });
      await this.anim.finished.catch(() => {});
    }
    click() {
      if (skipping) return;
      const r = mk('vb-ripple', this.color); r.style.left = this.x + 'px'; r.style.top = this.y + 'px';
      r.animate([{ transform: 'scale(.3)', opacity: 1 }, { transform: 'scale(1.5)', opacity: 0 }], { duration: 480, easing: 'ease-out' }).finished.then(() => r.remove());
      this.el.animate([{ scale: 1 }, { scale: 0.82 }, { scale: 1 }], { duration: 200 });
    }
  }
  const team = TEAM.slice(0, innerWidth < 700 ? 3 : 5).map(([n, c]) => new Cursor(n, c));

  // ---------- подготовка: прячем то, что будет «построено» ----------
  const textEls = new Set(), swapEls = new Set();
  function prepText(el) {
    if (el._vbt) return;
    const list = [], tw = document.createTreeWalker(el, NodeFilter.SHOW_TEXT, { acceptNode: (n) => (n.data.trim() ? 1 : 2) });
    while (tw.nextNode()) list.push(tw.currentNode);
    el._vbt = list.map((t) => {
      const w = document.createElement('span'), v = document.createElement('span'), hd = document.createElement('span');
      hd.className = 'vb-h'; hd.textContent = t.data; w.append(v, hd); t.replaceWith(w);
      return { w, v, hd, full: t.data };
    });
    textEls.add(el);
  }
  function unwrapText(el) { (el._vbt || []).forEach((p) => p.w.isConnected && p.w.replaceWith(p.full)); el._vbt = null; }
  function prepSwap(el) { el._vbs = el.textContent; el.textContent = /\$/.test(el._vbs) ? '$0.00' : '—'; swapEls.add(el); }
  function hide(el) { el.classList.add('vb-hide'); }
  function prep(el, kind, opt) {
    if (kind === 'type') prepText(el);
    else if (kind === 'swap') prepSwap(el);
    else hide(el);
    if (opt.children) el.querySelectorAll(opt.children).forEach((k) => { hide(k); left++; });
    if (opt.cells) el.querySelectorAll(opt.cells).forEach((c) => {
      hide(c);
      (opt.sub || []).forEach(([s, k]) => c.querySelectorAll(s).forEach((x) => { prep(x, k, {}); left++; }));
    });
    if (kind === 'table') el.classList.remove('vb-hide'); // контейнер сетки виден, прячутся ячейки
  }

  // ---------- действия ----------
  function reveal(el, cls = 'vb-in') {
    el.classList.remove('vb-hide');
    if (!skipping) { el.classList.add(cls); setTimeout(() => el.classList.remove(cls), 650); }
    el.querySelectorAll('[data-count]').forEach(countUp);
  }
  function countUp(el) {
    const end = parseFloat(el.dataset.count), dec = +(el.dataset.dec || 0);
    const f = (v) => v.toLocaleString('ru-RU', { minimumFractionDigits: dec, maximumFractionDigits: dec });
    if (skipping) { el.textContent = f(end); return; }
    let t0 = null;
    const step = (t) => { t0 ??= t; const k = Math.min(1, (t - t0) / 900); el.textContent = f(end * (1 - Math.pow(1 - k, 3))); if (k < 1) requestAnimationFrame(step); };
    requestAnimationFrame(step);
  }

  async function marquee(cur, r) {
    await cur.move(r.x, r.y);
    cur.mode(true); cur.click();
    const box = mk('vb-box', cur.color); place(box, { ...r, w: 0, h: 0 });
    const sz = mk('vb-size', null, box);
    const raw = clamp(320 + Math.hypot(r.w, r.h) * 0.42, 320, 950), dur = T(raw);
    if (dur) {
      box.animate([{ width: '0px', height: '0px' }, { width: r.w + 'px', height: r.h + 'px' }], { duration: dur, easing: EASE });
      sizeLoop(box, sz, dur);
      await cur.move(r.x + r.w, r.y + r.h, raw);
    }
    place(box, r); sz.textContent = `${Math.round(r.w)} × ${Math.round(r.h)}`;
    ['tl', 'tm', 'tr', 'ml', 'mr', 'bl', 'bm', 'br'].forEach((p) => { const i = document.createElement('i'); i.className = 'h-' + p; box.appendChild(i); });
    cur.mode(false);
    return box;
  }
  function sizeLoop(box, sz, dur) {
    const t0 = performance.now();
    const loop = () => { const b = box.getBoundingClientRect(); sz.textContent = `${Math.round(b.width)} × ${Math.round(b.height)}`; if (performance.now() - t0 < dur) requestAnimationFrame(loop); };
    loop();
  }

  async function doDraw(el, cur, opt) {
    const box = await marquee(cur, prect(el));
    reveal(el);
    await wait(260);
    fadeOut(box);
    if (opt.children) [...el.querySelectorAll(opt.children)].forEach((k) => enqueue({ el: k, kind: 'pop', opt: {} }));
  }

  async function doPop(el, cur) {
    const r = prect(el);
    await cur.move(r.x + Math.min(r.w * 0.5, 70), r.y + Math.min(r.h * 0.55, 30));
    cur.click(); reveal(el, 'vb-pop');
    await wait(140);
  }

  async function doTable(el, cur, opt) {
    const box = await marquee(cur, prect(el));
    const cells = [...el.querySelectorAll(opt.cells)];
    const outlines = cells.map((c, i) => {
      const o = mk('vb-cell', cur.color); place(o, prect(c));
      if (!skipping) o.animate([{ opacity: 0, transform: 'scale(.95)' }, { opacity: 1, transform: 'none' }], { duration: 240, delay: i * 50, fill: 'backwards' });
      return o;
    });
    await wait(180 + cells.length * 50);
    fadeOut(box, 80);
    for (let i = 0; i < cells.length; i++) {
      if (skipping) break;
      const c = cells[i], r = prect(c);
      await cur.move(r.x + Math.min(r.w * 0.35, 80), r.y + Math.min(r.h * 0.45, 36), 280);
      cur.click(); reveal(c, 'vb-pop'); fadeOut(outlines[i]);
      (opt.sub || []).forEach(([s, k]) => c.querySelectorAll(s).forEach((x) => enqueue({ el: x, kind: k, opt: {} })));
      await wait(70);
    }
    outlines.forEach((o) => o.remove());
  }

  async function doType(el, cur, opt) {
    const parts = el._vbt; if (!parts) return;
    const total = parts.reduce((s, p) => s + p.full.length, 0) || 1;
    const r = prect(el);
    let fr = null;
    if (opt.frame && !skipping) { fr = mk('vb-frame', cur.color); place(fr, r); fr.innerHTML = `<b>${el.tagName.toLowerCase()} · text</b>`; }
    await cur.move(r.x - 2, r.y + Math.min(r.h, 44) * 0.6);
    cur.click();
    const caret = document.createElement('span'); caret.className = 'vb-caret'; caret.style.setProperty('--c', cur.color);
    const per = clamp(1700 / total, 4, 34) * speed;
    const chunk = per < 16 ? Math.ceil(16 / per) : 1, delay = Math.max(per, 16);
    for (const p of parts) {
      if (skipping) break;
      p.w.insertBefore(caret, p.hd);
      for (let i = 0; i <= p.full.length; i += chunk) {
        if (skipping) break;
        p.v.textContent = p.full.slice(0, i); p.hd.textContent = p.full.slice(i);
        const c = caret.getBoundingClientRect();
        cur.place(c.left + scrollX + 3, c.top + scrollY + c.height * 0.8);
        await sleep(delay);
      }
      p.v.textContent = p.full; p.hd.textContent = '';
    }
    caret.remove();
    unwrapText(el);
    if (fr) fadeOut(fr, 350);
  }

  async function doSwap(el, cur) {
    const r = prect(el);
    await cur.move(r.x + r.w - 4, r.y + r.h * 0.6);
    cur.click(); await wait(110); cur.click();
    if (skipping || el._vbs == null) return;
    el.innerHTML = `<span class="vb-sel" style="--c:${cur.color}">${el.textContent}</span>`;
    await wait(420);
    if (skipping || el._vbs == null) return;
    el.textContent = el._vbs; el._vbs = null;
    prepText(el);
    await doType(el, cur, {});
  }

  async function doGrow(el, cur) {
    const r = prect(el), part = { ...r, w: Math.max(160, r.w * 0.32) };
    const box = await marquee(cur, part);
    el.classList.remove('vb-hide');
    const cut = r.w - part.w;
    el.style.clipPath = `inset(0 ${cut}px 0 0)`;
    await cur.move(r.x + part.w, r.y + r.h / 2, 280);
    cur.click();
    const raw = 1000, dur = T(raw);
    if (dur) {
      el.animate([{ clipPath: `inset(0 ${cut}px 0 0)` }, { clipPath: 'inset(0 0px 0 0)' }], { duration: dur, easing: EASE });
      box.animate([{ width: part.w + 'px' }, { width: r.w + 'px' }], { duration: dur, easing: EASE });
      sizeLoop(box, box.querySelector('.vb-size'), dur);
      await cur.move(r.x + r.w - 8, r.y + r.h / 2, raw);
    }
    el.style.clipPath = '';
    place(box, r); box.querySelector('.vb-size').textContent = `${Math.round(r.w)} × ${Math.round(r.h)}`;
    await wait(260);
    fadeOut(box);
  }

  const KIND = { draw: doDraw, pop: doPop, table: doTable, type: doType, swap: doSwap, grow: doGrow };

  // ---------- очередь задач и распределение по курсорам ----------
  const queue = [];
  let active = 0, left = 0, idleT = null;
  function enqueue(t) { if (t.el._vbQ) return; t.el._vbQ = 1; queue.push(t); pump(); }
  function pump() {
    if (!started || skipping) return;
    speed = queue.length > team.length * 2 ? 0.45 : queue.length > team.length ? 0.7 : 1;
    for (const c of team) {
      if (c.busy || !queue.length) continue;
      queue.sort((a, b) => prect(a.el).y - prect(b.el).y);
      // из трёх верхних задач берём ближайшую к курсору — так они работают рядом, но не толкаются
      let best = 0, bd = Infinity;
      queue.slice(0, 3).forEach((t, i) => { const r = prect(t.el); const d = Math.hypot(r.x - c.x, r.y - c.y) + i * 220; if (d < bd) { bd = d; best = i; } });
      run(c, queue.splice(best, 1)[0]);
    }
  }
  async function run(c, t) {
    c.busy = true; active++; clearTimeout(idleT);
    try { await KIND[t.kind](t.el, c, t.opt || {}); }
    catch (e) { t.el.classList.remove('vb-hide'); unwrapText(t.el); }
    c.busy = false; active--; left--;
    if (left <= 0) done();
    else if (!queue.length && !active) idleT = setTimeout(rest, 1800);
    pump();
  }
  function rest() { if (queue.length || active) return; team.forEach((c, i) => { c.move(c.x + 30 + i * 8, c.y + 50, 700); c.show(false); }); }

  // ---------- регистрация ----------
  const claimed = new Set();
  const isClaimed = (el) => { for (let p = el; p; p = p.parentElement) if (claimed.has(p)) return true; return false; };
  const io = new IntersectionObserver((es) => es.forEach((e) => {
    if (!e.isIntersecting) return;
    io.unobserve(e.target); enqueue(e.target._vbTask);
  }), { rootMargin: '0px 0px -12% 0px' });

  for (const [sel, kind, opt = {}] of PLAN) {
    document.querySelectorAll(sel).forEach((el) => {
      if (isClaimed(el) || !el.offsetParent && getComputedStyle(el).position !== 'fixed') return;
      claimed.add(el); left++;
      prep(el, kind, opt);
      el._vbTask = { el, kind, opt };
      io.observe(el);
    });
  }

  // ---------- кнопка «пропустить» / «собрать заново» ----------
  const btn = document.createElement('button');
  btn.type = 'button'; btn.className = 'vb-skip'; btn.innerHTML = '<i></i><span>Skip the build</span>';
  document.body.appendChild(btn);
  btn.addEventListener('click', () => {
    if (finished) { try { sessionStorage.setItem('vb-top', '1'); } catch (e) {} location.reload(); return; }
    skipAll();
  });
  try { if (sessionStorage.getItem('vb-top')) { sessionStorage.removeItem('vb-top'); history.scrollRestoration = 'manual'; scrollTo(0, 0); } } catch (e) {}

  function done() {
    if (finished) return; finished = true;
    btn.classList.add('done'); btn.querySelector('span').textContent = '↻ Build again';
    setTimeout(rest, skipping ? 0 : 1500);
  }
  function skipAll() {
    skipping = true; queue.length = 0; io.disconnect();
    team.forEach((c) => { c.anim?.finish?.(); c.show(false); c.mode(false); });
    document.querySelectorAll('.vb-hide').forEach((e) => e.classList.remove('vb-hide'));
    textEls.forEach(unwrapText);
    document.querySelectorAll('.vb-caret').forEach((c) => c.remove());
    swapEls.forEach((el) => { if (el._vbs != null) { el.textContent = el._vbs; el._vbs = null; } });
    document.querySelectorAll('[data-count]').forEach(countUp);
    layer.querySelectorAll('.vb-box,.vb-frame,.vb-cell,.vb-ripple').forEach((x) => x.remove());
    done();
  }

  // ---------- старт после экрана загрузки ----------
  const go = () => {
    if (!document.body.classList.contains('ready')) return setTimeout(go, 80);
    setTimeout(() => { started = true; team.forEach((c, i) => setTimeout(() => c.show(), i * 140)); pump(); }, 300);
  };
  go();
})();
