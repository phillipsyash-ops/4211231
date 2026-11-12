// Loyal — "My crypto": balances of the user's own wallets (MetaMask, Trust Wallet…), shown under the ‑x assets.
// Read-only: Loyal only reads public balances by address. It never asks for a recovery phrase and can't move these coins.
(() => {
  'use strict';
  const CFG = window.LOYAL_APP || {};
  if (!CFG.user) return;
  const anchor = document.querySelector('main .cards2') || document.querySelector('main .hero-bal');
  if (!anchor) return;
  const API = 'mywallets.php';
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const usd = (v) => '$' + Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const amt = (v) => { v = +v; const d = v >= 1000 ? 2 : v >= 1 ? 4 : 6; return v.toLocaleString('en-US', { maximumFractionDigits: d }); };
  const short = (a) => a.length > 16 ? a.slice(0, 6) + '…' + a.slice(-4) : a;
  const api = async (a, body) => {
    const r = await fetch(`${API}?a=${a}`, body ? { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF': CFG.csrf || '' }, body: JSON.stringify(body) } : { credentials: 'same-origin' });
    const d = await r.json().catch(() => ({ error: 'Connection problem — try again.' }));
    if (!r.ok || d.error) throw new Error(d.error || 'Something went wrong.');
    return d;
  };
  const isMobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  // brand-coloured initials for wallets that didn't give us an icon (we don't ship other companies' logos)
  const BADGE = { 'trust wallet': ['TW', '#2f63e6'], metamask: ['MM', '#e8821e'], 'coinbase wallet': ['CB', '#1652f0'], 'okx wallet': ['OK', '#111'], rabby: ['RB', '#7084ff'], phantom: ['PH', '#ab9ff2'] };
  const COIN = { USDT: ['₮', '#26A17B'], USDC: ['$', '#2775CA'], ETH: ['Ξ', '#627EEA'], BNB: ['◆', '#F0B90B', '#1E2026'], TRX: ['▽', '#EB0029'], BTC: ['₿', '#F7931A'] };
  const CHAIN_NAME = { evm: 'Ethereum · BNB Chain · Base', tron: 'Tron', btc: 'Bitcoin' };
  const walletIcon = (w, size = 40) => {
    if (w.icon) return `<img class="mw-wic" src="${esc(w.icon)}" width="${size}" height="${size}" alt="">`;
    const [t, bg] = BADGE[(w.wallet || '').toLowerCase()] || [(w.wallet || w.chain || '?').slice(0, 2).toUpperCase(), '#55555a'];
    return `<span class="mw-wic mw-ini" style="background:${bg};width:${size}px;height:${size}px">${esc(t)}</span>`;
  };
  const coinIcon = (s) => { const [g, bg, ink] = COIN[s] || [s.slice(0, 1), '#888']; return `<span class="mw-cic" style="background:${bg};color:${ink || '#fff'}">${g}</span>`; };
  const ICON = {
    plug: '<svg viewBox="0 0 24 24"><path d="M9 7V3M15 7V3M7 7h10v4a5 5 0 0 1-10 0zM12 16v5"/></svg>',
    x: '<svg viewBox="0 0 24 24"><path d="M18 6 6 18M6 6l12 12"/></svg>',
    copy: '<svg viewBox="0 0 24 24"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>',
    refresh: '<svg viewBox="0 0 24 24"><path d="M20 11a8 8 0 1 0-2.3 5.7M20 4v7h-7"/></svg>',
    shield: '<svg viewBox="0 0 24 24"><path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M8.5 12l2.5 2.5 4.5-5"/></svg>',
    out: '<svg viewBox="0 0 24 24"><path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></svg>',
  };

  // ---------- the block under the ‑x assets ----------
  const sec = document.createElement('section');
  sec.className = 'box mw';
  sec.id = 'my-crypto';
  sec.innerHTML = `<div class="mw-head"><div><h3>My crypto <span class="mw-tag">real · your wallet</span></h3>
      <small class="muted">Your own wallets — balances from the blockchain, every action confirmed in your wallet</small></div>
      <div class="mw-sum" hidden></div>
      <button type="button" class="btn btn-p mw-connect">${ICON.plug}<span>Connect wallet</span></button></div>
    <div class="mw-list"></div>`;
  anchor.after(sec);
  const list = sec.querySelector('.mw-list'), sum = sec.querySelector('.mw-sum');
  sec.querySelector('.mw-connect').onclick = () => openConnect();

  const W = new Map();
  async function load(quiet) {
    if (!quiet) list.innerHTML = '<div class="mw-load"><i></i><i></i><i></i></div>';
    try {
      const d = await api('list');
      sum.hidden = !d.wallets.length; sum.innerHTML = `<small>Total</small><b>${usd(d.total)}</b>`;
      if (!d.wallets.length) {
        list.innerHTML = `<div class="mw-empty">${ICON.shield}<div><b>Connect MetaMask, Trust Wallet or another wallet</b>
          <p>See its real balances here, under your ‑x coins. Loyal can only read them — your crypto stays in your wallet and we never ask for your recovery phrase.</p></div></div>`;
        return;
      }
      list.innerHTML = d.wallets.map((w) => `
        <div class="mw-w" data-id="${w.id}">
          <div class="mw-wh">${walletIcon(w)}
            <div class="mw-wn"><b>${esc(w.wallet || 'Wallet')}</b><small><span class="mono">${esc(short(w.address))}</span> · ${CHAIN_NAME[w.chain] || ''}</small></div>
            <b class="mw-wt">${w.ok || w.stale ? usd(w.total) : '—'}</b>
            <div class="mw-acts">
              <button type="button" data-copy="${esc(w.address)}" title="Copy address" aria-label="Copy address">${ICON.copy}</button>
              <button type="button" data-rm="${w.id}" title="Disconnect" aria-label="Disconnect">${ICON.x}</button></div></div>
          <div class="mw-do">
            <button type="button" class="btn btn-sm btn-p" data-send="${w.id}">↑ Send</button>
            <button type="button" class="btn btn-sm" data-recv="${w.id}">↓ Receive</button>
            ${w.chain === 'evm' ? `<button type="button" class="btn btn-sm" data-swap="${w.id}">⇄ Swap</button>` : ''}</div>
          ${!w.ok && !w.stale ? '<div class="mw-note">Couldn’t reach the blockchain right now — try again in a minute.</div>' : ''}
          ${w.ok && !w.assets.length ? '<div class="mw-note">No coins on this address yet.</div>' : ''}
          ${w.assets.map((x) => `<div class="mw-a">${coinIcon(x.sym)}<span class="n"><b>${esc(x.name)}</b><small>${esc(x.sym)} · ${esc(x.net)}</small></span>
            <span class="v"><span class="mono">${amt(x.amount)}</span><small>${usd(x.usd)}</small></span></div>`).join('')}
        </div>`).join('') + `<p class="mw-foot">${ICON.shield}Every transfer and swap is confirmed by you in your wallet — Loyal can’t move these coins on its own.</p>`;
      W.clear(); d.wallets.forEach((w) => W.set(w.id, w));
      list.querySelectorAll('[data-send]').forEach((b) => (b.onclick = () => openSend(W.get(+b.dataset.send))));
      list.querySelectorAll('[data-recv]').forEach((b) => (b.onclick = () => openReceive(W.get(+b.dataset.recv))));
      list.querySelectorAll('[data-swap]').forEach((b) => (b.onclick = () => openSwap(W.get(+b.dataset.swap))));
      list.querySelectorAll('[data-rm]').forEach((b) => (b.onclick = async () => {
        if (!confirm('Disconnect this wallet from Loyal? Nothing happens to the coins in it.')) return;
        try { await api('remove', { id: +b.dataset.rm }); load(true); } catch (e) { alert(e.message); }
      }));
      list.querySelectorAll('[data-copy]').forEach((b) => (b.onclick = () => { navigator.clipboard?.writeText(b.dataset.copy); b.classList.add('ok'); setTimeout(() => b.classList.remove('ok'), 1200); }));
    } catch (e) { list.innerHTML = `<div class="mw-note">${esc(e.message)}</div>`; }
  }

  // ---------- browser wallets (EIP-6963: each installed wallet announces its name and icon) ----------
  const found = new Map();
  addEventListener('eip6963:announceProvider', (e) => { const d = e.detail; if (d && d.info && d.provider) found.set(d.info.uuid || d.info.rdns || d.info.name, d); });
  dispatchEvent(new Event('eip6963:requestProvider'));
  const legacy = () => (!found.size && window.ethereum) ? [{ info: { name: window.ethereum.isTrust || window.ethereum.isTrustWallet ? 'Trust Wallet' : window.ethereum.isMetaMask ? 'MetaMask' : 'Browser wallet', icon: '' }, provider: window.ethereum }] : [];

  async function connectProvider(d, btn) {
    btn && (btn.disabled = true);
    try {
      const accs = await d.provider.request({ method: 'eth_requestAccounts' });
      if (!accs || !accs[0]) throw new Error('No account was shared.');
      await api('add', { address: accs[0], wallet: d.info.name, icon: d.info.icon || '' });
      closeSheet(); load();
    } catch (e) { alert(e && e.code === 4001 ? 'You cancelled the request in your wallet.' : (e.message || 'Couldn’t connect.')); }
    btn && (btn.disabled = false);
  }

  // ---------- "Connect wallet" sheet ----------
  let sheet = null;
  const closeSheet = () => { sheet?.remove(); sheet = null; };
  function openConnect() {
    closeSheet();
    const here = location.origin + location.pathname + '?connect=1';
    const providers = [...found.values(), ...legacy()];
    const deep = [
      ['Trust Wallet', 'TW', '#2f63e6', 'https://link.trustwallet.com/open_url?coin_id=60&url=' + encodeURIComponent(here)],
      ['MetaMask', 'MM', '#e8821e', 'https://metamask.app.link/dapp/' + here.replace(/^https?:\/\//, '')],
    ];
    sheet = document.createElement('div');
    sheet.className = 'mw-sheet';
    sheet.innerHTML = `<div class="mw-panel" role="dialog" aria-label="Connect wallet">
      <div class="mw-ph"><b>Connect wallet</b><button type="button" class="mw-x" aria-label="Close">${ICON.x}</button></div>
      ${providers.length ? `<div class="mw-sub">On this device</div>${providers.map((d, i) => `
        <button type="button" class="mw-opt" data-p="${i}">${walletIcon({ icon: d.info.icon, wallet: d.info.name }, 40)}
          <span><b>${esc(d.info.name)}</b><small>Connect — you’ll confirm every action in the wallet</small></span></button>`).join('')}` : ''}
      ${isMobile && !providers.length ? `<div class="mw-sub">Wallet app on this phone</div>${deep.map(([n, t, c, href]) => `
        <a class="mw-opt" href="${esc(href)}" rel="noopener"><span class="mw-wic mw-ini" style="background:${c};width:40px;height:40px">${t}</span>
          <span><b>Open in ${n}</b><small>Opens Loyal inside the app’s browser — then tap Connect again</small></span>${ICON.out}</a>`).join('')}` : ''}
      ${!isMobile && !providers.length ? `<div class="mw-hint">No wallet extension found in this browser. Install MetaMask or Trust Wallet extension, or add your address below.</div>` : ''}
      <div class="mw-sub">Or add any wallet by address</div>
      <div class="mw-addr"><input type="text" placeholder="0x…, T… or bc1… address" autocomplete="off" spellcheck="false" aria-label="Wallet address">
        <select aria-label="Wallet app"><option>Trust Wallet</option><option>MetaMask</option><option>Coinbase Wallet</option><option>OKX Wallet</option><option value="">Other</option></select>
        <button type="button" class="btn btn-p">Add</button></div>
      <p class="mw-err" hidden></p>
      <p class="mw-safe">${ICON.shield}<span>Loyal never holds your coins: every transfer or swap opens in your wallet for <b>your</b> confirmation. We’ll never ask for your recovery phrase or private key — anyone who does is a scammer.</span></p>
    </div>`;
    document.body.appendChild(sheet);
    sheet.addEventListener('click', (e) => { if (e.target === sheet || e.target.closest('.mw-x')) closeSheet(); });
    sheet.querySelectorAll('[data-p]').forEach((b) => (b.onclick = () => connectProvider(providers[+b.dataset.p], b)));
    const inp = sheet.querySelector('.mw-addr input'), sel = sheet.querySelector('.mw-addr select'), err = sheet.querySelector('.mw-err');
    // the QR scanner from the app works here too
    if (window.loyalScan) {
      const sb = document.createElement('button'); sb.type = 'button'; sb.className = 'btn mw-scan'; sb.title = 'Scan QR'; sb.setAttribute('aria-label', 'Scan QR');
      sb.innerHTML = '<svg viewBox="0 0 24 24"><path d="M4 8V5a1 1 0 0 1 1-1h3M16 4h3a1 1 0 0 1 1 1v3M20 16v3a1 1 0 0 1-1 1h-3M8 20H5a1 1 0 0 1-1-1v-3M7 12h10"/></svg>';
      sb.onclick = async () => { const v = await window.loyalScan(); if (v) inp.value = v; };
      inp.after(sb);
    }
    sheet.querySelector('.mw-addr .btn-p').onclick = async (e) => {
      err.hidden = true; e.target.disabled = true;
      try { await api('add', { address: inp.value.trim(), wallet: sel.value }); closeSheet(); load(); }
      catch (x) { err.textContent = x.message; err.hidden = false; }
      e.target.disabled = false;
    };
  }

  // =====================================================================
  //  Send · Receive · Swap — always confirmed by the user in THEIR wallet
  // =====================================================================
  const NETS = {
    eth:  { id: 1,    hex: '0x1',    name: 'Ethereum',  tw: 60,       exp: 'https://etherscan.io/tx/', fee: 'ETH' },
    bsc:  { id: 56,   hex: '0x38',   name: 'BNB Chain', tw: 20000714, exp: 'https://bscscan.com/tx/', fee: 'BNB',
            add: { chainId: '0x38', chainName: 'BNB Smart Chain', nativeCurrency: { name: 'BNB', symbol: 'BNB', decimals: 18 }, rpcUrls: ['https://bsc-dataseed.binance.org'], blockExplorerUrls: ['https://bscscan.com'] } },
    base: { id: 8453, hex: '0x2105', name: 'Base',      tw: 8453,     exp: 'https://basescan.org/tx/', fee: 'ETH',
            add: { chainId: '0x2105', chainName: 'Base', nativeCurrency: { name: 'Ether', symbol: 'ETH', decimals: 18 }, rpcUrls: ['https://mainnet.base.org'], blockExplorerUrls: ['https://basescan.org'] } },
    tron: { name: 'Tron',    tw: 195, exp: 'https://tronscan.org/#/transaction/', fee: 'TRX' },
    btc:  { name: 'Bitcoin', tw: 0,   exp: 'https://mempool.space/tx/', fee: 'BTC' },
  };
  const TOKENS = {   // [symbol, name, contract|null (native), decimals]
    eth:  [['ETH', 'Ether', null, 18], ['USDT', 'Tether', '0xdAC17F958D2ee523a2206206994597C13D831ec7', 6], ['USDC', 'USD Coin', '0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48', 6]],
    bsc:  [['BNB', 'BNB', null, 18], ['USDT', 'Tether', '0x55d398326f99059fF775485246999027B3197955', 18], ['USDC', 'USD Coin', '0x8AC76a51cc950d9822D68b83fE1Ad97B32Cd580d', 18]],
    base: [['ETH', 'Ether', null, 18], ['USDC', 'USD Coin', '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913', 6]],
  };
  const NATIVE = '0x0000000000000000000000000000000000000000';
  const SWAP_API = CFG.swapApi || 'https://li.quest/v1';   // LI.FI: finds the best DEX price; no fee added by Loyal
  const RE = { evm: /^0x[0-9a-fA-F]{40}$/, tron: /^T[1-9A-HJ-NP-Za-km-z]{33}$/, btc: /^(bc1[02-9ac-hj-np-z]{11,71}|[13][1-9A-HJ-NP-Za-km-z]{25,34})$/ };
  const toUnits = (str, dec) => {
    str = String(str).trim().replace(',', '.');
    if (!/^\d*\.?\d*$/.test(str) || str === '' || str === '.') throw new Error('Enter an amount.');
    let [i, f = ''] = str.split('.'); f = (f + '0'.repeat(dec)).slice(0, dec);
    const v = BigInt(i || '0') * 10n ** BigInt(dec) + BigInt(f || '0');
    if (v <= 0n) throw new Error('Enter an amount greater than zero.');
    return v;
  };
  const fromUnits = (bi, dec, keep = 6) => {
    const s = BigInt(bi).toString().padStart(dec + 1, '0'); const i = s.slice(0, -dec) || '0';
    const f = s.slice(-dec).slice(0, keep).replace(/0+$/, ''); return f ? `${(+i).toLocaleString('en-US')}.${f}` : (+i).toLocaleString('en-US');
  };
  const pad = (h) => String(h).replace(/^0x/, '').toLowerCase().padStart(64, '0');
  const hex = (bi) => '0x' + BigInt(bi).toString(16);

  // the browser wallet for this linked wallet (extension, or the wallet app's own browser)
  function providerFor(w) {
    if (w.chain !== 'evm') return null;
    const all = [...found.values(), ...legacy()];
    return all.find((d) => (d.info.name || '').toLowerCase() === (w.wallet || '').toLowerCase()) || all[0] || null;
  }
  async function ready(p, w, net) {
    const accs = (await p.provider.request({ method: 'eth_requestAccounts' })) || [];
    if (!accs.some((a) => a.toLowerCase() === w.address.toLowerCase()))
      throw new Error(`In ${p.info.name}, switch to account ${short(w.address)} and try again.`);
    const cur = await p.provider.request({ method: 'eth_chainId' }).catch(() => null);
    if (String(cur).toLowerCase() !== NETS[net].hex) {
      try { await p.provider.request({ method: 'wallet_switchEthereumChain', params: [{ chainId: NETS[net].hex }] }); }
      catch (e) {
        if (e && e.code === 4902 && NETS[net].add) await p.provider.request({ method: 'wallet_addEthereumChain', params: [NETS[net].add] });
        else throw e;
      }
    }
  }
  const nice = (e) => (e && e.code === 4001) ? 'You rejected it in your wallet — nothing was sent.' : ((e && (e.shortMessage || e.message)) || 'Something went wrong.');
  async function receipt(p, hash) {
    for (let i = 0; i < 60; i++) {
      const r = await p.provider.request({ method: 'eth_getTransactionReceipt', params: [hash] }).catch(() => null);
      if (r && r.status) { if (r.status === '0x0') throw new Error('The approval failed on the blockchain.'); return r; }
      await new Promise((ok) => setTimeout(ok, 3000));
    }
    throw new Error('The approval is taking long. Check your wallet and try again.');
  }
  // links that open the wallet app with the transfer already filled in
  function deepLinks(w, x, to, amountStr, units) {
    const out = [], net = NETS[x.key];
    if (w.chain === 'evm' && (w.wallet || '').toLowerCase() !== 'trust wallet')
      out.push(['MetaMask', 'MM', '#e8821e', x.contract
        ? `https://metamask.app.link/send/pay-${x.contract}@${net.id}/transfer?address=${to}&uint256=${units}`
        : `https://metamask.app.link/send/${to}@${net.id}?value=${units}`]);
    out.push(['Trust Wallet', 'TW', '#2f63e6', `https://link.trustwallet.com/send?coin=${net.tw}&address=${encodeURIComponent(to)}&amount=${encodeURIComponent(amountStr)}${x.contract ? '&token_id=' + encodeURIComponent(x.contract) : ''}`]);
    if (w.chain === 'btc') out.push(['Bitcoin wallet', '₿', '#F7931A', `bitcoin:${to}?amount=${amountStr}`]);
    return out;
  }
  const qrSvg = (text, cell = 4) => { try { const q = qrcode(0, 'M'); q.addData(text); q.make(); return q.createSvgTag({ cellSize: cell, margin: 2 }); } catch (e) { return ''; } };

  // generic sheet
  function openSheet(title, bodyHtml) {
    closeSheet();
    sheet = document.createElement('div');
    sheet.className = 'mw-sheet';
    sheet.innerHTML = `<div class="mw-panel" role="dialog" aria-label="${esc(title)}"><div class="mw-ph"><b>${esc(title)}</b><button type="button" class="mw-x" aria-label="Close">${ICON.x}</button></div><div class="mw-pb">${bodyHtml}</div></div>`;
    document.body.appendChild(sheet);
    sheet.addEventListener('click', (e) => { if (e.target === sheet || e.target.closest('.mw-x')) closeSheet(); });
    return sheet.querySelector('.mw-pb');
  }
  const fromLine = (w) => `<div class="mw-from">${walletIcon(w, 32)}<span><b>${esc(w.wallet || 'Wallet')}</b><small class="mono">${esc(short(w.address))}</small></span></div>`;
  const scanBtn = () => window.loyalScan ? '<button type="button" class="btn mw-scan" data-scan aria-label="Scan QR"><svg viewBox="0 0 24 24"><path d="M4 8V5a1 1 0 0 1 1-1h3M16 4h3a1 1 0 0 1 1 1v3M20 16v3a1 1 0 0 1-1 1h-3M8 20H5a1 1 0 0 1-1-1v-3M7 12h10"/></svg></button>' : '';
  function success(box, w, net, hash, what) {
    box.innerHTML = `<div class="mw-done"><div class="ok">✓</div><h4>${esc(what)}</h4>
      <p>Sent to the blockchain. It usually confirms in under a minute.</p>
      <a class="btn btn-block" href="${esc(NETS[net].exp + hash)}" target="_blank" rel="noopener noreferrer">View on explorer ↗</a></div>`;
    setTimeout(() => load(true), 12000);
  }

  // ---------- Receive ----------
  function openReceive(w) {
    const nets = w.chain === 'evm' ? 'Ethereum, BNB Chain or Base' : NETS[w.chain].name;
    const box = openSheet('Receive', `${fromLine(w)}
      <div class="mw-qr">${qrSvg(w.address, 5)}</div>
      <div class="mw-addrbig mono">${esc(w.address)}</div>
      <button type="button" class="btn btn-block" data-c>Copy address</button>
      <p class="mw-warn">Send only <b>${esc(nets)}</b> coins to this address. Coins from other networks can be lost.</p>`);
    const c = box.querySelector('[data-c]');
    c.onclick = () => { navigator.clipboard?.writeText(w.address); c.textContent = 'Copied ✓'; setTimeout(() => (c.textContent = 'Copy address'), 1500); };
  }

  // ---------- Send ----------
  function openSend(w) {
    if (!w.assets.length) return openReceive(w);
    const box = openSheet('Send', `${fromLine(w)}
      <label class="mw-l" for="mw-coin">Coin</label>
      <select id="mw-coin">${w.assets.map((x, i) => `<option value="${i}">${esc(x.sym)} · ${esc(x.net)} — ${amt(x.amount)}</option>`).join('')}</select>
      <label class="mw-l" for="mw-to">Recipient address</label>
      <div class="mw-row"><input id="mw-to" class="mono" autocomplete="off" spellcheck="false" placeholder="${w.chain === 'evm' ? '0x…' : w.chain === 'tron' ? 'T…' : 'bc1…'}">${scanBtn()}</div>
      <label class="mw-l" for="mw-amt">Amount <button type="button" class="mw-max" data-max>Max</button></label>
      <input id="mw-amt" inputmode="decimal" placeholder="0.00" autocomplete="off">
      <p class="mw-err" hidden></p>
      <button type="button" class="btn btn-p btn-block btn-lg" data-review>Review</button>`);
    const $ = (s) => box.querySelector(s), err = $('.mw-err');
    const cur = () => w.assets[+$('#mw-coin').value];
    $('[data-max]').onclick = () => { const x = cur(); $('#mw-amt').value = String(x.contract ? x.amount : Math.max(0, x.amount * 0.98).toFixed(8)).replace(/\.?0+$/, ''); };
    $('[data-scan]') && ($('[data-scan]').onclick = async () => { const v = await window.loyalScan(); if (v) $('#mw-to').value = v; });
    $('[data-review]').onclick = () => {
      err.hidden = true;
      try {
        const x = cur(), to = $('#mw-to').value.trim(), a = $('#mw-amt').value.trim().replace(',', '.');
        if (!RE[w.chain].test(to)) throw new Error('Check the recipient address.');
        if (to.toLowerCase() === w.address.toLowerCase()) throw new Error('That’s this wallet’s own address.');
        const units = toUnits(a, x.dec);
        if (+a > x.amount) throw new Error(`Not enough ${x.sym}: you have ${amt(x.amount)}.`);
        review(w, x, to, a, units);
      } catch (e) { err.textContent = e.message; err.hidden = false; }
    };
  }
  function review(w, x, to, a, units) {
    const p = providerFor(w), net = NETS[x.key];
    const rows = `<div class="mw-sum2">
      <div><span>Send</span><b class="mono">${esc(a)} ${esc(x.sym)}</b></div>
      <div><span>≈</span><span>${usd(x.amount ? x.usd / x.amount * +a : 0)}</span></div>
      <div><span>To</span><span class="mono">${esc(short(to))}</span></div>
      <div><span>Network</span><span>${esc(net.name)}</span></div>
      <div><span>Network fee</span><span>paid in ${esc(net.fee)} from your wallet</span></div></div>
      <p class="mw-warn">Blockchain transfers can’t be reversed — check the address carefully.</p>`;
    if (p) {
      const box = openSheet('Confirm transfer', `${fromLine(w)}${rows}<p class="mw-err" hidden></p>
        <button type="button" class="btn btn-p btn-block btn-lg" data-go>Confirm in ${esc(p.info.name)}</button>
        <p class="mw-hint2">${esc(p.info.name)} will open with the amount and address — tap Confirm there.</p>`);
      const go = box.querySelector('[data-go]'), err = box.querySelector('.mw-err');
      go.onclick = async () => {
        err.hidden = true; go.disabled = true; go.textContent = `Waiting for ${p.info.name}…`;
        try {
          await ready(p, w, x.key);
          const tx = x.contract
            ? { from: w.address, to: x.contract, value: '0x0', data: '0xa9059cbb' + pad(to) + pad(units.toString(16)) }
            : { from: w.address, to, value: hex(units) };
          const hash = await p.provider.request({ method: 'eth_sendTransaction', params: [tx] });
          success(box, w, x.key, hash, `${a} ${x.sym} sent`);
        } catch (e) { err.textContent = nice(e); err.hidden = false; go.disabled = false; go.textContent = `Confirm in ${p.info.name}`; }
      };
      return;
    }
    // no wallet in this browser: open the wallet app with everything filled in
    const links = deepLinks(w, x, to, a, units.toString());
    openSheet('Confirm transfer', `${fromLine(w)}${rows}
      ${links.map(([n, t, c, href]) => `<a class="mw-opt" href="${esc(href)}" rel="noopener"><span class="mw-wic mw-ini" style="background:${c};width:40px;height:40px">${t}</span>
        <span><b>Confirm in ${esc(n)}</b><small>Opens the app with the amount and address filled in</small></span>${ICON.out}</a>`).join('')}
      ${!isMobile && links[0] ? `<div class="mw-qrhint"><div class="mw-qr sm">${qrSvg(links[0][3], 3)}</div><span>On a computer? Scan with your phone to confirm in ${esc(links[0][0])}.</span></div>` : ''}`);
  }

  // ---------- Swap (decentralized exchanges via LI.FI) ----------
  function openSwap(w) {
    const p = providerFor(w);
    const owned = w.assets.filter((x) => TOKENS[x.key]);
    if (!p) {
      const here = location.origin + location.pathname + '?connect=1';
      openSheet('Swap', `${fromLine(w)}<div class="mw-hint">Swaps are signed in your wallet, so open Loyal inside it:</div>
        <a class="mw-opt" href="https://metamask.app.link/dapp/${esc(here.replace(/^https?:\/\//, ''))}" rel="noopener"><span class="mw-wic mw-ini" style="background:#e8821e;width:40px;height:40px">MM</span><span><b>Open in MetaMask</b><small>Then tap ⇄ Swap again</small></span>${ICON.out}</a>
        <a class="mw-opt" href="https://link.trustwallet.com/open_url?coin_id=60&url=${encodeURIComponent(here)}" rel="noopener"><span class="mw-wic mw-ini" style="background:#2f63e6;width:40px;height:40px">TW</span><span><b>Open in Trust Wallet</b><small>Then tap ⇄ Swap again</small></span>${ICON.out}</a>
        ${!isMobile ? '<div class="mw-hint">On a computer: install the MetaMask or Trust Wallet browser extension with this account.</div>' : ''}`);
      return;
    }
    if (!owned.length) return openReceive(w);
    let net = owned[0].key;
    const box = openSheet('Swap', `${fromLine(w)}
      <label class="mw-l" for="sw-net">Network</label>
      <select id="sw-net">${[...new Set(owned.map((x) => x.key))].map((k) => `<option value="${k}">${NETS[k].name}</option>`).join('')}</select>
      <div class="mw-swap">
        <div class="mw-sbox"><label class="mw-l" for="sw-amt">You pay <button type="button" class="mw-max" data-max>Max</button></label>
          <div class="mw-row"><input id="sw-amt" inputmode="decimal" placeholder="0.00" autocomplete="off"><select id="sw-from" aria-label="Pay with"></select></div>
          <small id="sw-bal" class="muted"></small></div>
        <button type="button" class="mw-flip" data-flip aria-label="Swap direction">⇅</button>
        <div class="mw-sbox"><label class="mw-l" for="sw-to">You get</label>
          <div class="mw-row"><output id="sw-out" class="mono">—</output><select id="sw-to" aria-label="Receive"></select></div>
          <small id="sw-min" class="muted"></small></div>
      </div>
      <div class="mw-sum2" id="sw-info" hidden></div>
      <p class="mw-err" hidden></p>
      <button type="button" class="btn btn-p btn-block btn-lg" data-go disabled>Enter an amount</button>
      <p class="mw-hint2">Best price across decentralized exchanges (via LI.FI). Loyal adds no fee and never holds your coins — you confirm in ${esc(p.info.name)}.</p>`);
    const $ = (s) => box.querySelector(s), err = $('.mw-err'), go = $('[data-go]');
    let quote = null, qAt = 0, tm = 0, seq = 0;
    const bal = (k, sym) => (w.assets.find((x) => x.key === k && x.sym === sym) || { amount: 0 }).amount;
    const tok = (sym) => TOKENS[net].find((t) => t[0] === sym);
    const fill = () => {
      const fromSyms = TOKENS[net].filter((t) => bal(net, t[0]) > 0).map((t) => t[0]);
      $('#sw-from').innerHTML = fromSyms.map((s) => `<option>${s}</option>`).join('');
      fillTo();
    };
    const fillTo = () => {
      const f = $('#sw-from').value, keep = $('#sw-to').value;
      $('#sw-to').innerHTML = TOKENS[net].filter((t) => t[0] !== f).map((t) => `<option${t[0] === keep ? ' selected' : ''}>${t[0]}</option>`).join('');
      $('#sw-bal').textContent = `Balance: ${amt(bal(net, f))} ${f}`;
      requote();
    };
    const reset = (msg) => { quote = null; $('#sw-out').textContent = '—'; $('#sw-min').textContent = ''; $('#sw-info').hidden = true; go.disabled = true; go.textContent = msg; };
    async function getQuote() {
      const f = tok($('#sw-from').value), t = tok($('#sw-to').value), a = $('#sw-amt').value.trim();
      const units = toUnits(a, f[3]);
      if (+a.replace(',', '.') > bal(net, f[0])) throw new Error(`Not enough ${f[0]}: you have ${amt(bal(net, f[0]))}.`);
      const q = new URLSearchParams({ fromChain: NETS[net].id, toChain: NETS[net].id, fromToken: f[2] || NATIVE, toToken: t[2] || NATIVE,
        fromAmount: units.toString(), fromAddress: w.address, toAddress: w.address, slippage: '0.005', integrator: 'loyal' });
      const r = await fetch(`${SWAP_API}/quote?${q}`);
      const d = await r.json().catch(() => ({}));
      if (!r.ok || !d.transactionRequest) throw new Error(d.message ? `No route: ${d.message}` : 'No swap route found for this amount. Try a different amount.');
      return { d, f, t, units, a };
    }
    function requote() {
      clearTimeout(tm); err.hidden = true;
      if (!$('#sw-amt').value.trim()) return reset('Enter an amount');
      reset('Getting the best price…');
      const my = ++seq;
      tm = setTimeout(async () => {
        try {
          const q = await getQuote(); if (my !== seq) return;
          quote = q; qAt = Date.now();
          const e = q.d.estimate, fee = (e.gasCosts || []).reduce((s, g) => s + (+g.amountUSD || 0), 0);
          $('#sw-out').textContent = fromUnits(e.toAmount, q.t[3]);
          $('#sw-min').textContent = `Minimum ${fromUnits(e.toAmountMin, q.t[3])} ${q.t[0]} (0.5% price protection)`;
          $('#sw-info').hidden = false;
          $('#sw-info').innerHTML = `<div><span>Rate</span><span>1 ${q.f[0]} ≈ ${(Number(fromUnits(e.toAmount, q.t[3], 8).replace(/,/g, '')) / +q.a.replace(',', '.')).toLocaleString('en-US', { maximumFractionDigits: 6 })} ${q.t[0]}</span></div>
            <div><span>Route</span><span>${esc((q.d.toolDetails && q.d.toolDetails.name) || q.d.tool || 'DEX')}</span></div>
            <div><span>Network fee</span><span>≈ ${usd(fee)} in ${NETS[net].fee}</span></div>`;
          go.disabled = false; go.textContent = `Swap in ${p.info.name}`;
        } catch (x) { if (my !== seq) return; reset('Swap'); err.textContent = x.message; err.hidden = false; }
      }, 450);
    }
    $('#sw-net').onchange = () => { net = $('#sw-net').value; fill(); };
    $('#sw-from').onchange = fillTo; $('#sw-to').onchange = requote; $('#sw-amt').oninput = requote;
    $('[data-max]').onclick = () => { const f = $('#sw-from').value, b = bal(net, f); $('#sw-amt').value = String(tok(f)[2] ? b : Math.max(0, b * 0.97).toFixed(8)).replace(/\.?0+$/, ''); requote(); };
    $('[data-flip]').onclick = () => { const f = $('#sw-from').value, t = $('#sw-to').value; if (bal(net, t) <= 0) return; $('#sw-from').value = t; fillTo(); $('#sw-to').value = f; requote(); };
    go.onclick = async () => {
      err.hidden = true; go.disabled = true;
      try {
        if (!quote || Date.now() - qAt > 50000) { go.textContent = 'Refreshing price…'; quote = await getQuote(); qAt = Date.now(); }
        const q = quote, tr = q.d.transactionRequest, spender = q.d.estimate.approvalAddress;
        go.textContent = `Waiting for ${p.info.name}…`;
        await ready(p, w, net);
        if (q.f[2] && spender) {   // a token needs permission for the exchange to take exactly this amount
          const al = await p.provider.request({ method: 'eth_call', params: [{ to: q.f[2], data: '0xdd62ed3e' + pad(w.address) + pad(spender) }, 'latest'] }).catch(() => '0x0');
          if (BigInt(al || '0x0') < q.units) {
            go.textContent = `Step 1 of 2: allow ${q.f[0]} in ${p.info.name}`;
            const ah = await p.provider.request({ method: 'eth_sendTransaction', params: [{ from: w.address, to: q.f[2], value: '0x0', data: '0x095ea7b3' + pad(spender) + pad(q.units.toString(16)) }] });
            go.textContent = 'Waiting for the approval to confirm…';
            await receipt(p, ah);
            go.textContent = `Step 2 of 2: confirm the swap in ${p.info.name}`;
          }
        }
        const tx = { from: w.address, to: tr.to, data: tr.data, value: tr.value ? hex(tr.value) : '0x0' };
        if (tr.gasLimit) tx.gas = hex(tr.gasLimit);
        const hash = await p.provider.request({ method: 'eth_sendTransaction', params: [tx] });
        success(box, w, net, hash, `Swapped ${q.a} ${q.f[0]} → ${q.t[0]}`);
      } catch (x) { err.textContent = nice(x); err.hidden = false; go.disabled = false; go.textContent = `Swap in ${p.info.name}`; }
    };
    fill();
  }

  load();
  setInterval(() => { if (!document.hidden && !sheet) load(true); }, 90000);
  // opened from "Open in Trust Wallet / MetaMask": offer to connect right away
  if (/[?&]connect=1\b/.test(location.search)) setTimeout(openConnect, 600);
})();
