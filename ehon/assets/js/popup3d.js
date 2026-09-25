// 飛び出す絵本の立体舞台（three.js）
// 本を開くと、台紙に立てた紙のパーツが起きあがる。光と影、紙の切り口、かすみ、視点の動きで奥行きを出す。
import * as THREE from '../vendor/three/three.module.min.js';
import { artURL } from './common.js';

// 単位は cm。台紙は x: -17〜17、z: -12（のど）〜6（手前）
const PAGE_W = 34;
const PAGE_D = 18;
const BACK_Z = -12;
const FRONT_Z = BACK_Z + PAGE_D;
const PAPER = '#fbf6ea';
const OPEN_TIME = 1.2; // 本が開ききるまで
const clamp01 = (v) => Math.min(1, Math.max(0, v));
const lerp = (a, b, t) => a + (b - a) * t;
const easeInOut = (t) => (t < 0.5 ? 2 * t * t : 1 - (-2 * t + 2) ** 2 / 2);
const easeOut = (t) => 1 - (1 - t) ** 3;
const spring = (t) => (t >= 1 ? 1 : 1 - Math.exp(-5 * t) * Math.cos(9 * t)); // 紙が起きあがって少しゆれる
const easeOutBack = (t) => 1 + 2.2 * (t - 1) ** 3 + 1.2 * (t - 1) ** 2;

/* ---------- 紙の素材づくり（canvas） ---------- */

const makeCanvas = (w, h) => Object.assign(document.createElement('canvas'), { width: w, height: h });

let grainCanvas = null;
function grain() {
  if (grainCanvas) return grainCanvas;
  const c = makeCanvas(256, 256);
  const ctx = c.getContext('2d');
  const img = ctx.createImageData(256, 256);
  for (let i = 0; i < img.data.length; i += 4) {
    const v = 228 + Math.random() * 27;
    img.data[i] = v;
    img.data[i + 1] = v - 2;
    img.data[i + 2] = v - 7;
    img.data[i + 3] = 255;
  }
  ctx.putImageData(img, 0, 0);
  // 和紙の繊維
  ctx.lineCap = 'round';
  for (let k = 0; k < 70; k++) {
    ctx.strokeStyle = `rgba(140,118,88,${0.06 + Math.random() * 0.08})`;
    ctx.lineWidth = 0.6 + Math.random() * 0.8;
    const x = Math.random() * 256;
    const y = Math.random() * 256;
    const a = Math.random() * Math.PI * 2;
    const l = 6 + Math.random() * 18;
    ctx.beginPath();
    ctx.moveTo(x, y);
    ctx.quadraticCurveTo(x + Math.cos(a + 0.6) * l * 0.5, y + Math.sin(a + 0.6) * l * 0.5, x + Math.cos(a) * l, y + Math.sin(a) * l);
    ctx.stroke();
  }
  return (grainCanvas = c);
}

function applyGrain(ctx, w, h, strength = 1) {
  const g = makeCanvas(w, h);
  const gc = g.getContext('2d');
  gc.drawImage(ctx.canvas, 0, 0);
  gc.globalCompositeOperation = 'source-in';
  gc.fillStyle = gc.createPattern(grain(), 'repeat');
  gc.fillRect(0, 0, w, h);
  ctx.save();
  ctx.globalAlpha = strength;
  ctx.globalCompositeOperation = 'multiply';
  ctx.drawImage(g, 0, 0);
  ctx.restore();
}

const artCache = new Map();
function loadArt(url) {
  if (!artCache.has(url)) {
    artCache.set(url, (async () => {
      if (url.endsWith('.svg')) {
        // viewBox の大きさを幅・高さとして書きこみ、どのブラウザでも同じ比率で描けるようにする
        const text = await (await fetch(url)).text();
        const [, , vw, vh] = text.match(/viewBox="([^"]+)"/)[1].trim().split(/[\s,]+/).map(Number);
        const sized = text.replace('<svg ', `<svg width="${vw}" height="${vh}" `);
        const img = await decodeImage(URL.createObjectURL(new Blob([sized], { type: 'image/svg+xml' })));
        return { img, w: vw, h: vh };
      }
      const img = await decodeImage(url);
      return { img, w: img.naturalWidth, h: img.naturalHeight };
    })());
  }
  return artCache.get(url);
}

