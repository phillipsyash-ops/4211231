(() => {
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const loader = $('#loader');

  // ---- loading screen: hide after render, show on navigation ----
  const hide = () => setTimeout(() => { loader && loader.classList.remove('show'); document.body.classList.add('ready'); }, 450);
  if (document.readyState === 'complete') hide(); else window.addEventListener('load', hide);
  window.addEventListener('pageshow', e => { if (e.persisted) hide(); });
  const show = () => loader && loader.classList.add('show');

  document.addEventListener('click', e => {
    const a = e.target.closest('a[href]');
    if (!a || e.defaultPrevented || e.metaKey || e.ctrlKey || e.shiftKey || a.target === '_blank') return;
    const href = a.getAttribute('href');
    if (!href || href.startsWith('#') || href.startsWith('javascript') || a.hasAttribute('download') || a.dataset.noload !== undefined) return;
    if (a.origin !== location.origin) return;
    e.preventDefault(); show(); setTimeout(() => location.href = a.href, 380);
  });
  document.addEventListener('submit', e => { if (!e.defaultPrevented && !e.target.dataset.noload) show(); });

  // ---- light / dark theme ----
  const applyTheme = t => {
    document.documentElement.dataset.theme = t;
    document.querySelector('meta[name=theme-color]')?.setAttribute('content', t === 'dark' ? '#0b0b0c' : '#f6f6f4');
    try { localStorage.setItem('theme', t); } catch (e) {}
    window.dispatchEvent(new Event('themechange'));
  };
  if (document.documentElement.dataset.theme === 'dark') document.querySelector('meta[name=theme-color]')?.setAttribute('content', '#0b0b0c');
  document.addEventListener('click', e => {
    if (!e.target.closest('[data-theme-toggle]')) return;
    document.documentElement.classList.add('theming');
    applyTheme(document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark');
    setTimeout(() => document.documentElement.classList.remove('theming'), 350);
  });

  // ---- reveal on scroll ----
  const io = new IntersectionObserver(es => es.forEach(x => { if (x.isIntersecting) { x.target.classList.add('in'); io.unobserve(x.target); } }), { threshold: .12 });
  $$('.rv').forEach(el => io.observe(el));

  // ---- card glow under the cursor ----
  $$('.coin-card').forEach(c => c.addEventListener('mousemove', e => {
    const r = c.getBoundingClientRect();
    c.style.setProperty('--mx', (e.clientX - r.left) + 'px'); c.style.setProperty('--my', (e.clientY - r.top) + 'px');
  }));

  // ---- counters ----
  $$('[data-count]').forEach(el => {
    const end = parseFloat(el.dataset.count), dec = +(el.dataset.dec || 0), pre = el.dataset.pre || '', suf = el.dataset.suf || '';
    let t0 = null;
    const step = t => { t0 ??= t; const k = Math.min(1, (t - t0) / 1400), v = end * (1 - Math.pow(1 - k, 3));
      el.textContent = pre + v.toLocaleString('en-US', { minimumFractionDigits: dec, maximumFractionDigits: dec }) + suf;
      if (k < 1) requestAnimationFrame(step); };
    new IntersectionObserver((es, o) => { if (es[0].isIntersecting) { requestAnimationFrame(step); o.disconnect(); } }).observe(el);
  });

  // ---- copy ----
  const toast = txt => { const d = document.createElement('div'); d.className = 'copied'; d.textContent = txt; document.body.append(d); setTimeout(() => d.remove(), 1900); };
  document.addEventListener('click', e => {
    const b = e.target.closest('[data-copy]'); if (!b) return;
    const v = b.dataset.copy || $(b.dataset.copyFrom)?.textContent.trim();
    navigator.clipboard?.writeText(v).then(() => toast('Copied'), () => toast('Couldn’t copy'));
  });

  // ---- modals ----
  document.addEventListener('click', e => {
    const o = e.target.closest('[data-open]');
    if (o) { const m = $('#' + o.dataset.open); m?.classList.add('open'); m?.dispatchEvent(new CustomEvent('opened', { detail: o.dataset })); return; }
    if (e.target.closest('[data-close]') || e.target.classList.contains('modal')) e.target.closest('.modal')?.classList.remove('open');
  });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') $$('.modal.open').forEach(m => m.classList.remove('open')); });

  // ---- password strength ----
  $$('[data-pw]').forEach(inp => {
    const bar = $(inp.dataset.pw);
    inp.addEventListener('input', () => {
      const v = inp.value; let s = 0;
      if (v.length >= 8) s++; if (v.length >= 12) s++; if (/[A-Z]/.test(v) && /[a-z]/.test(v)) s++; if (/\d/.test(v)) s++; if (/[^\w]/.test(v)) s++;
      bar.style.width = (s / 5 * 100) + '%';
    });
  });

  // ---- hide an announcement ----
  document.addEventListener('click', e => {
    const b = e.target.closest('[data-dismiss]'); if (!b) return;
    const box = b.closest('.ann'); box.style.transition = '.25s'; box.style.opacity = 0; box.style.transform = 'translateY(-6px)';
    setTimeout(() => box.remove(), 250);
    const fd = new FormData(); fd.append('csrf', window.CSRF || '');
    fetch('api.php?dismiss=' + b.dataset.dismiss, { method: 'POST', body: fd }).catch(() => {});
  });

  // ---- reveal the recovery phrase ----
  $$('.seed-cover').forEach(c => c.addEventListener('click', () => c.parentElement.classList.remove('blur')));

  // ---- live prices without reload ----
  const priced = $$('[data-price]');
  if (priced.length) {
    const fmt = v => '$' + v.toLocaleString('en-US', { minimumFractionDigits: v >= 1 ? 2 : 4, maximumFractionDigits: v >= 1 ? 2 : 6 });
    const refresh = () => fetch('api.php?prices=1').then(r => r.json()).then(p => {
      priced.forEach(el => { const x = p[el.dataset.price]; if (!x) return;
        const units = parseFloat(el.dataset.units || '1'); const v = x.usd * units;
        if (el.textContent !== fmt(v)) { el.textContent = fmt(v); el.animate([{ opacity: .35 }, { opacity: 1 }], 600); } });
      $$('[data-ch]').forEach(el => { const x = p[el.dataset.ch]; if (!x) return;
        el.textContent = (x.ch >= 0 ? '+' : '') + x.ch.toFixed(2) + '%'; el.className = el.className.replace(/\b(up|down)\b/g, '') + (x.ch >= 0 ? ' up' : ' down'); });
      const tot = $('[data-total]');
      if (tot) { let s = 0; $$('[data-price][data-units]').forEach(el => { s += (p[el.dataset.price]?.usd || 0) * parseFloat(el.dataset.units); }); tot.textContent = fmt(s); }
    }).catch(() => {});
    setInterval(refresh, 15000);
  }

  // ---- price chart ----
  const M = $('#m-chart');
  if (M) {
    const cv = $('#ch-cv'), ctx = cv.getContext('2d'), tip = $('#ch-tip'), load = $('#ch-load');
    let sym = null, range = '7d', pts = [], color = '#121212', liveT = null, req = 0;
    const css = n => getComputedStyle(document.documentElement).getPropertyValue(n).trim();
    const usd = v => '$' + v.toLocaleString('en-US', { minimumFractionDigits: v >= 100 ? 2 : v >= 1 ? 2 : 4, maximumFractionDigits: v >= 100 ? 2 : v >= 1 ? 4 : 6 });
    const pct = v => (v >= 0 ? '+' : '') + v.toFixed(2) + '%';
    const dt = ms => { const d = new Date(ms); return range === '1d' ? d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: false })
      : d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', ...(range === '1y' ? { year: '2-digit' } : {}) }) + (range === '7d' ? ' ' + d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: false }) : ''); };

    const geom = () => {
      const W = cv.clientWidth, H = cv.clientHeight, pad = { l: 8, r: 8, t: 16, b: 22 };
      const ys = pts.map(p => p[1]); let lo = Math.min(...ys), hi = Math.max(...ys);
      if (hi === lo) { hi += hi * .001 || 1; lo -= lo * .001 || 1; }
      const t0 = pts[0][0], t1 = pts[pts.length - 1][0];
      return { W, H, pad, lo, hi, x: t => pad.l + (t - t0) / Math.max(1, t1 - t0) * (W - pad.l - pad.r), y: v => pad.t + (1 - (v - lo) / (hi - lo)) * (H - pad.t - pad.b) };
    };
    const draw = (hover = null) => {
      const dpr = window.devicePixelRatio || 1;
      cv.width = cv.clientWidth * dpr; cv.height = cv.clientHeight * dpr; ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      ctx.clearRect(0, 0, cv.width, cv.height);
      if (pts.length < 2) return;
      const g = geom();
      // grid
      color = css('--chart'); ctx.strokeStyle = css('--chart-grid'); ctx.lineWidth = 1;
      for (let i = 0; i <= 3; i++) { const yy = g.pad.t + i * (g.H - g.pad.t - g.pad.b) / 3; ctx.beginPath(); ctx.moveTo(0, yy); ctx.lineTo(g.W, yy); ctx.stroke(); }
      // fill
      const fill = ctx.createLinearGradient(0, g.pad.t, 0, g.H);
      fill.addColorStop(0, css('--chart-fill')); fill.addColorStop(1, 'rgba(0,0,0,0)');
      ctx.beginPath(); pts.forEach((p, i) => i ? ctx.lineTo(g.x(p[0]), g.y(p[1])) : ctx.moveTo(g.x(p[0]), g.y(p[1])));
      ctx.lineTo(g.x(pts[pts.length - 1][0]), g.H - g.pad.b); ctx.lineTo(g.x(pts[0][0]), g.H - g.pad.b); ctx.closePath(); ctx.fillStyle = fill; ctx.fill();
      // line
      ctx.beginPath(); pts.forEach((p, i) => i ? ctx.lineTo(g.x(p[0]), g.y(p[1])) : ctx.moveTo(g.x(p[0]), g.y(p[1])));
      ctx.strokeStyle = color; ctx.lineWidth = 1.8; ctx.lineJoin = 'round'; ctx.stroke();
      // date labels
      ctx.fillStyle = css('--chart-txt'); ctx.font = '11px JetBrains Mono, monospace';
      [0, .5, 1].forEach(k => { const p = pts[Math.round(k * (pts.length - 1))]; ctx.textAlign = k === 0 ? 'left' : k === 1 ? 'right' : 'center'; ctx.fillText(dt(p[0]), g.x(p[0]), g.H - 5); });
      // last point
      const last = pts[pts.length - 1];
      ctx.beginPath(); ctx.arc(g.x(last[0]), g.y(last[1]), 4, 0, 7); ctx.fillStyle = color; ctx.fill();
      // hover
      if (hover) {
        const hx = g.x(hover[0]), hy = g.y(hover[1]);
        ctx.strokeStyle = css('--chart-txt'); ctx.setLineDash([4, 4]); ctx.beginPath(); ctx.moveTo(hx, g.pad.t); ctx.lineTo(hx, g.H - g.pad.b); ctx.stroke(); ctx.setLineDash([]);
        ctx.beginPath(); ctx.arc(hx, hy, 5, 0, 7); ctx.fillStyle = css('--card'); ctx.fill(); ctx.lineWidth = 2; ctx.strokeStyle = color; ctx.stroke();
      }
    };
    const stats = () => {
      if (pts.length < 2) { ['#ch-min', '#ch-max'].forEach(s => $(s).textContent = '—'); return; }
      const ys = pts.map(p => p[1]), first = ys[0], last = ys[ys.length - 1], d = (last - first) / first * 100;
      $('#ch-min').textContent = usd(Math.min(...ys)); $('#ch-max').textContent = usd(Math.max(...ys));
      const el = $('#ch-delta'); el.textContent = pct(d) + ' over ' + { '1d': 'the day', '7d': 'the week', '1m': 'the month', '1y': 'the year' }[range]; el.className = 'mono ' + (d >= 0 ? 'up' : 'down');
    };
    const setNow = now => { if (!now) return; $('#ch-now').textContent = usd(now.usd); const e = $('#ch-24'); e.textContent = pct(now.ch); e.className = now.ch >= 0 ? 'up' : 'down'; };
    const loadChart = () => {
      const my = ++req; load.classList.add('on'); tip.style.opacity = 0;
      fetch(`api.php?chart=${sym}&range=${range}`).then(r => r.json()).then(d => {
        if (my !== req) return;
        pts = d.points || []; setNow(d.now); stats(); draw(); load.classList.remove('on');
        if (pts.length < 2) { load.classList.add('on'); load.innerHTML = '<span class="muted">No data — the exchange is unavailable</span>'; }
      }).catch(() => { if (my === req) load.innerHTML = '<span class="muted">Couldn’t load the chart</span>'; });
    };
    const open = s => {
      sym = s; const c = (window.COINS || {})[s] || {};
      $('#ch-ic').innerHTML = `<span class="coin-ic" style="--cbg:${c.bg || c.color || '#888'};--ink:${c.ink || '#fff'};width:46px;height:46px;font-size:23px"><b>${c.glyph || s[0]}</b><i>x</i></span>`;
      $('#ch-name').textContent = (c.name || s) + ''; $('#ch-sym').textContent = s + ' / USD';
      load.innerHTML = '<div class="ring r1"></div>'; pts = []; draw();
      M.classList.add('open'); loadChart();
      clearInterval(liveT);
      liveT = setInterval(() => { if (!M.classList.contains('open')) return clearInterval(liveT);
        fetch('api.php?prices=1').then(r => r.json()).then(p => { const n = p[sym]; if (!n) return; setNow(n);
          if (pts.length > 1) { pts[pts.length - 1] = [Date.now(), n.usd]; stats(); draw(); } }); }, 10000);
    };
    document.addEventListener('click', e => {
      const t = e.target.closest('[data-chart]');
      if (t && !e.target.closest('button:not([data-chart]),a,[data-open]')) { e.preventDefault(); open(t.dataset.chart); }
    });
    $$('#ch-range button').forEach(b => b.addEventListener('click', () => {
      $$('#ch-range button').forEach(x => x.classList.toggle('on', x === b)); range = b.dataset.r; loadChart(); }));
    const hoverAt = cx => {
      if (pts.length < 2) return; const g = geom(), r = cv.getBoundingClientRect(), mx = cx - r.left;
      let best = pts[0], bd = 1e9; for (const p of pts) { const d = Math.abs(g.x(p[0]) - mx); if (d < bd) { bd = d; best = p; } }
      draw(best); tip.innerHTML = `<b>${usd(best[1])}</b><span>${dt(best[0])}</span>`;
      const tx = Math.min(Math.max(g.x(best[0]) - 60, 0), g.W - 120); tip.style.transform = `translate(${tx}px,${Math.max(g.y(best[1]) - 58, 0)}px)`; tip.style.opacity = 1;
    };
    cv.addEventListener('mousemove', e => hoverAt(e.clientX));
    cv.addEventListener('touchmove', e => { hoverAt(e.touches[0].clientX); e.preventDefault(); }, { passive: false });
    const leave = () => { tip.style.opacity = 0; draw(); };
    cv.addEventListener('mouseleave', leave); cv.addEventListener('touchend', leave);
    window.addEventListener('resize', () => M.classList.contains('open') && draw());
    window.addEventListener('themechange', () => M.classList.contains('open') && draw());
  }
})();
