// Shared helpers for the popup and the background script

export const local = {
  get: async (k) => (await chrome.storage.local.get(k))[k],
  set: (k, v) => chrome.storage.local.set({ [k]: v }),
  del: (k) => chrome.storage.local.remove(k),
};
export const session = {
  get: async (k) => (await chrome.storage.session.get(k))[k],
  set: (k, v) => chrome.storage.session.set({ [k]: v }),
  del: (k) => chrome.storage.session.remove(k),
};

export class ApiError extends Error { constructor(msg, status) { super(msg); this.status = status; } }

// Request to ext_api.php on the site
export async function api(action, { params = {}, body = null, token = null, site = null } = {}) {
  site = site || await local.get('site');
  if (!site) throw new ApiError('No site address set', 0);
  const qs = new URLSearchParams({ a: action, ...params }).toString();
  const headers = { 'Accept': 'application/json' };
  if (body) headers['Content-Type'] = 'application/json';
  if (token) headers['X-Auth-Token'] = token;
  let r;
  try {
    r = await fetch(`${site}/ext_api.php?${qs}`, { method: body ? 'POST' : 'GET', headers, body: body ? JSON.stringify(body) : undefined, cache: 'no-store' });
  } catch (e) { throw new ApiError('The site is unreachable. Check your connection and the site address.', 0); }
  let j = null; try { j = await r.json(); } catch (e) {}
  if (!r.ok || !j) throw new ApiError(j?.error || `Site error (${r.status})`, r.status);
  return j;
}

// ---------- access key encrypted with the password (PBKDF2 + AES‑GCM) ----------
const enc = new TextEncoder(), dec = new TextDecoder();
const b64 = (buf) => btoa(String.fromCharCode(...new Uint8Array(buf)));
const unb64 = (s) => Uint8Array.from(atob(s), (c) => c.charCodeAt(0));

async function deriveKey(password, salt) {
  const base = await crypto.subtle.importKey('raw', enc.encode(password), 'PBKDF2', false, ['deriveKey']);
  return crypto.subtle.deriveKey({ name: 'PBKDF2', salt, iterations: 250000, hash: 'SHA-256' }, base, { name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']);
}
export async function encryptSecret(text, password) {
  const salt = crypto.getRandomValues(new Uint8Array(16)), iv = crypto.getRandomValues(new Uint8Array(12));
  const key = await deriveKey(password, salt);
  const ct = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, key, enc.encode(text));
  return { salt: b64(salt), iv: b64(iv), ct: b64(ct) };
}
export async function decryptSecret(box, password) {
  const key = await deriveKey(password, unb64(box.salt));
  const pt = await crypto.subtle.decrypt({ name: 'AES-GCM', iv: unb64(box.iv) }, key, unb64(box.ct));
  return dec.decode(pt);
}

// ---------- formatting ----------
export const usd = (v) => '$' + Number(v).toLocaleString('en-US', {
  minimumFractionDigits: 2, maximumFractionDigits: v >= 1 || v === 0 ? 2 : 6,
}).replace(/,/g, ' ');
export const amt = (s, dec = 8) => {
  const [i, f = ''] = String(s).split('.');
  const ff = f.slice(0, Math.max(dec, 2)).replace(/0+$/, '');
  return Number(i).toLocaleString('en-US') + (ff ? '.' + ff : '');
};
export const pct = (v) => (v >= 0 ? '+' : '') + Number(v).toFixed(2) + '%';