function decodeImage(src) {
  return new Promise((resolve, reject) => {
    const img = new Image();
    img.onload = () => resolve(img);
    img.onerror = () => reject(new Error(`画像を読みこめません: ${src}`));
    img.src = src;
  });
}

// 絵を「切り抜いた紙」にする：白い切り口、切り口の段差の影、和紙の地合い、遠くのかすみ
function cutPaper(art, { widthCm, density = 64, border = 0.1, blur = 0, haze = 0, hazeColor = '#e8f0ec' }) {
  const W = Math.round(widthCm * density);
  const H = Math.round((W * art.h) / art.w);
  const r = border ? Math.max(2, Math.round(border * density)) : 0;
  const blurPx = blur * density * 0.03;
  const pad = r + Math.ceil(blurPx * 2) + 3;
  const cw = W + pad * 2;
  const ch = H + pad * 2;

  const flat = makeCanvas(cw, ch);
  const fc = flat.getContext('2d');
  if (blurPx) fc.filter = `blur(${blurPx}px)`;
  fc.drawImage(art.img, pad, pad, W, H);
  if (haze) {
    fc.filter = 'none';
    fc.globalCompositeOperation = 'source-atop';
    fc.globalAlpha = haze;
    fc.fillStyle = hazeColor;
    fc.fillRect(0, 0, cw, ch);
  }

  const out = makeCanvas(cw, ch);
  const oc = out.getContext('2d');
  if (r) {
    for (let k = 0; k < 16; k++) {
      const t = (k / 16) * Math.PI * 2;
      oc.drawImage(flat, Math.cos(t) * r, Math.sin(t) * r);
    }
    oc.drawImage(flat, 0, 0);
    oc.globalCompositeOperation = 'source-in';
    oc.fillStyle = PAPER;
    oc.fillRect(0, 0, cw, ch);
    oc.globalCompositeOperation = 'source-over';
    oc.shadowColor = 'rgba(70,50,30,.38)';
    oc.shadowBlur = Math.max(2, r * 0.7);
    oc.shadowOffsetY = r * 0.3;
  }
  oc.drawImage(flat, 0, 0);
  oc.shadowColor = 'transparent';
  applyGrain(oc, cw, ch, 0.9);
  return { canvas: out, W, H, pad, density };
}

/* ---------- 台紙・背景の印刷 ---------- */

function paintSky(def) {
  const d = 40;
  const c = makeCanvas(PAGE_W * d, PAGE_D * d);
  const ctx = c.getContext('2d');
  const g = ctx.createLinearGradient(0, 0, 0, c.height);
  g.addColorStop(0, def.sky?.top ?? '#b7d7e6');
  g.addColorStop(0.75, def.sky?.bottom ?? '#eef3e6');
  g.addColorStop(1, def.sky?.bottom ?? '#eef3e6');
  ctx.fillStyle = g;
  ctx.fillRect(0, 0, c.width, c.height);
  if (def.sky?.sun) {
    const [sx, sy] = def.sky.sun;
    const x = sx * c.width;
    const y = sy * c.height;
    const glow = ctx.createRadialGradient(x, y, 20, x, y, 150);
    glow.addColorStop(0, 'rgba(255,236,170,.9)');
    glow.addColorStop(1, 'rgba(255,236,170,0)');
    ctx.fillStyle = glow;
    ctx.fillRect(0, 0, c.width, c.height);
    ctx.fillStyle = '#f4b93f';
    ctx.beginPath();
    ctx.arc(x, y, 46, 0, Math.PI * 2);
    ctx.fill();
  }
  // うすく刷った遠山
  ctx.filter = 'blur(3px)';
  ctx.fillStyle = 'rgba(160,190,196,.55)';
  ctx.beginPath();
  ctx.moveTo(0, c.height);
  for (let x = 0; x <= c.width; x += 20) {
    ctx.lineTo(x, c.height * 0.62 - Math.sin(x / 140) * 40 - Math.sin(x / 57) * 14);
  }
  ctx.lineTo(c.width, c.height);
  ctx.fill();
  ctx.filter = 'none';
  // のど（折り目）の陰
  const gutter = ctx.createLinearGradient(0, c.height, 0, c.height - 60);
  gutter.addColorStop(0, 'rgba(60,45,30,.28)');
  gutter.addColorStop(1, 'rgba(60,45,30,0)');
  ctx.fillStyle = gutter;
  ctx.fillRect(0, c.height - 60, c.width, 60);
  applyGrain(ctx, c.width, c.height, 0.7);
  return c;
}

