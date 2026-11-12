// 3D-монета Loyal на главной: крупная в первом экране, при прокрутке уходит вдаль к правому краю
(() => {
  'use strict';
  if (!window.THREE) return;
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const canvas = document.createElement('canvas');
  canvas.className = 'coin3d';
  canvas.setAttribute('aria-hidden', 'true');
  document.body.appendChild(canvas);

  let renderer;
  try { renderer = new THREE.WebGLRenderer({ canvas, alpha: true, antialias: true, powerPreference: 'low-power' }); }
  catch (e) { canvas.remove(); return; }
  renderer.setPixelRatio(Math.min(devicePixelRatio || 1, 2));
  renderer.outputEncoding = THREE.sRGBEncoding;
  renderer.toneMapping = THREE.ACESFilmicToneMapping;
  renderer.toneMappingExposure = 1.05;

  const scene = new THREE.Scene();
  const camera = new THREE.PerspectiveCamera(30, 1, 0.1, 100);
  camera.position.set(0, 0, 12);

  // окружение для металлических отражений — мягкая «студия», нарисованная на canvas
  const env = (() => {
    const c = document.createElement('canvas'); c.width = 512; c.height = 256;
    const g = c.getContext('2d');
    const gr = g.createLinearGradient(0, 0, 0, 256);
    gr.addColorStop(0, '#ffffff'); gr.addColorStop(0.45, '#d9dbe0'); gr.addColorStop(0.55, '#8d9097'); gr.addColorStop(1, '#2a2b2f');
    g.fillStyle = gr; g.fillRect(0, 0, 512, 256);
    [[90, 70, 70], [300, 60, 90], [430, 110, 50]].forEach(([x, y, r]) => {
      const s = g.createRadialGradient(x, y, 0, x, y, r); s.addColorStop(0, 'rgba(255,255,255,1)'); s.addColorStop(1, 'rgba(255,255,255,0)');
      g.fillStyle = s; g.fillRect(x - r, y - r, r * 2, r * 2);
    });
    const t = new THREE.CanvasTexture(c); t.mapping = THREE.EquirectangularReflectionMapping; t.encoding = THREE.sRGBEncoding;
    const pm = new THREE.PMREMGenerator(renderer);
    const out = pm.fromEquirectangular(t).texture; t.dispose(); pm.dispose();
    return out;
  })();
  scene.environment = env;

  // рифлёный гурт монеты
  const ridges = (() => {
    const c = document.createElement('canvas'); c.width = 1024; c.height = 16;
    const g = c.getContext('2d');
    for (let x = 0; x < 1024; x += 8) { g.fillStyle = '#fff'; g.fillRect(x, 0, 4, 16); g.fillStyle = '#777'; g.fillRect(x + 4, 0, 4, 16); }
    const t = new THREE.CanvasTexture(c); t.wrapS = THREE.RepeatWrapping; t.repeat.set(3, 1); return t;
  })();

  const base = (document.querySelector('script[data-coin3d]')?.dataset.base) || 'assets/';
  const loader = new THREE.TextureLoader();
  const faceTex = loader.load(base + 'logo/coin-face.jpg', () => { ready = true; });
  faceTex.encoding = THREE.sRGBEncoding;
  faceTex.anisotropy = renderer.capabilities.getMaxAnisotropy();
  const backTex = faceTex.clone(); backTex.needsUpdate = true;

  const R = 1, TH = 0.17;
  const geo = new THREE.CylinderGeometry(R, R, TH, 160, 1, false);
  geo.rotateX(Math.PI / 2);                         // лицевая сторона смотрит в камеру
  const edge = new THREE.MeshPhysicalMaterial({ color: 0xd4d7dc, metalness: 1, roughness: 0.3, bumpMap: ridges, bumpScale: 0.012, clearcoat: 0.4 });
  const face = new THREE.MeshPhysicalMaterial({ map: faceTex, metalness: 0.25, roughness: 0.42, clearcoat: 0.55, clearcoatRoughness: 0.25, envMapIntensity: 0.55 });
  const back = new THREE.MeshPhysicalMaterial({ map: backTex, metalness: 0.25, roughness: 0.42, clearcoat: 0.55, clearcoatRoughness: 0.25, envMapIntensity: 0.55 });
  const coin = new THREE.Mesh(geo, [edge, face, back]);
  // ориентация картинки на крышках цилиндра
  faceTex.center.set(0.5, 0.5); faceTex.rotation = Math.PI / 2;
  backTex.center.set(0.5, 0.5); backTex.rotation = Math.PI / 2;
  const holder = new THREE.Group(); holder.add(coin); scene.add(holder);

  scene.add(new THREE.AmbientLight(0xffffff, 0.35));
  const key = new THREE.DirectionalLight(0xffffff, 1.1); key.position.set(-3, 4, 6); scene.add(key);
  const rim = new THREE.DirectionalLight(0xdfe6ff, 0.6); rim.position.set(4, -2, -3); scene.add(rim);

  // ---------- положение по прокрутке ----------
  let W = 0, H = 0, ready = false;
  const stage = document.querySelector('.coin-stage');
  const lerp = (a, b, t) => a + (b - a) * t;
  const ease = (t) => t < 0.5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2;
  function resize() {
    W = innerWidth; H = innerHeight;
    renderer.setSize(W, H, false); camera.aspect = W / H; camera.updateProjectionMatrix();
  }
  addEventListener('resize', resize); resize();
  function layout(p) {
    const mobile = W < 760;
    // [доля ширины, доля высоты, радиус в px, прозрачность, размытие]
    // старт — место под монету в первом экране (.coin-stage); оно уезжает вверх вместе со страницей
    let A = mobile ? [0.80, 0.17, Math.min(84, W * 0.2), 0.95, 0] : [0.79, 0.36, Math.min(250, W * 0.17, H * 0.3), 1, 0];
    if (stage) { const r = stage.getBoundingClientRect(); A = [(r.left + r.width / 2) / W, (r.top + r.height / 2) / H, Math.min(r.width, r.height) * 0.4, 1, 0]; }
    const B = mobile ? [0.92, 0.62, 46, 0.22, 1.5] : [0.955, 0.58, Math.min(120, W * 0.08), 0.32, 1.6];
    const k = ease(p);
    const sx = lerp(A[0], B[0], k), sy = lerp(A[1], B[1], k), rad = lerp(A[2], B[2], k);
    const hh = Math.tan(THREE.MathUtils.degToRad(camera.fov / 2)) * camera.position.z, ww = hh * camera.aspect;
    holder.position.set((sx * 2 - 1) * ww, -(sy * 2 - 1) * hh, 0);
    holder.scale.setScalar(rad * (2 * hh / H));
    canvas.style.opacity = lerp(A[3], B[3], k).toFixed(3);
    canvas.style.filter = k > 0.02 ? `blur(${(lerp(A[4], B[4], k)).toFixed(2)}px)` : '';
  }

  // ---------- движение ----------
  const mouse = { x: 0, y: 0, tx: 0, ty: 0 };
  addEventListener('pointermove', (e) => { mouse.tx = (e.clientX / W - 0.5); mouse.ty = (e.clientY / H - 0.5); }, { passive: true });
  let scrollP = 0, shownP = 0, t0 = performance.now(), running = !document.hidden;
  document.addEventListener('visibilitychange', () => { running = !document.hidden; if (running) requestAnimationFrame(frame); });
  const readScroll = () => { scrollP = Math.min(1, Math.max(0, scrollY / (H * 0.85))); };
  addEventListener('scroll', readScroll, { passive: true }); readScroll(); shownP = scrollP;

  function frame(now) {
    const t = (now - t0) / 1000;
    shownP += (scrollP - shownP) * 0.08;
    layout(shownP);
    mouse.x += (mouse.tx - mouse.x) * 0.05; mouse.y += (mouse.ty - mouse.y) * 0.05;
    const intro = Math.min(1, t / 1.8), ie = 1 - Math.pow(1 - intro, 3);
    // медленное вращение, быстрый «заход» на старте, наклон за курсором и лёгкое покачивание
    coin.rotation.y = t * 0.45 + (1 - ie) * 6 + mouse.x * 0.6;
    coin.rotation.x = mouse.y * 0.45 + Math.sin(t * 0.7) * 0.06;
    coin.rotation.z = Math.sin(t * 0.5) * 0.04;
    holder.position.y += Math.sin(t * 1.1) * 0.03;
    holder.scale.multiplyScalar(0.35 + 0.65 * ie);
    if (ready) renderer.render(scene, camera);
    if (running && !reduce) requestAnimationFrame(frame);
  }
  if (reduce) { t0 = performance.now() - 4000; const once = () => { if (!ready) return setTimeout(once, 100); frame(performance.now()); }; once(); addEventListener('scroll', () => frame(performance.now()), { passive: true }); }
  else requestAnimationFrame(frame);
})();