function paintGround(def) {
  const d = 40;
  const c = makeCanvas(PAGE_W * d, PAGE_D * d);
  const ctx = c.getContext('2d');
  const row = (z) => (z - BACK_Z) * d;
  const g = ctx.createLinearGradient(0, 0, 0, c.height);
  g.addColorStop(0, '#86b36a');
  g.addColorStop(1, '#a3c97f');
  ctx.fillStyle = g;
  ctx.fillRect(0, 0, c.width, c.height);
  // 草のむら
  for (let k = 0; k < 900; k++) {
    ctx.fillStyle = Math.random() < 0.5 ? 'rgba(120,165,90,.35)' : 'rgba(180,210,140,.3)';
    const x = Math.random() * c.width;
    const y = Math.random() * c.height;
    ctx.beginPath();
    ctx.ellipse(x, y, 6 + Math.random() * 14, 2 + Math.random() * 4, 0, 0, Math.PI * 2);
    ctx.fill();
  }
  const river = def.river;
  if (river) {
    // 川べりの砂
    ctx.fillStyle = '#dccb98';
    ctx.fillRect(0, row(river.far) - 22, c.width, 30);
    ctx.fillRect(0, row(river.near) - 8, c.width, 34);
    ctx.fillStyle = 'rgba(160,140,95,.35)';
    for (let k = 0; k < 160; k++) {
      const y = Math.random() < 0.5 ? row(river.far) - 18 + Math.random() * 22 : row(river.near) + Math.random() * 24;
      ctx.beginPath();
      ctx.arc(Math.random() * c.width, y, 1 + Math.random() * 2.5, 0, Math.PI * 2);
      ctx.fill();
    }
  }
  // のどの陰
  const gutter = ctx.createLinearGradient(0, 0, 0, 70);
  gutter.addColorStop(0, 'rgba(60,45,30,.3)');
  gutter.addColorStop(1, 'rgba(60,45,30,0)');
  ctx.fillStyle = gutter;
  ctx.fillRect(0, 0, c.width, 70);
  applyGrain(ctx, c.width, c.height, 0.7);
  return c;
}

function paintRiver() {
  const c = makeCanvas(1024, 200);
  const ctx = c.getContext('2d');
  const g = ctx.createLinearGradient(0, 0, 0, c.height);
  g.addColorStop(0, '#a6d2e4');
  g.addColorStop(1, '#5f9fc6');
  ctx.fillStyle = g;
  ctx.fillRect(0, 0, c.width, c.height);
  ctx.strokeStyle = 'rgba(255,255,255,.75)';
  ctx.lineCap = 'round';
  for (let k = 0; k < 46; k++) {
    const x = Math.random() * c.width;
    const y = 12 + Math.random() * (c.height - 24);
    const s = 0.6 + (y / c.height) * 0.8; // 手前ほど大きい波
    ctx.lineWidth = 2.5 * s;
    for (const dx of [0, -c.width, c.width]) {
      ctx.beginPath();
      ctx.moveTo(x + dx, y);
      ctx.quadraticCurveTo(x + dx + 10 * s, y - 7 * s, x + dx + 20 * s, y);
      ctx.quadraticCurveTo(x + dx + 30 * s, y - 7 * s, x + dx + 40 * s, y);
      ctx.stroke();
    }
  }
  applyGrain(ctx, c.width, c.height, 0.5);
  return c;
}

function paintCloth() {
  // 藍の風呂敷（青海波）
  const s = 64;
  const c = makeCanvas(s * 4, s * 2);
  const ctx = c.getContext('2d');
  ctx.fillStyle = '#22304a';
  ctx.fillRect(0, 0, c.width, c.height);
  for (let row = -1; row <= 4; row++) {
    for (let col = -1; col <= 4; col++) {
      const cx = col * s + (row % 2 ? s / 2 : 0);
      const cy = row * (s / 2) + s / 2;
      for (let k = 4; k >= 1; k--) {
        ctx.fillStyle = k % 2 ? '#22304a' : '#2b3b5a';
        ctx.beginPath();
        ctx.arc(cx, cy, (s / 2) * (k / 4), Math.PI, 0);
        ctx.fill();
      }
    }
  }
  return c;
}

/* ---------- 舞台 ---------- */

export async function createPopupStage(container, def, { base = '', speak = () => '', reduceMotion = false } = {}) {
  const renderer = new THREE.WebGLRenderer({ antialias: true });
  renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
  renderer.outputColorSpace = THREE.SRGBColorSpace;
  renderer.shadowMap.enabled = true;
  renderer.shadowMap.type = THREE.PCFSoftShadowMap;
  const maxAniso = renderer.capabilities.getMaxAnisotropy();
  container.append(renderer.domElement);

  const overlay = document.createElement('div');
  overlay.className = 'stage3d-overlay';
  container.append(overlay);

  const scene = new THREE.Scene();
  scene.background = new THREE.Color('#1c2740');

  const cam = def.camera ?? {};
  const camera = new THREE.PerspectiveCamera(cam.fov ?? 33, 16 / 9, 0.5, 200);
  const camBase = new THREE.Vector3(...(cam.position ?? [0, 10.5, 31]));
  const camTarget = new THREE.Vector3(...(cam.target ?? [0, 4.6, -3]));
  camera.position.copy(camBase);
  camera.lookAt(camTarget);

  // 光：やわらかい空の光 ＋ 左手前上からの日ざし（影を落とす）
  scene.add(new THREE.HemisphereLight('#f6f9ff', '#a08e6c', def.light?.ambient ?? 2.1));
  const sun = new THREE.DirectionalLight('#fff0d8', def.light?.sun ?? 2.3);
  sun.position.set(-13, 24, 17);
  sun.target.position.set(0, 2, -3);
  sun.castShadow = true;
  sun.shadow.mapSize.set(2048, 2048);
  Object.assign(sun.shadow.camera, { left: -24, right: 24, top: 24, bottom: -24, near: 1, far: 90 });
  sun.shadow.bias = -0.0004;
  sun.shadow.normalBias = 0.03;
  scene.add(sun, sun.target);

  const tex = (canvas, repeat) => {
    const t = new THREE.CanvasTexture(canvas);
    t.colorSpace = THREE.SRGBColorSpace;
    t.anisotropy = maxAniso;
    if (repeat) {
      t.wrapS = t.wrapT = THREE.RepeatWrapping;
      t.repeat.set(...repeat);
    }
    return t;
  };

  // 机（風呂敷）
  const desk = new THREE.Mesh(new THREE.PlaneGeometry(200, 200), new THREE.MeshLambertMaterial({ map: tex(paintCloth(), [40, 80]) }));
  desk.rotation.x = -Math.PI / 2;
  desk.position.y = -0.86;
  desk.receiveShadow = true;
  scene.add(desk);

  // 本：表紙・紙の束・台紙・背のページ
  const coverMat = new THREE.MeshLambertMaterial({ color: def.cover ?? '#9c3d2e' });
  const edgeMat = new THREE.MeshLambertMaterial({ color: '#efe4cc' });
  const groundMat = new THREE.MeshLambertMaterial({ map: tex(paintGround(def)) });
  // 背のページは印刷の色が沈まないよう、少し自分で明るくする
  const skyTex = tex(paintSky(def));
  const skyMat = new THREE.MeshLambertMaterial({ map: skyTex, emissive: '#ffffff', emissiveMap: skyTex, emissiveIntensity: 0.35 });
  const midZ = BACK_Z + PAGE_D / 2;

  const cover = new THREE.Mesh(new THREE.BoxGeometry(PAGE_W + 1.6, 0.5, PAGE_D + 0.8), coverMat);
  cover.position.set(0, -0.6, midZ + 0.2);
  const block = new THREE.Mesh(new THREE.BoxGeometry(PAGE_W, 0.34, PAGE_D), [edgeMat, edgeMat, groundMat, edgeMat, edgeMat, edgeMat]);
  block.position.set(0, -0.17, midZ);
  for (const m of [cover, block]) { m.castShadow = true; m.receiveShadow = true; }
  scene.add(cover, block);

  const backPage = new THREE.Group();
  backPage.position.set(0, 0, BACK_Z);
  const backBlock = new THREE.Mesh(new THREE.BoxGeometry(PAGE_W, PAGE_D, 0.34), [edgeMat, edgeMat, edgeMat, edgeMat, skyMat, edgeMat]);
  backBlock.position.set(0, PAGE_D / 2, -0.17);
  const backCover = new THREE.Mesh(new THREE.BoxGeometry(PAGE_W + 1.6, PAGE_D + 0.8, 0.5), coverMat);
  backCover.position.set(0, PAGE_D / 2 + 0.2, -0.6);
  for (const m of [backBlock, backCover]) { m.castShadow = true; m.receiveShadow = true; }
  backPage.add(backBlock, backCover);
  scene.add(backPage);

  // 川（台紙に刷った水面。流れる）
  let riverTex = null;
  if (def.river) {
    const depth = def.river.near - def.river.far;
    riverTex = tex(paintRiver(), [2.2, 1]);
    const river = new THREE.Mesh(
      new THREE.PlaneGeometry(PAGE_W - 0.4, depth),
      new THREE.MeshStandardMaterial({ map: riverTex, roughness: 0.35, metalness: 0 }),
    );
    river.rotation.x = -Math.PI / 2;
    river.position.set(0, 0.015, def.river.far + depth / 2);
    river.receiveShadow = true;
    scene.add(river);
  }

  /* 紙のパーツ */
  const makePart = async (spec, pivot = [0.5, 1]) => {
    const art = await loadArt(artURL(spec.src, base));
    const paper = cutPaper(art, {
      widthCm: spec.w,
      density: spec.density ?? (spec.w > 20 ? 48 : 80),
      border: spec.border ?? 0.1,
      blur: spec.blur ?? 0,
      haze: spec.haze ?? 0,
      hazeColor: def.sky?.bottom,
    });
    const { canvas: c, W, H, pad, density } = paper;
    const geo = new THREE.PlaneGeometry(c.width / density, c.height / density);
    const px = pad + pivot[0] * W;
    const py = pad + pivot[1] * H;
    geo.translate((c.width / 2 - px) / density, (py - c.height / 2) / density, 0);
    const map = tex(c);
    const mesh = new THREE.Mesh(geo, new THREE.MeshLambertMaterial({ map, alphaTest: 0.5, alphaToCoverage: true, side: THREE.DoubleSide }));
    mesh.customDepthMaterial = new THREE.MeshDepthMaterial({ depthPacking: THREE.RGBADepthPacking, map, alphaTest: 0.5 });
    mesh.castShadow = true;
    mesh.receiveShadow = true;
    mesh.userData.paper = c;
    if (spec.sy) mesh.scale.y = spec.sy;
    return mesh;
  };

  const cards = [];
  const byId = new Map();
  await Promise.all(def.cards.map(async (spec, index) => {
    const hinge = new THREE.Group(); // 下の辺が折り目
    const inner = new THREE.Group(); // ゆれ・はね
    hinge.add(inner);
    const card = { spec, index, hinge, inner, parts: {}, alt: {}, state: {}, phase: (index * 1.7) % (Math.PI * 2) };
    if (spec.parts) {
      for (const p of spec.parts) {
        const g = new THREE.Group();
        g.position.set(p.at?.[0] ?? 0, p.at?.[1] ?? 0, p.z ?? 0);
        const mesh = await makePart({ ...p, border: p.border ?? spec.border }, p.pivot);
        g.add(mesh);
        if (p.alt) {
          const altMesh = await makePart({ ...p, src: p.alt }, p.pivot);
          altMesh.visible = false;
          g.add(altMesh);
          card.alt[p.id] = { normal: mesh, alt: altMesh };
        }
        inner.add(g);
        card.parts[p.id] = g;
      }
    } else {
      inner.add(await makePart(spec));
    }
    if (spec.attach === 'back') {
      hinge.position.set(spec.x ?? 0, spec.y ?? 0, spec.z ?? 0.3);
      backPage.add(hinge);
    } else {
      hinge.position.set(spec.x ?? 0, spec.lift ?? 0, spec.z ?? 0);
      scene.add(hinge);
    }
    card.baseY = hinge.position.y;
    cards[index] = card;
    byId.set(spec.id, card);
  }));

  // 奥のパーツから順に起きあがる
  const standing = cards.filter((c) => c.spec.attach !== 'back' && c.spec.pop !== false).sort((a, b) => a.spec.z - b.spec.z);
  standing.forEach((c, rank) => { c.popDelay = 0.3 + rank * 0.07; });

  /* ---------- 吹き出し ---------- */
  const bubbles = new Map();
  const tmp = new THREE.Vector3();
  function say(card, key) {
    const text = speak(key);
    if (!text) return;
    bubbles.get(card)?.el.remove();
    const el = document.createElement('span');
    el.className = 'bubble bubble-3d';
    el.textContent = text;
    overlay.append(el);
    const entry = { el, until: time + 1.9 };
    bubbles.set(card, entry);
  }
  function placeBubbles() {
    const rect = renderer.domElement.getBoundingClientRect();
    for (const [card, b] of bubbles) {
      if (time > b.until) { b.el.remove(); bubbles.delete(card); continue; }
      const box = new THREE.Box3().setFromObject(card.inner);
      tmp.set((box.min.x + box.max.x) / 2, box.max.y + 0.3, (box.min.z + box.max.z) / 2).project(camera);
      b.el.style.left = `${((tmp.x + 1) / 2) * rect.width}px`;
      b.el.style.top = `${((1 - tmp.y) / 2) * rect.height}px`;
    }
  }

  /* ---------- 動き ---------- */
  const events = new Map();
  const emit = (name) => (events.get(name) ?? []).forEach((fn) => fn());
  const on = (name, fn) => events.set(name, [...(events.get(name) ?? []), fn]);

  const BEHAVIORS = {
    sway(c, t) { c.inner.rotation.z = Math.sin(t * 0.9 + c.phase) * 0.025; },
    drift(c, t) { c.inner.position.x = Math.sin(t * 0.22 + c.phase) * 0.9; },
    ripple(c, t) { c.inner.position.x = Math.sin(t * 0.7 + c.phase) * 0.4; },
    // 川上（左）から、水の中から浮かびあがって流れてくる桃
    'peach-drift'(c, t, st) {
      const p = { from: -16, to: -2.5, delay: 0.3, duration: 5.5, lift: 0.1, ...c.spec.params };
      const k = clamp01((st - p.delay) / p.duration);
      const rise = easeOut(clamp01((st - p.delay) / 1.4));
      c.hinge.position.x = lerp(p.from, p.to, easeOut(k));
      c.hinge.position.y = lerp(-3.2, p.lift, rise) + Math.sin(t * 2.2) * 0.12;
      c.inner.rotation.z = Math.sin(t * 1.7) * 0.07 + (1 - k) * Math.sin(t * 3.1) * 0.05;
      if (k >= 1 && !c.state.arrived) {
        c.state.arrived = true;
        emit('peach-arrived');
      }
    },
    // せんたく → 桃に気づいて顔をあげる
    'grandma-wash'(c, t) {
      const n = c.state.noticeAt == null ? 0 : clamp01((t - c.state.noticeAt) / 0.55);
      const react = n ? easeOutBack(n) : 0;
      const scrub = Math.sin(t * 5.5) * (1 - n);
      if (c.parts.arm) c.parts.arm.rotation.z = scrub * 0.13 - react * 0.45;
      if (c.parts.head) c.parts.head.rotation.z = Math.sin(t * 5.5 + 0.8) * 0.035 * (1 - n) - react * 0.2;
      c.inner.rotation.z = Math.sin(t * 5.5) * 0.008 * (1 - n) - react * 0.03;
    },
  };

  on('peach-arrived', () => {
    const g = byId.get('grandma');
    if (!g) return;
    g.state.noticeAt = time;
    if (g.alt.head) { g.alt.head.normal.visible = false; g.alt.head.alt.visible = true; }
    say(g, g.spec.tap);
  });

  /* ---------- 時間 ---------- */
  let time = 0;
  let last = performance.now();

  function reset() {
    time = reduceMotion ? OPEN_TIME + 8 : 0;
    for (const c of cards) {
      c.state = {};
      for (const pair of Object.values(c.alt)) { pair.normal.visible = true; pair.alt.visible = false; }
    }
    for (const b of bubbles.values()) b.el.remove();
    bubbles.clear();
    if (reduceMotion) {
      // 動きを減らす設定：最初から完成した場面を見せる
      const g = byId.get('grandma');
      if (g?.alt.head) { g.alt.head.normal.visible = false; g.alt.head.alt.visible = true; g.state.noticeAt = -10; }
      const peach = byId.get('peach');
      if (peach) peach.state.arrived = true;
    }
  }

  function update(dt) {
    time += dt;
    const open = easeInOut(clamp01(time / OPEN_TIME));
    backPage.rotation.x = (1 - open) * 1.35;
    for (const c of standing) {
      const k = clamp01((time - c.popDelay) / 0.8);
      c.hinge.rotation.x = -Math.PI / 2 * (1 - spring(k));
    }
    const st = time - OPEN_TIME;
    for (const c of cards) {
      const fn = BEHAVIORS[c.spec.behavior];
      if (fn && !(reduceMotion && c.spec.behavior !== 'peach-drift' && c.spec.behavior !== 'grandma-wash')) fn(c, time, st);
      if (c.state.jumpAt != null) {
        const j = clamp01((time - c.state.jumpAt) / 0.5);
        c.inner.position.y = Math.sin(j * Math.PI) * 0.8;
      }
    }
    if (riverTex && !reduceMotion) riverTex.offset.x -= dt * 0.035;

    // 視点：マウス・指の位置に合わせて少しまわりこむ
    const idle = reduceMotion ? 0 : Math.sin(time * 0.35) * 0.25;
    const tx = camBase.x + look.x * 2.2 + idle;
    const ty = camBase.y - look.y * 1.2;
    camera.position.x += (tx - camera.position.x) * 0.06;
    camera.position.y += (ty - camera.position.y) * 0.06;
    camera.position.z = camBase.z;
    camera.lookAt(camTarget);
  }

  /* ---------- 操作 ---------- */
  const look = { x: 0, y: 0 };
  const raycaster = new THREE.Raycaster();
  const pointer = new THREE.Vector2();
  let down = null;

  const tappable = () => cards.filter((c) => c.spec.tap);
  function pick(e) {
    const rect = renderer.domElement.getBoundingClientRect();
    pointer.set(((e.clientX - rect.left) / rect.width) * 2 - 1, -((e.clientY - rect.top) / rect.height) * 2 + 1);
    raycaster.setFromCamera(pointer, camera);
    const meshes = [];
    for (const c of tappable()) c.inner.traverse((o) => { if (o.isMesh && o.visible) meshes.push([o, c]); });
    const hits = raycaster.intersectObjects(meshes.map(([m]) => m), false);
    for (const hit of hits) {
      // 透明なところは素通りさせる（紙の形どおりにタップを判定）
      const c = hit.object.userData.paper;
      hit.object.userData.alpha ??= c.getContext('2d').getImageData(0, 0, c.width, c.height).data;
      const x = Math.min(c.width - 1, Math.floor(hit.uv.x * c.width));
      const y = Math.min(c.height - 1, Math.floor((1 - hit.uv.y) * c.height));
      if (hit.object.userData.alpha[(y * c.width + x) * 4 + 3] > 60) return meshes.find(([m]) => m === hit.object)[1];
    }
    return null;
  }

  const el = renderer.domElement;
  el.addEventListener('pointermove', (e) => {
    const rect = el.getBoundingClientRect();
    if (e.pointerType === 'mouse' || down) {
      look.x = ((e.clientX - rect.left) / rect.width) * 2 - 1;
      look.y = ((e.clientY - rect.top) / rect.height) * 2 - 1;
    }
    if (e.pointerType === 'mouse') el.style.cursor = pick(e) ? 'pointer' : '';
  });
  el.addEventListener('pointerleave', () => { look.x = 0; look.y = 0; });
  el.addEventListener('pointerdown', (e) => { down = { x: e.clientX, y: e.clientY }; });
  el.addEventListener('pointerup', (e) => {
    const moved = down && Math.hypot(e.clientX - down.x, e.clientY - down.y) > 8;
    down = null;
    if (e.pointerType !== 'mouse') { look.x = 0; look.y = 0; }
    if (moved) return;
    const c = pick(e);
    if (!c) return;
    c.state.jumpAt = time;
    say(c, c.spec.tap);
  });

  /* ---------- 描画 ---------- */
  function resize() {
    const { width, height } = container.getBoundingClientRect();
    if (!width || !height) return;
    renderer.setSize(width, height, false);
    camera.aspect = width / height;
    camera.updateProjectionMatrix();
  }
  const ro = new ResizeObserver(resize);
  ro.observe(container);
  resize();

  let raf = 0;
  function frame(now) {
    const dt = Math.min(0.05, (now - last) / 1000);
    last = now;
    update(dt);
    renderer.render(scene, camera);
    placeBubbles();
    raf = requestAnimationFrame(frame);
  }
  reset();
  raf = requestAnimationFrame(frame);

  return {
    replay() { reset(); },
    // 確認用：指定した秒数まで一気に進める
    seek(t) {
      reset();
      while (time < t) update(1 / 30);
    },
    destroy() {
      cancelAnimationFrame(raf);
      ro.disconnect();
      renderer.dispose();
      container.replaceChildren();
    },
  };
}
