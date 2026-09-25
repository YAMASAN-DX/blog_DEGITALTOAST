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

// 点列を Catmull-Rom でなめらかにつなぐ（[x, z] の配列）
function sampleCurve(pts, per = 12) {
  if (pts.length < 3) return pts.map((p) => [...p]);
  const out = [];
  for (let i = 0; i < pts.length - 1; i++) {
    const p0 = pts[Math.max(i - 1, 0)];
    const p1 = pts[i];
    const p2 = pts[i + 1];
    const p3 = pts[Math.min(i + 2, pts.length - 1)];
    for (let k = 0; k < per; k++) {
      const t = k / per;
      out.push([0, 1].map((j) => 0.5 * (2 * p1[j] + (p2[j] - p0[j]) * t
        + (2 * p0[j] - 5 * p1[j] + 4 * p2[j] - p3[j]) * t * t + (3 * p1[j] - p0[j] - 3 * p2[j] + p3[j]) * t ** 3)));
    }
  }
  out.push([...pts[pts.length - 1]]);
  return out;
}

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
function cutPaper(art, { widthCm, density = 64, border = 0.1, blur = 0, haze = 0, hazeColor = '#e8f0ec', shade = 0 }) {
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
  if (shade) {
    // 奥の手足など：少し暗くして、手前の部品と見分けやすくする
    fc.filter = 'none';
    fc.globalCompositeOperation = 'source-atop';
    fc.globalAlpha = shade;
    fc.fillStyle = '#2c2440';
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
  // うすい雲のすじ
  ctx.filter = 'blur(8px)';
  ctx.fillStyle = 'rgba(255,255,255,.45)';
  for (const [x, y, rx, ry] of [[0.18, 0.3, 160, 14], [0.42, 0.22, 120, 10], [0.62, 0.4, 190, 16], [0.3, 0.46, 140, 11]]) {
    ctx.beginPath();
    ctx.ellipse(x * c.width, y * c.height, rx, ry, 0, 0, Math.PI * 2);
    ctx.fill();
  }
  ctx.filter = 'none';
  // 遠くを飛ぶ鳥
  ctx.strokeStyle = 'rgba(74,58,48,.55)';
  ctx.lineWidth = 3;
  ctx.lineCap = 'round';
  for (const [x, y, s] of [[0.3, 0.36, 1], [0.34, 0.33, 0.8], [0.37, 0.38, 0.7]]) {
    const bx = x * c.width;
    const by = y * c.height;
    ctx.beginPath();
    ctx.moveTo(bx - 12 * s, by - 2 * s);
    ctx.quadraticCurveTo(bx - 5 * s, by - 8 * s, bx, by);
    ctx.quadraticCurveTo(bx + 5 * s, by - 8 * s, bx + 12 * s, by - 2 * s);
    ctx.stroke();
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
  const W = c.width;
  const H = c.height;
  const row = (z) => (z - BACK_Z) * d;
  const g = ctx.createLinearGradient(0, 0, 0, H);
  g.addColorStop(0, '#86b36a');
  g.addColorStop(1, '#a3c97f');
  ctx.fillStyle = g;
  ctx.fillRect(0, 0, W, H);
  // 草むらの濃淡
  for (let k = 0; k < 700; k++) {
    ctx.fillStyle = Math.random() < 0.5 ? 'rgba(115,160,85,.35)' : 'rgba(180,210,140,.3)';
    ctx.beginPath();
    ctx.ellipse(Math.random() * W, Math.random() * H, 8 + Math.random() * 16, 2 + Math.random() * 5, 0, 0, Math.PI * 2);
    ctx.fill();
  }
  // 小川の岸と、土の道（この上には草や花を描かない）
  const toPx = ([x, z]) => [(x + PAGE_W / 2) * d, (z - BACK_Z) * d];
  const bare = [];
  const curve = (pts) => {
    const p = new Path2D();
    sampleCurve(pts).map(toPx).forEach(([x, y], i) => (i ? p.lineTo(x, y) : p.moveTo(x, y)));
    return p;
  };
  ctx.lineCap = 'round';
  ctx.lineJoin = 'round';
  if (def.stream) {
    const p = curve(def.stream.points);
    const w = (def.stream.width + 1.3) * d;
    ctx.strokeStyle = '#d4c291';
    ctx.lineWidth = w + 8;
    ctx.stroke(p);
    ctx.strokeStyle = '#dfcf9f';
    ctx.lineWidth = w;
    ctx.stroke(p);
    bare.push([p, w + 4]);
    // 岸の小石
    const pts = sampleCurve(def.stream.points, 30);
    const stones = ['#b9b2a4', '#a39c8f', '#c9c1b0', '#948d80'];
    for (let k = 0; k < 260; k++) {
      const i = Math.floor(Math.random() * (pts.length - 1));
      const [ax, az] = pts[i];
      const [bx, bz] = pts[i + 1];
      const len = Math.hypot(bx - ax, bz - az) || 1;
      const side = Math.random() < 0.5 ? -1 : 1;
      const off = (def.stream.width / 2 + 0.05 + Math.random() * 0.55) * side;
      const [x, y] = toPx([ax - ((bz - az) / len) * off, az + ((bx - ax) / len) * off]);
      const r = 1.6 + Math.random() * 3.2;
      ctx.fillStyle = stones[k % stones.length];
      ctx.strokeStyle = 'rgba(74,58,48,.55)';
      ctx.lineWidth = 1.2;
      ctx.beginPath();
      ctx.ellipse(x, y, r * 1.4, r, 0, 0, Math.PI * 2);
      ctx.fill();
      ctx.stroke();
    }
  }
  for (const path of def.paths ?? []) {
    const p = curve(path.points);
    const w = (path.width ?? 1.4) * d;
    ctx.strokeStyle = '#b39a68';
    ctx.lineWidth = w + 6;
    ctx.stroke(p);
    ctx.strokeStyle = '#e0cc9b';
    ctx.lineWidth = w;
    ctx.stroke(p);
    ctx.strokeStyle = 'rgba(255,248,226,.4)';
    ctx.lineWidth = w * 0.35;
    ctx.stroke(p);
    bare.push([p, w * 0.8]);
    // 轍と小石
    const pts = sampleCurve(path.points, 30);
    ctx.fillStyle = 'rgba(150,122,80,.45)';
    for (let k = 0; k < pts.length * 3; k++) {
      const [x, y] = toPx(pts[Math.floor(Math.random() * pts.length)]);
      ctx.beginPath();
      ctx.ellipse(x + (Math.random() - 0.5) * w * 0.8, y + (Math.random() - 0.5) * w * 0.5, 1.5 + Math.random() * 2, 1 + Math.random(), 0, 0, Math.PI * 2);
      ctx.fill();
    }
  }
  const onBare = (x, y) => bare.some(([p, w]) => {
    ctx.lineWidth = w;
    return ctx.isPointInStroke(p, x, y);
  });
  // 草の葉（手前ほど長く太い）
  ctx.lineCap = 'round';
  const blades = ['rgba(90,140,72,.6)', 'rgba(128,178,98,.55)', 'rgba(168,208,128,.5)'];
  for (let k = 0; k < 5200; k++) {
    const x = Math.random() * W;
    const y = Math.random() * H;
    if (bare.length && onBare(x, y)) continue;
    const depth = 0.6 + y / H;
    const l = (4 + Math.random() * 6) * depth;
    ctx.strokeStyle = blades[k % 3];
    ctx.lineWidth = depth;
    ctx.beginPath();
    ctx.moveTo(x, y);
    ctx.quadraticCurveTo(x + 1, y - l * 0.6, x + (Math.random() - 0.5) * 5, y - l);
    ctx.stroke();
  }
  // 野の花
  const petals = ['#fffaf0', '#f3cf4a', '#f2a6b9', '#c9b3e6'];
  for (let k = 0; k < 170; k++) {
    const x = Math.random() * W;
    const y = Math.random() * H;
    const z = y / d + BACK_Z;
    if (def.river && z > def.river.far - 0.9 && z < def.river.near + 0.9) continue;
    if (bare.length && onBare(x, y)) continue;
    const r = 2 + Math.random() * 2;
    ctx.fillStyle = petals[k % petals.length];
    for (let p = 0; p < 5; p++) {
      const a = (p / 5) * Math.PI * 2;
      ctx.beginPath();
      ctx.arc(x + Math.cos(a) * r, y + Math.sin(a) * r * 0.6, r * 0.7, 0, Math.PI * 2);
      ctx.fill();
    }
    ctx.fillStyle = '#e9a93a';
    ctx.beginPath();
    ctx.arc(x, y, r * 0.5, 0, Math.PI * 2);
    ctx.fill();
  }
  const river = def.river;
  if (river) {
    // 川べりの砂（ふちは波うたせる）
    const band = (top, bottom, seed) => {
      ctx.beginPath();
      ctx.moveTo(0, top(0));
      for (let x = 0; x <= W; x += 16) ctx.lineTo(x, top(x) + Math.sin(x / 37 + seed) * 4);
      for (let x = W; x >= 0; x -= 16) ctx.lineTo(x, bottom(x) + Math.sin(x / 29 + seed * 2) * 5);
      ctx.closePath();
      ctx.fill();
    };
    ctx.fillStyle = '#dccb98';
    band(() => row(river.far) - 24, () => row(river.far) + 6, 1);
    band(() => row(river.near) - 6, () => row(river.near) + 28, 2);
    // 小石
    const stones = ['#b9b2a4', '#a39c8f', '#c9c1b0', '#948d80'];
    for (let k = 0; k < 220; k++) {
      const onFar = k % 2 === 0;
      const x = Math.random() * W;
      const y = onFar ? row(river.far) - 20 + Math.random() * 22 : row(river.near) + Math.random() * 26;
      const r = 1.6 + Math.random() * 3.4;
      ctx.fillStyle = stones[k % stones.length];
      ctx.strokeStyle = 'rgba(74,58,48,.55)';
      ctx.lineWidth = 1.2;
      ctx.beginPath();
      ctx.ellipse(x, y, r * 1.4, r, 0, 0, Math.PI * 2);
      ctx.fill();
      ctx.stroke();
    }
  }
  // のどの陰
  const gutter = ctx.createLinearGradient(0, 0, 0, 70);
  gutter.addColorStop(0, 'rgba(60,45,30,.3)');
  gutter.addColorStop(1, 'rgba(60,45,30,0)');
  ctx.fillStyle = gutter;
  ctx.fillRect(0, 0, W, 70);
  applyGrain(ctx, W, H, 0.7);
  return c;
}

function paintRiver() {
  const c = makeCanvas(1024, 256);
  const ctx = c.getContext('2d');
  const W = c.width;
  const H = c.height;
  const g = ctx.createLinearGradient(0, 0, 0, H);
  g.addColorStop(0, '#b3dbe8');
  g.addColorStop(0.45, '#8cc3dc');
  g.addColorStop(1, '#5896bf');
  ctx.fillStyle = g;
  ctx.fillRect(0, 0, W, H);
  // 空のうつりこみ
  const sky = ctx.createLinearGradient(0, H * 0.12, 0, H * 0.58);
  sky.addColorStop(0, 'rgba(255,255,255,0)');
  sky.addColorStop(0.5, 'rgba(255,255,255,.22)');
  sky.addColorStop(1, 'rgba(255,255,255,0)');
  ctx.fillStyle = sky;
  ctx.fillRect(0, 0, W, H);
  // 流れのすじ（左右がつながるように3回ずつ描く）
  ctx.lineCap = 'round';
  for (let k = 0; k < 70; k++) {
    const y = 8 + Math.random() * (H - 16);
    const x = Math.random() * W;
    const len = 60 + Math.random() * 160;
    const s = 0.5 + y / H; // 手前ほど太い
    ctx.strokeStyle = `rgba(255,255,255,${0.16 + Math.random() * 0.22})`;
    ctx.lineWidth = 1.2 * s + Math.random();
    for (const dx of [-W, 0, W]) {
      ctx.beginPath();
      ctx.moveTo(x + dx, y);
      ctx.bezierCurveTo(x + dx + len * 0.3, y - 3 * s, x + dx + len * 0.7, y + 3 * s, x + dx + len, y);
      ctx.stroke();
    }
  }
  // 波がしら
  for (let k = 0; k < 26; k++) {
    const y = 20 + Math.random() * (H - 36);
    const x = Math.random() * W;
    const s = 0.6 + y / H;
    ctx.strokeStyle = 'rgba(255,255,255,.8)';
    ctx.lineWidth = 2.4 * s;
    for (const dx of [-W, 0, W]) {
      for (let j = 0; j < 2; j++) {
        ctx.beginPath();
        ctx.arc(x + dx + j * 16 * s, y, 8 * s, Math.PI * 1.15, Math.PI * 1.85);
        ctx.stroke();
      }
    }
  }
  // 岸ぎわの陰
  const far = ctx.createLinearGradient(0, 0, 0, 26);
  far.addColorStop(0, 'rgba(40,70,60,.35)');
  far.addColorStop(1, 'rgba(40,70,60,0)');
  ctx.fillStyle = far;
  ctx.fillRect(0, 0, W, 26);
  const near = ctx.createLinearGradient(0, H, 0, H - 30);
  near.addColorStop(0, 'rgba(30,60,80,.4)');
  near.addColorStop(1, 'rgba(30,60,80,0)');
  ctx.fillStyle = near;
  ctx.fillRect(0, H - 30, W, 30);
  // きらめき
  ctx.fillStyle = '#ffffff';
  for (let k = 0; k < 30; k++) {
    const x = Math.random() * W;
    const y = Math.random() * H * 0.6;
    const r = 2 + Math.random() * 3;
    ctx.beginPath();
    ctx.moveTo(x, y - r * 2); ctx.lineTo(x + r * 0.5, y); ctx.lineTo(x, y + r * 2); ctx.lineTo(x - r * 0.5, y);
    ctx.moveTo(x - r * 2, y); ctx.lineTo(x, y + r * 0.5); ctx.lineTo(x + r * 2, y); ctx.lineTo(x, y - r * 0.5);
    ctx.fill();
  }
  applyGrain(ctx, W, H, 0.4);
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

/* ---------- 立体の小道具：木のたらい ---------- */

const INK = '#4a3a30';

function toonGradient() {
  // 3段の陰影（イラストの塗りに合わせる）
  const data = new Uint8Array([150, 150, 150, 255, 210, 210, 210, 255, 255, 255, 255, 255]);
  const t = new THREE.DataTexture(data, 3, 1, THREE.RGBAFormat);
  t.minFilter = t.magFilter = THREE.NearestFilter;
  t.generateMipmaps = false;
  t.needsUpdate = true;
  return t;
}

function paintStaves(inside = false) {
  const c = makeCanvas(1024, 256);
  const ctx = c.getContext('2d');
  const n = 24;
  const w = c.width / n;
  const tones = inside ? ['#9c7446', '#a67c4c', '#94693f'] : ['#c0935c', '#cda06a', '#b58853'];
  for (let i = 0; i < n; i++) {
    const x = i * w;
    ctx.fillStyle = tones[i % 3];
    ctx.fillRect(x, 0, w + 1, c.height);
    // 木目
    ctx.strokeStyle = 'rgba(95,60,28,.3)';
    ctx.lineWidth = 1.3;
    for (let k = 0; k < 4; k++) {
      const x0 = x + 5 + ((k * 37 + i * 13) % Math.max(1, w - 10));
      ctx.beginPath();
      for (let y = 0; y <= c.height; y += 12) {
        const xx = x0 + Math.sin(y / 34 + k * 1.7 + i) * 2.4;
        if (y) ctx.lineTo(xx, y); else ctx.moveTo(xx, y);
      }
      ctx.stroke();
    }
    // 節
    if ((i * 7) % 5 === 0) {
      ctx.strokeStyle = 'rgba(95,60,28,.45)';
      ctx.beginPath();
      ctx.ellipse(x + w / 2, 60 + ((i * 53) % 130), 3.5, 8, 0, 0, Math.PI * 2);
      ctx.stroke();
    }
    ctx.fillStyle = 'rgba(55,32,14,.65)'; // 板の継ぎ目
    ctx.fillRect(x, 0, 2.5, c.height);
    ctx.fillStyle = 'rgba(255,238,205,.18)'; // 板の角に当たる光
    ctx.fillRect(x + 3, 0, 4, c.height);
  }
  if (inside) {
    ctx.fillStyle = 'rgba(35,20,8,.22)';
    ctx.fillRect(0, 0, c.width, c.height);
  }
  applyGrain(ctx, c.width, c.height, 0.6);
  return c;
}

function paintHoop() {
  // 竹を編んだ「たが」
  const c = makeCanvas(128, 32);
  const ctx = c.getContext('2d');
  ctx.fillStyle = '#c29a52';
  ctx.fillRect(0, 0, c.width, c.height);
  ctx.strokeStyle = '#8f6a2c';
  ctx.lineWidth = 3;
  for (let x = -32; x < c.width + 32; x += 12) {
    ctx.beginPath();
    ctx.moveTo(x, 0);
    ctx.lineTo(x + 16, c.height);
    ctx.stroke();
  }
  ctx.fillStyle = 'rgba(255,240,200,.3)';
  ctx.fillRect(0, 9, c.width, 4);
  applyGrain(ctx, c.width, c.height, 0.5);
  return c;
}

function paintWater() {
  const c = makeCanvas(512, 512);
  const ctx = c.getContext('2d');
  const g = ctx.createRadialGradient(236, 226, 20, 256, 256, 256);
  g.addColorStop(0, '#d6eef5');
  g.addColorStop(0.7, '#9fcde1');
  g.addColorStop(1, '#6aa3c2');
  ctx.fillStyle = g;
  ctx.fillRect(0, 0, 512, 512);
  // 空のうつりこみ
  ctx.fillStyle = 'rgba(255,255,255,.3)';
  ctx.beginPath();
  ctx.ellipse(200, 180, 150, 46, -0.35, 0, Math.PI * 2);
  ctx.fill();
  // さざなみ
  ctx.strokeStyle = 'rgba(255,255,255,.6)';
  ctx.lineCap = 'round';
  for (let k = 0; k < 14; k++) {
    const a = k * 2.39;
    const d = 60 + ((k * 71) % 150);
    ctx.lineWidth = 2.5 + (k % 3);
    ctx.beginPath();
    ctx.arc(256 + Math.cos(a) * d, 256 + Math.sin(a) * d, 14 + (k % 4) * 6, a, a + 1.3);
    ctx.stroke();
  }
  // ふちの陰
  const e = ctx.createRadialGradient(256, 256, 180, 256, 256, 256);
  e.addColorStop(0, 'rgba(20,50,70,0)');
  e.addColorStop(1, 'rgba(20,50,70,.4)');
  ctx.fillStyle = e;
  ctx.fillRect(0, 0, 512, 512);
  applyGrain(ctx, 512, 512, 0.4);
  return c;
}

function paintDrop() {
  const c = makeCanvas(64, 64);
  const ctx = c.getContext('2d');
  ctx.fillStyle = '#e6f5fb';
  ctx.strokeStyle = INK;
  ctx.lineWidth = 4;
  ctx.beginPath();
  ctx.moveTo(32, 6);
  ctx.bezierCurveTo(40, 22, 52, 32, 52, 42);
  ctx.bezierCurveTo(52, 54, 43, 60, 32, 60);
  ctx.bezierCurveTo(21, 60, 12, 54, 12, 42);
  ctx.bezierCurveTo(12, 32, 24, 22, 32, 6);
  ctx.fill();
  ctx.stroke();
  ctx.fillStyle = '#ffffff';
  ctx.beginPath();
  ctx.arc(25, 40, 5, 0, Math.PI * 2);
  ctx.fill();
  return c;
}

function buildTub(spec, tex) {
  const r = spec.r ?? 1.9; // 口の半径
  const h = spec.h ?? 1.6;
  const rb = r * 0.88; // 底の半径
  const waterY = h * (spec.water ?? 0.68);
  const radiusAt = (y) => rb + (r - rb) * (y / h);
  const gradientMap = toonGradient();
  const toon = (opt) => new THREE.MeshToonMaterial({ gradientMap, ...opt });
  const inkMat = new THREE.MeshBasicMaterial({ color: INK, side: THREE.BackSide });
  const group = new THREE.Group();
  const add = (mesh, { cast = true, receive = true } = {}) => {
    mesh.castShadow = cast;
    mesh.receiveShadow = receive;
    group.add(mesh);
    return mesh;
  };

  // 側板（外・内）と、線画のようなりんかく
  const wall = new THREE.CylinderGeometry(r, rb, h, 64, 1, true).translate(0, h / 2, 0);
  add(new THREE.Mesh(wall, toon({ map: tex(paintStaves(false)) })));
  add(new THREE.Mesh(wall, toon({ map: tex(paintStaves(true)), side: THREE.BackSide })));
  const hull = add(new THREE.Mesh(wall, inkMat), { cast: false, receive: false });
  hull.scale.set(1 + 0.07 / r, 1, 1 + 0.07 / r);

  // 縁
  const ring = (radius, tube, y) => new THREE.TorusGeometry(radius, tube, 10, 72).rotateX(Math.PI / 2).translate(0, y, 0);
  add(new THREE.Mesh(ring(r, 0.09, h), toon({ color: '#dcb67f' })));
  add(new THREE.Mesh(ring(r, 0.13, h), inkMat), { cast: false, receive: false });

  // たが（2本）
  const hoopTex = tex(paintHoop(), [22, 1]);
  for (const k of [0.24, 0.7]) {
    const y = h * k;
    const rr = radiusAt(y) + 0.035;
    add(new THREE.Mesh(ring(rr, 0.08, y), toon({ map: hoopTex })));
    add(new THREE.Mesh(ring(rr, 0.115, y), inkMat), { cast: false, receive: false });
  }

  // 底と水
  add(new THREE.Mesh(new THREE.CircleGeometry(rb, 48).rotateX(-Math.PI / 2).translate(0, 0.02, 0), toon({ color: '#7a5634' })), { cast: false });
  const hasWater = spec.water !== 0;
  const waterTex = hasWater ? tex(paintWater()) : null;
  if (hasWater) {
    waterTex.center.set(0.5, 0.5);
    add(new THREE.Mesh(
      new THREE.CircleGeometry(radiusAt(waterY) - 0.015, 64).rotateX(-Math.PI / 2).translate(0, waterY, 0),
      new THREE.MeshLambertMaterial({ map: waterTex, emissive: '#ffffff', emissiveMap: waterTex, emissiveIntensity: 0.3 }),
    ), { cast: false });
  }
  if (spec.load === 'laundry') {
    // せんたく物（藍の絣・白・紅）をふんわり入れる
    for (const [x, z, s, color] of [[-0.35, -0.2, 0.5, '#4f6b9a'], [0.3, -0.15, 0.46, '#f3ecdc'], [0.05, 0.3, 0.42, '#c0453a'], [-0.2, 0.35, 0.36, '#f3ecdc']]) {
      const geo = new THREE.SphereGeometry(r * s, 20, 12).scale(1, 0.55, 0.8);
      const m = add(new THREE.Mesh(geo, toon({ color })));
      m.position.set(x * r, h * 0.78, z * r);
      m.rotation.y = x * 3;
      const ink = add(new THREE.Mesh(geo, inkMat), { cast: false, receive: false });
      ink.position.copy(m.position);
      ink.rotation.copy(m.rotation);
      ink.scale.setScalar(1.06);
    }
  }

  // 波紋としぶき
  const ringGeo = new THREE.RingGeometry(0.8, 1, 40).rotateX(-Math.PI / 2);
  const ripples = Array.from({ length: 8 }, () => {
    const m = new THREE.Mesh(ringGeo, new THREE.MeshBasicMaterial({ color: '#ffffff', transparent: true, opacity: 0, depthWrite: false }));
    m.visible = false;
    group.add(m);
    return { m, t0: 0, size: 1 };
  });
  const dropMat = new THREE.SpriteMaterial({ map: tex(paintDrop()), transparent: true, depthWrite: false });
  const drops = Array.from({ length: 18 }, () => {
    const sprite = new THREE.Sprite(dropMat);
    sprite.scale.set(0.15, 0.15, 1);
    sprite.visible = false;
    group.add(sprite);
    return { sprite, v: new THREE.Vector3(), alive: false };
  });

  let now = 0;
  let wobbleAt = null;
  const clampIn = (x, z, margin) => {
    const d = Math.hypot(x, z);
    const max = radiusAt(waterY) - margin;
    return d > max ? [(x * max) / d, (z * max) / d] : [x, z];
  };
  function ripple(x, z, size = 1) {
    const rp = ripples.find((q) => !q.m.visible) ?? ripples[0];
    const [px, pz] = clampIn(x, z, 0.6 * size);
    rp.m.position.set(px, waterY + 0.012, pz);
    rp.t0 = now;
    rp.size = size;
    rp.m.visible = true;
  }
  function splash(x, z, strength = 1) {
    ripple(x, z, 0.6 + 0.4 * strength);
    const n = Math.round(2 + strength * 3);
    for (let i = 0; i < n; i++) {
      const d = drops.find((q) => !q.alive);
      if (!d) break;
      const [px, pz] = clampIn(x, z, 0.25);
      d.sprite.position.set(px, waterY + 0.05, pz);
      d.v.set((Math.random() - 0.5) * 1.3, 1.3 + Math.random() * 1.1 * strength, (Math.random() - 0.3) * 0.8);
      d.alive = d.sprite.visible = true;
    }
  }
  function drip(local) {
    const d = drops.find((q) => !q.alive);
    if (!d) return;
    d.sprite.position.copy(local);
    d.v.set(0, -0.3, 0);
    d.alive = d.sprite.visible = true;
  }

  return {
    group,
    splash,
    drip,
    tap(t) {
      wobbleAt = t;
      splash((Math.random() - 0.5) * r, (Math.random() - 0.5) * r, 1.8);
    },
    update(t, dt) {
      now = t;
      if (waterTex) waterTex.rotation += dt * 0.04;
      for (const rp of ripples) {
        if (!rp.m.visible) continue;
        const k = (t - rp.t0) / 0.9;
        if (k >= 1 || k < 0) { rp.m.visible = false; continue; }
        const s = (0.08 + k * 0.55) * rp.size;
        rp.m.scale.set(s, 1, s);
        rp.m.material.opacity = 0.75 * (1 - k);
      }
      for (const d of drops) {
        if (!d.alive) continue;
        d.v.y -= 7 * dt;
        d.sprite.position.addScaledVector(d.v, dt);
        const p = d.sprite.position;
        const inTub = Math.hypot(p.x, p.z) < radiusAt(waterY);
        if (p.y < (inTub ? waterY : 0)) {
          d.alive = d.sprite.visible = false;
          if (inTub) ripple(p.x, p.z, 0.35);
        }
      }
      const k = wobbleAt == null ? 1 : clamp01((t - wobbleAt) / 0.7);
      group.rotation.z = Math.sin(k * Math.PI * 4) * 0.05 * (1 - k);
    },
  };
}

/* ---------- 立体の桃 ---------- */

// 桃の形：下は丸く、上は少しとがる。割れ目（縫合線）のところを少しへこませる
const PEACH_PROFILE = [[0, -0.95], [0.35, -0.9], [0.62, -0.76], [0.82, -0.54], [0.95, -0.27], [1, 0], [0.97, 0.28], [0.88, 0.54],
  [0.72, 0.77], [0.5, 0.95], [0.26, 1.07], [0.09, 1.15], [0, 1.19]];
function peachGeometry(R, groove, phiStart = 0, phiLength = Math.PI * 2, segments = 72) {
  const geo = new THREE.LatheGeometry(PEACH_PROFILE.map(([r, y]) => new THREE.Vector2(r * R, y * R)), segments, phiStart, phiLength);
  const pos = geo.attributes.position;
  const v = new THREE.Vector3();
  for (let i = 0; i < pos.count; i++) {
    v.fromBufferAttribute(pos, i);
    let d = Math.atan2(v.x, v.z) - groove;
    d = Math.atan2(Math.sin(d), Math.cos(d));
    const fade = THREE.MathUtils.smoothstep(v.y / R, -0.85, 0.1);
    const k = 1 - 0.09 * Math.exp(-(d * d) / (2 * 0.11 * 0.11)) * fade + 0.025 * Math.exp(-((Math.abs(d) - 0.4) ** 2) / 0.05) * fade;
    pos.setXYZ(i, v.x * k, v.y, v.z * k);
  }
  if (phiLength < Math.PI * 2) {
    // 半分の形でも、模様（割れ目のすじ）の位置が丸ごとの桃と同じになるように
    const uv = geo.attributes.uv;
    for (let i = 0; i < uv.count; i++) uv.setX(i, (phiStart + uv.getX(i) * phiLength) / (Math.PI * 2));
  }
  geo.computeVertexNormals();
  return geo;
}

function paintPeach(groove) {
  const W = 1024;
  const H = 512;
  const c = makeCanvas(W, H);
  const ctx = c.getContext('2d');
  // 下はクリーム色、上ほど桃色から紅へ
  const g = ctx.createLinearGradient(0, H, 0, 0);
  g.addColorStop(0, '#f6ddb0');
  g.addColorStop(0.35, '#f9c7a6');
  g.addColorStop(0.7, '#f39a8e');
  g.addColorStop(1, '#e8716c');
  ctx.fillStyle = g;
  ctx.fillRect(0, 0, W, H);
  // 赤みのまだら
  for (let k = 0; k < 44; k++) {
    const x = Math.random() * W;
    const y = Math.random() * H * 0.65;
    const r = 30 + Math.random() * 90;
    for (const dx of [-W, 0, W]) {
      const rg = ctx.createRadialGradient(x + dx, y, 0, x + dx, y, r);
      rg.addColorStop(0, 'rgba(225,85,85,.26)');
      rg.addColorStop(1, 'rgba(225,85,85,0)');
      ctx.fillStyle = rg;
      ctx.fillRect(x + dx - r, y - r, r * 2, r * 2);
    }
  }
  // 割れ目のすじ
  const gx = ((((groove / (Math.PI * 2)) % 1) + 1) % 1) * W;
  ctx.strokeStyle = 'rgba(185,70,70,.6)';
  ctx.lineWidth = 5;
  ctx.lineCap = 'round';
  ctx.beginPath();
  ctx.moveTo(gx, 0);
  for (let y = 0; y <= H * 0.85; y += 16) ctx.lineTo(gx + Math.sin(y / 60) * 4, y);
  ctx.stroke();
  // 産毛と小さな斑点
  for (let k = 0; k < 3200; k++) {
    ctx.fillStyle = `rgba(255,255,255,${0.1 + Math.random() * 0.2})`;
    ctx.fillRect(Math.random() * W, Math.random() * H, 1.6, 1.6);
  }
  ctx.fillStyle = 'rgba(170,80,60,.25)';
  for (let k = 0; k < 160; k++) {
    ctx.beginPath();
    ctx.arc(Math.random() * W, Math.random() * H * 0.7, 1.2 + Math.random(), 0, Math.PI * 2);
    ctx.fill();
  }
  applyGrain(ctx, W, H, 0.5);
  return c;
}

/* ---------- 家の中（たたみ・かべ） ---------- */

function paintTatami() {
  const d = 40;
  const W = PAGE_W * d;
  const H = PAGE_D * d;
  const c = makeCanvas(W, H);
  const ctx = c.getContext('2d');
  ctx.fillStyle = '#c3b97c';
  ctx.fillRect(0, 0, W, H);
  const L = 12 * d; // たたみ1枚：12cm × 6cm
  const S = 6 * d;
  for (let row = 0; row * S < H; row++) {
    const y = row * S;
    for (let x = row % 2 ? -L / 2 : 0; x < W; x += L) {
      const tone = (Math.floor(x / L) + row) % 2 ? '#cdc486' : '#c6bd7d';
      ctx.fillStyle = tone;
      ctx.fillRect(x, y, L, S);
      // い草の目
      for (let k = 0; k < S; k += 5) {
        ctx.fillStyle = k % 10 ? 'rgba(120,112,60,.12)' : 'rgba(255,250,210,.14)';
        ctx.fillRect(x, y + k, L, 2);
      }
      ctx.strokeStyle = 'rgba(80,70,40,.35)';
      ctx.lineWidth = 2;
      ctx.strokeRect(x + 1, y + 1, L - 2, S - 2);
      // たたみの縁（長い辺）
      for (const by of [y, y + S - 14]) {
        ctx.fillStyle = '#343c34';
        ctx.fillRect(x, by, L, 14);
        ctx.fillStyle = 'rgba(140,150,120,.35)';
        ctx.fillRect(x, by + 6, L, 2);
      }
    }
  }
  // 障子からさしこむ日の光
  ctx.fillStyle = 'rgba(255,248,215,.2)';
  ctx.beginPath();
  ctx.moveTo(40, 0); ctx.lineTo(420, 0); ctx.lineTo(560, H * 0.7); ctx.lineTo(120, H * 0.7);
  ctx.fill();
  const gutter = ctx.createLinearGradient(0, 0, 0, 80);
  gutter.addColorStop(0, 'rgba(60,45,30,.35)');
  gutter.addColorStop(1, 'rgba(60,45,30,0)');
  ctx.fillStyle = gutter;
  ctx.fillRect(0, 0, W, 80);
  applyGrain(ctx, W, H, 0.6);
  return c;
}

function woodBand(ctx, x, y, w, h, color = '#7a5634', vertical = true) {
  ctx.fillStyle = color;
  ctx.fillRect(x, y, w, h);
  ctx.strokeStyle = 'rgba(50,30,15,.3)';
  ctx.lineWidth = 1.2;
  for (let k = 0; k < 4; k++) {
    ctx.beginPath();
    if (vertical) {
      const x0 = x + 4 + ((k * 7) % Math.max(4, w - 8));
      for (let yy = y; yy <= y + h; yy += 16) ctx.lineTo(x0 + Math.sin(yy / 40 + k) * 1.5, yy);
    } else {
      const y0 = y + 3 + ((k * 5) % Math.max(3, h - 6));
      for (let xx = x; xx <= x + w; xx += 16) ctx.lineTo(xx, y0 + Math.sin(xx / 40 + k) * 1.2);
    }
    ctx.stroke();
  }
  ctx.fillStyle = 'rgba(255,235,200,.16)';
  if (vertical) ctx.fillRect(x + 2, y, 4, h); else ctx.fillRect(x, y + 1, w, 3);
  ctx.fillStyle = 'rgba(40,25,10,.28)';
  if (vertical) ctx.fillRect(x + w - 5, y, 5, h); else ctx.fillRect(x, y + h - 4, w, 4);
}

function paintInterior() {
  const d = 40;
  const W = PAGE_W * d;
  const H = PAGE_D * d;
  const c = makeCanvas(W, H);
  const ctx = c.getContext('2d');
  // 土かべ
  ctx.fillStyle = '#e8d9b7';
  ctx.fillRect(0, 0, W, H);
  for (let k = 0; k < 400; k++) {
    ctx.fillStyle = k % 2 ? 'rgba(200,176,130,.12)' : 'rgba(255,248,230,.14)';
    ctx.beginPath();
    ctx.ellipse(Math.random() * W, Math.random() * H, 10 + Math.random() * 30, 6 + Math.random() * 16, Math.random() * 3, 0, Math.PI * 2);
    ctx.fill();
  }
  // 天井板
  woodBand(ctx, 0, 0, W, 64, '#6e5033', false);
  for (let x = 0; x < W; x += 68) {
    ctx.fillStyle = 'rgba(40,25,10,.35)';
    ctx.fillRect(x, 0, 3, 64);
  }
  const bays = [[64, 470], [504, 896], [930, 1296]];
  const lintel = 236;
  const floor = H - 22;

  // 左：障子（庭の松の影がうつる）
  {
    const [x0, x1] = bays[0];
    const g = ctx.createLinearGradient(0, lintel, 0, floor);
    g.addColorStop(0, '#fffbf0');
    g.addColorStop(1, '#f3e8cc');
    ctx.fillStyle = g;
    ctx.fillRect(x0, lintel, x1 - x0, floor - lintel);
    ctx.save();
    ctx.filter = 'blur(5px)';
    ctx.fillStyle = 'rgba(70,86,70,.2)';
    for (const [x, y, rx, ry] of [[150, 330, 70, 22], [230, 300, 60, 18], [300, 350, 80, 24], [200, 400, 50, 16]]) {
      ctx.beginPath();
      ctx.ellipse(x, y, rx, ry, -0.1, 0, Math.PI * 2);
      ctx.fill();
    }
    ctx.lineWidth = 10;
    ctx.strokeStyle = 'rgba(70,70,60,.2)';
    ctx.beginPath();
    ctx.moveTo(90, 520); ctx.quadraticCurveTo(200, 420, 310, 360);
    ctx.stroke();
    ctx.restore();
    ctx.strokeStyle = '#a07e55';
    ctx.lineWidth = 4;
    for (let x = x0; x <= x1; x += (x1 - x0) / 4) {
      ctx.beginPath(); ctx.moveTo(x, lintel); ctx.lineTo(x, floor); ctx.stroke();
    }
    for (let y = lintel; y <= floor; y += 64) {
      ctx.beginPath(); ctx.moveTo(x0, y); ctx.lineTo(x1, y); ctx.stroke();
    }
    ctx.lineWidth = 12;
    ctx.strokeRect(x0 + 6, lintel + 6, x1 - x0 - 12, floor - lintel - 12);
    ctx.beginPath(); ctx.moveTo((x0 + x1) / 2, lintel); ctx.lineTo((x0 + x1) / 2, floor); ctx.stroke();
    // 欄間（雲の透かし彫り）
    woodBand(ctx, x0, 176, x1 - x0, lintel - 176, '#7c5a38', false);
    ctx.fillStyle = '#f7eed8';
    for (const [x, y, s] of [[140, 206, 1], [270, 200, 1.2], [390, 210, 0.9]]) {
      ctx.beginPath();
      ctx.ellipse(x, y, 26 * s, 11 * s, 0, 0, Math.PI * 2);
      ctx.ellipse(x + 22 * s, y - 6 * s, 16 * s, 10 * s, 0, 0, Math.PI * 2);
      ctx.ellipse(x - 22 * s, y + 2 * s, 14 * s, 8 * s, 0, 0, Math.PI * 2);
      ctx.fill();
    }
  }

  // 中：床の間（掛け軸と桃の花）
  {
    const [x0, x1] = bays[1];
    ctx.fillStyle = '#dccaa2';
    ctx.fillRect(x0, 176, x1 - x0, floor - 176);
    const sh = ctx.createLinearGradient(x0, 0, x0 + 60, 0);
    sh.addColorStop(0, 'rgba(90,65,35,.3)');
    sh.addColorStop(1, 'rgba(90,65,35,0)');
    ctx.fillStyle = sh;
    ctx.fillRect(x0, 176, 60, floor - 176);
    const cx = (x0 + x1) / 2;
    // 掛け軸
    ctx.fillStyle = '#5f6f5c';
    ctx.fillRect(cx - 66, 196, 132, 360);
    ctx.fillStyle = '#c9b07a';
    ctx.fillRect(cx - 66, 236, 132, 4);
    ctx.fillRect(cx - 66, 516, 132, 4);
    ctx.fillStyle = '#f4ecd6';
    ctx.fillRect(cx - 50, 244, 100, 268);
    ctx.fillStyle = '#d9534a';
    ctx.beginPath(); ctx.arc(cx + 22, 300, 14, 0, Math.PI * 2); ctx.fill();
    ctx.strokeStyle = '#3a342e';
    ctx.lineWidth = 3;
    ctx.fillStyle = '#9fb4c0';
    ctx.beginPath();
    ctx.moveTo(cx - 46, 440); ctx.lineTo(cx - 8, 356); ctx.lineTo(cx + 8, 356); ctx.lineTo(cx + 46, 440);
    ctx.fill(); ctx.stroke();
    ctx.fillStyle = '#ffffff';
    ctx.beginPath();
    ctx.moveTo(cx - 20, 382); ctx.lineTo(cx - 8, 356); ctx.lineTo(cx + 8, 356); ctx.lineTo(cx + 20, 382);
    ctx.lineTo(cx + 10, 376); ctx.lineTo(cx, 386); ctx.lineTo(cx - 10, 376); ctx.closePath();
    ctx.fill();
    ctx.lineWidth = 2;
    ctx.strokeStyle = 'rgba(58,52,46,.6)';
    for (const y of [462, 478]) {
      ctx.beginPath(); ctx.moveTo(cx - 40, y); ctx.bezierCurveTo(cx - 20, y - 6, cx + 20, y + 6, cx + 40, y); ctx.stroke();
    }
    ctx.fillStyle = '#3a2d25';
    ctx.fillRect(cx - 74, 552, 148, 10);
    ctx.beginPath(); ctx.arc(cx - 76, 557, 7, 0, Math.PI * 2); ctx.arc(cx + 76, 557, 7, 0, Math.PI * 2); ctx.fill();
    // 床框と、花びん
    ctx.fillStyle = '#2e2420';
    ctx.fillRect(x0, floor - 40, x1 - x0, 14);
    ctx.fillStyle = 'rgba(255,255,255,.2)';
    ctx.fillRect(x0, floor - 40, x1 - x0, 3);
    ctx.fillStyle = '#c9b48a';
    ctx.fillRect(x0, floor - 26, x1 - x0, 26);
    const vx = x0 + 90;
    ctx.strokeStyle = '#5a3b2a';
    ctx.lineWidth = 4;
    ctx.lineCap = 'round';
    for (const [dx, dy] of [[-40, -130], [30, -150], [60, -90]]) {
      ctx.beginPath(); ctx.moveTo(vx, floor - 90); ctx.quadraticCurveTo(vx + dx * 0.3, floor - 90 + dy * 0.6, vx + dx, floor - 90 + dy); ctx.stroke();
      for (let k = 0; k < 5; k++) {
        const t = 0.35 + k * 0.15;
        const bx = vx + dx * t;
        const by = floor - 90 + dy * t;
        ctx.fillStyle = k % 2 ? '#f6b7c1' : '#f29aa9';
        ctx.beginPath(); ctx.arc(bx + 6, by, 7, 0, Math.PI * 2); ctx.fill();
        ctx.fillStyle = '#fff4d6';
        ctx.beginPath(); ctx.arc(bx + 6, by, 2.4, 0, Math.PI * 2); ctx.fill();
      }
    }
    ctx.fillStyle = '#5d86a3';
    ctx.strokeStyle = '#3a2d25';
    ctx.lineWidth = 3;
    ctx.beginPath();
    ctx.moveTo(vx - 10, floor - 94);
    ctx.bezierCurveTo(vx - 34, floor - 70, vx - 30, floor - 44, vx - 16, floor - 40);
    ctx.lineTo(vx + 16, floor - 40);
    ctx.bezierCurveTo(vx + 30, floor - 44, vx + 34, floor - 70, vx + 10, floor - 94);
    ctx.closePath(); ctx.fill(); ctx.stroke();
    ctx.fillStyle = 'rgba(255,255,255,.35)';
    ctx.beginPath(); ctx.ellipse(vx - 12, floor - 66, 4, 12, 0.2, 0, Math.PI * 2); ctx.fill();
  }

  // 右：ふすま（雲と山の絵）
  {
    const [x0, x1] = bays[2];
    const mid = (x0 + x1) / 2;
    ctx.fillStyle = '#efe2c2';
    ctx.fillRect(x0, lintel, x1 - x0, floor - lintel);
    ctx.fillStyle = 'rgba(160,190,170,.55)';
    ctx.beginPath();
    ctx.moveTo(x0, floor - 110);
    for (let x = x0; x <= x1; x += 20) ctx.lineTo(x, floor - 150 - Math.sin((x - x0) / 60) * 40 - Math.sin((x - x0) / 23) * 10);
    ctx.lineTo(x1, floor); ctx.lineTo(x0, floor);
    ctx.fill();
    ctx.fillStyle = 'rgba(226,196,120,.7)';
    for (const [x, y, w] of [[x0 + 40, 300, 160], [x0 + 190, 360, 180], [x0 + 70, 420, 120]]) {
      ctx.beginPath();
      ctx.roundRect(x, y, w, 26, 13);
      ctx.fill();
    }
    ctx.strokeStyle = '#3a2e28';
    ctx.lineWidth = 8;
    ctx.strokeRect(x0 + 4, lintel + 4, mid - x0 - 6, floor - lintel - 8);
    ctx.strokeRect(mid + 2, lintel + 4, x1 - mid - 6, floor - lintel - 8);
    ctx.fillStyle = '#3a2e28';
    for (const x of [mid - 26, mid + 26]) {
      ctx.beginPath(); ctx.arc(x, (lintel + floor) / 2, 9, 0, Math.PI * 2); ctx.fill();
    }
    ctx.fillStyle = '#b89b5e';
    for (const x of [mid - 26, mid + 26]) {
      ctx.beginPath(); ctx.arc(x, (lintel + floor) / 2, 4, 0, Math.PI * 2); ctx.fill();
    }
  }

  // 長押・鴨居・柱・畳寄せ
  woodBand(ctx, 0, 150, W, 26, '#8b6641', false);
  woodBand(ctx, 0, lintel - 12, W, 14, '#8b6641', false);
  for (const x of [26, 470, 896, 1296]) woodBand(ctx, x, 64, 34, H - 64, '#7a5634', true);
  woodBand(ctx, 0, floor, W, 22, '#8a6a47', false);
  const gutter = ctx.createLinearGradient(0, H, 0, H - 70);
  gutter.addColorStop(0, 'rgba(60,45,30,.32)');
  gutter.addColorStop(1, 'rgba(60,45,30,0)');
  ctx.fillStyle = gutter;
  ctx.fillRect(0, H - 70, W, 70);
  applyGrain(ctx, W, H, 0.6);
  return c;
}

function paintEndpaper() {
  // 見返し（生成りの紙に、うすい青海波）
  const s = 80;
  const c = makeCanvas(s * 4, s * 2);
  const ctx = c.getContext('2d');
  ctx.fillStyle = '#f1e7cf';
  ctx.fillRect(0, 0, c.width, c.height);
  ctx.strokeStyle = 'rgba(160,130,90,.25)';
  ctx.lineWidth = 2;
  for (let row = -1; row <= 4; row++) {
    for (let col = -1; col <= 4; col++) {
      const cx = col * s + (row % 2 ? s / 2 : 0);
      const cy = row * (s / 2) + s / 2;
      for (let k = 4; k >= 1; k--) {
        ctx.beginPath();
        ctx.arc(cx, cy, (s / 2) * (k / 4), Math.PI, 0);
        ctx.stroke();
      }
    }
  }
  applyGrain(ctx, c.width, c.height, 0.6);
  return c;
}

/* ---------- 立体の小道具：かやぶき屋根の家 ---------- */

function paintThatch() {
  const c = makeCanvas(256, 256);
  const ctx = c.getContext('2d');
  ctx.fillStyle = '#c9a45a';
  ctx.fillRect(0, 0, 256, 256);
  const tones = ['#b08944', '#d8b86c', '#9c7738', '#e3c67e'];
  ctx.lineCap = 'round';
  for (let k = 0; k < 900; k++) {
    const x = Math.random() * 256;
    const y = Math.random() * 256;
    const l = 10 + Math.random() * 22;
    ctx.strokeStyle = tones[k % 4];
    ctx.lineWidth = 1 + Math.random() * 1.4;
    for (const dy of [-256, 0, 256]) {
      ctx.beginPath();
      ctx.moveTo(x, y + dy);
      ctx.lineTo(x + (Math.random() - 0.5) * 3, y + dy + l);
      ctx.stroke();
    }
  }
  // 葺き重ねの段
  for (const y of [60, 188]) {
    const g = ctx.createLinearGradient(0, y - 8, 0, y + 10);
    g.addColorStop(0, 'rgba(90,60,25,0)');
    g.addColorStop(0.6, 'rgba(90,60,25,.3)');
    g.addColorStop(1, 'rgba(90,60,25,0)');
    ctx.fillStyle = g;
    ctx.fillRect(0, y - 8, 256, 18);
  }
  return c;
}

function paintThatchEdge() {
  // 軒の切り口（わらの束の断面）
  const c = makeCanvas(256, 64);
  const ctx = c.getContext('2d');
  ctx.fillStyle = '#8f6a33';
  ctx.fillRect(0, 0, 256, 64);
  for (let k = 0; k < 700; k++) {
    ctx.fillStyle = ['#a57c3e', '#c49a52', '#6f4f24', '#b88d48'][k % 4];
    ctx.fillRect(Math.random() * 256, Math.random() * 64, 1.5 + Math.random() * 1.5, 2 + Math.random() * 5);
  }
  ctx.fillStyle = 'rgba(255,240,200,.25)';
  ctx.fillRect(0, 0, 256, 6);
  ctx.fillStyle = 'rgba(40,25,10,.35)';
  ctx.fillRect(0, 54, 256, 10);
  return c;
}

function paintHouseFront(w, h, door) {
  const d = 100;
  const W = Math.round(w * d);
  const H = Math.round(h * d);
  const c = makeCanvas(W, H);
  const ctx = c.getContext('2d');
  ctx.fillStyle = '#eee0bf';
  ctx.fillRect(0, 0, W, H);
  for (let k = 0; k < 160; k++) {
    ctx.fillStyle = 'rgba(190,165,120,.12)';
    ctx.beginPath();
    ctx.ellipse(Math.random() * W, Math.random() * H, 8 + Math.random() * 20, 4 + Math.random() * 10, 0, 0, Math.PI * 2);
    ctx.fill();
  }
  // 腰板
  const kosi = H * 0.64;
  for (let x = 0; x < W; x += 26) woodBand(ctx, x, kosi, 26, H - kosi, x % 52 ? '#86643f' : '#7d5c39', true);
  // 入口（戸を半分あけた土間）
  const dx0 = W * door - 95;
  const dx1 = W * door + 95;
  const g = ctx.createLinearGradient(0, H * 0.12, 0, H);
  g.addColorStop(0, '#2d2119');
  g.addColorStop(1, '#4a3526');
  ctx.fillStyle = g;
  ctx.fillRect(dx0, H * 0.12, dx1 - dx0, H * 0.88);
  const glow = ctx.createRadialGradient(dx0 + 120, H * 0.86, 4, dx0 + 120, H * 0.86, 70);
  glow.addColorStop(0, 'rgba(255,170,80,.55)');
  glow.addColorStop(1, 'rgba(255,170,80,0)');
  ctx.fillStyle = glow;
  ctx.fillRect(dx0, H * 0.5, dx1 - dx0, H * 0.5);
  woodBand(ctx, dx0 - 10, H * 0.12, 96, H * 0.88, '#9a7650', true);
  ctx.strokeStyle = 'rgba(50,30,15,.5)';
  ctx.lineWidth = 3;
  for (let y = H * 0.24; y < H; y += 44) {
    ctx.beginPath(); ctx.moveTo(dx0 - 10, y); ctx.lineTo(dx0 + 86, y); ctx.stroke();
  }
  // 障子窓
  const wx0 = W * 0.74;
  const wx1 = W * 0.92;
  ctx.fillStyle = '#fbf6e6';
  ctx.fillRect(wx0, H * 0.2, wx1 - wx0, H * 0.36);
  ctx.strokeStyle = '#8a6a44';
  ctx.lineWidth = 3;
  for (let x = wx0; x <= wx1 + 1; x += (wx1 - wx0) / 4) { ctx.beginPath(); ctx.moveTo(x, H * 0.2); ctx.lineTo(x, H * 0.56); ctx.stroke(); }
  for (let y = H * 0.2; y <= H * 0.56 + 1; y += H * 0.09) { ctx.beginPath(); ctx.moveTo(wx0, y); ctx.lineTo(wx1, y); ctx.stroke(); }
  ctx.lineWidth = 7;
  ctx.strokeRect(wx0, H * 0.2, wx1 - wx0, H * 0.36);
  // かべにかけた笠
  const hx = W * 0.6;
  ctx.fillStyle = '#d8b66a';
  ctx.strokeStyle = '#4a3a30';
  ctx.lineWidth = 3;
  ctx.beginPath(); ctx.ellipse(hx, H * 0.34, 40, 40, 0, 0, Math.PI * 2); ctx.fill(); ctx.stroke();
  ctx.strokeStyle = 'rgba(120,85,35,.6)';
  ctx.lineWidth = 2;
  for (let k = 0; k < 12; k++) {
    const a = (k / 12) * Math.PI * 2;
    ctx.beginPath(); ctx.moveTo(hx, H * 0.34); ctx.lineTo(hx + Math.cos(a) * 38, H * 0.34 + Math.sin(a) * 38); ctx.stroke();
  }
  // 柱と梁
  for (const x of [0, W * 0.3, W * 0.66, W - 24]) woodBand(ctx, x, 0, 24, H, '#6e4c2e', true);
  woodBand(ctx, 0, 0, W, 20, '#6e4c2e', false);
  woodBand(ctx, 0, kosi - 8, W, 12, '#6e4c2e', false);
  applyGrain(ctx, W, H, 0.6);
  return c;
}

function paintHouseSide(w, h) {
  const d = 100;
  const W = Math.round(w * d);
  const H = Math.round(h * d);
  const c = makeCanvas(W, H);
  const ctx = c.getContext('2d');
  ctx.fillStyle = '#e9dab8';
  ctx.fillRect(0, 0, W, H);
  const kosi = H * 0.64;
  for (let x = 0; x < W; x += 26) woodBand(ctx, x, kosi, 26, H - kosi, x % 52 ? '#86643f' : '#7d5c39', true);
  // 格子窓
  ctx.fillStyle = '#3b2c20';
  ctx.fillRect(W * 0.35, H * 0.22, W * 0.3, H * 0.28);
  for (let x = W * 0.35; x <= W * 0.65; x += 14) woodBand(ctx, x, H * 0.22, 7, H * 0.28, '#8a6a44', true);
  for (const x of [0, W / 2 - 12, W - 24]) woodBand(ctx, x, 0, 24, H, '#6e4c2e', true);
  woodBand(ctx, 0, 0, W, 20, '#6e4c2e', false);
  woodBand(ctx, 0, kosi - 8, W, 12, '#6e4c2e', false);
  applyGrain(ctx, W, H, 0.6);
  return c;
}

function paintPuff() {
  const c = makeCanvas(128, 128);
  const ctx = c.getContext('2d');
  ctx.fillStyle = '#f7f4ee';
  ctx.strokeStyle = 'rgba(74,58,48,.5)';
  ctx.lineWidth = 4;
  ctx.beginPath();
  for (const [x, y, r] of [[64, 70, 34], [40, 76, 22], [88, 76, 22], [58, 46, 22], [80, 52, 18]]) {
    ctx.moveTo(x + r, y);
    ctx.arc(x, y, r, 0, Math.PI * 2);
  }
  ctx.stroke();
  ctx.fill();
  ctx.fillStyle = 'rgba(190,190,200,.4)';
  ctx.beginPath();
  ctx.ellipse(68, 88, 30, 12, 0, 0, Math.PI * 2);
  ctx.fill();
  return c;
}

function hipRoofGeometry(a, b, r, rh, t) {
  const P = (x, y, z) => new THREE.Vector3(x, y, z);
  const c = [P(-a, 0, b), P(a, 0, b), P(a, 0, -b), P(-a, 0, -b)];
  const cd = c.map((p) => P(p.x, -t, p.z));
  const R1 = P(-r, rh, 0);
  const R2 = P(r, rh, 0);
  const pos = [];
  const uv = [];
  const groups = [[], []];
  const tri = (g, A, B, C, U, V, O, sx, sy) => {
    for (const p of [A, B, C]) {
      const q = p.clone().sub(O);
      pos.push(p.x, p.y, p.z);
      uv.push(q.dot(U) / sx, q.dot(V) / sy);
    }
    groups[g].push(pos.length / 3 - 3);
  };
  const slope = (pts, U, O, top) => {
    const V = top.clone().sub(O).normalize();
    tri(0, pts[0], pts[1], pts[2], U, V, O, 4, 4);
    if (pts.length === 4) tri(0, pts[0], pts[2], pts[3], U, V, O, 4, 4);
  };
  slope([c[0], c[1], R2, R1], P(1, 0, 0), P(0, 0, b), P(0, rh, 0));
  slope([c[2], c[3], R1, R2], P(-1, 0, 0), P(0, 0, -b), P(0, rh, 0));
  slope([c[1], c[2], R2], P(0, 0, -1), P(a, 0, 0), R2);
  slope([c[3], c[0], R1], P(0, 0, 1), P(-a, 0, 0), R1);
  // 軒の厚み
  for (let i = 0; i < 4; i++) {
    const j = (i + 1) % 4;
    const U = c[j].clone().sub(c[i]).normalize();
    const O = cd[i];
    const V = P(0, 1, 0);
    tri(1, cd[i], cd[j], c[j], U, V, O, 4, t);
    tri(1, cd[i], c[j], c[i], U, V, O, 4, t);
  }
  tri(1, cd[0], cd[3], cd[2], P(1, 0, 0), P(0, 0, 1), cd[0], 4, 4);
  tri(1, cd[0], cd[2], cd[1], P(1, 0, 0), P(0, 0, 1), cd[0], 4, 4);
  // 面ごとにまとめ直す（材質を2つに分ける）
  const out = new THREE.BufferGeometry();
  const P2 = [];
  const UV2 = [];
  let start = 0;
  for (let g = 0; g < 2; g++) {
    for (const i of groups[g]) {
      for (let k = 0; k < 3; k++) {
        P2.push(pos[(i + k) * 3], pos[(i + k) * 3 + 1], pos[(i + k) * 3 + 2]);
        UV2.push(uv[(i + k) * 2], uv[(i + k) * 2 + 1]);
      }
    }
    out.addGroup(start, groups[g].length * 3, g);
    start += groups[g].length * 3;
  }
  out.setAttribute('position', new THREE.Float32BufferAttribute(P2, 3));
  out.setAttribute('uv', new THREE.Float32BufferAttribute(UV2, 2));
  out.computeVertexNormals();
  return out;
}

function buildHouse(spec, tex) {
  const w = spec.w ?? 10;
  const dd = spec.d ?? 5;
  const h = spec.h ?? 3.2;
  const eave = spec.eave ?? 1.1;
  const rh = spec.roofH ?? 3.8;
  const t = spec.thick ?? 0.6;
  const gradientMap = toonGradient();
  const toon = (opt) => new THREE.MeshToonMaterial({ gradientMap, ...opt });
  const inkMat = new THREE.MeshBasicMaterial({ color: INK, side: THREE.BackSide });
  const group = new THREE.Group();
  const add = (mesh, parent = group, { cast = true, receive = true } = {}) => {
    mesh.castShadow = cast;
    mesh.receiveShadow = receive;
    parent.add(mesh);
    return mesh;
  };
  // 線画のりんかく（裏返しの一回り大きい面）といっしょに動かす
  const withInk = (geo, mat, grow, parent = group) => {
    const g = new THREE.Group();
    add(new THREE.Mesh(geo, mat), g);
    add(new THREE.Mesh(geo, inkMat), g, { cast: false, receive: false }).scale.set(...grow);
    parent.add(g);
    return g;
  };
  const baseH = 0.3;
  // 石の土台
  withInk(new THREE.BoxGeometry(w + 0.4, baseH, dd + 0.4).translate(0, baseH / 2, 0), toon({ color: '#aaa596' }),
    [1 + 0.08 / w, 1.1, 1 + 0.08 / dd]);
  // かべ
  const front = toon({ map: tex(paintHouseFront(w, h, spec.door ?? 0.42)) });
  const side = toon({ map: tex(paintHouseSide(dd, h)) });
  const plain = toon({ color: '#e7dcc0' });
  withInk(new THREE.BoxGeometry(w, h, dd), [side, side, plain, plain, front, plain], [1 + 0.07 / w, 1 + 0.07 / h, 1 + 0.07 / dd])
    .position.y = baseH + h / 2;
  // 入口の踏み石
  withInk(new THREE.BoxGeometry(1.3, 0.22, 0.8).translate(0, 0.11, 0), toon({ color: '#b9b3a4' }), [1.06, 1.2, 1.1])
    .position.set((spec.door ?? 0.42) * w - w / 2 + 0.4, 0, dd / 2 + 0.7);

  // かやぶき屋根
  const a = w / 2 + eave;
  const b = dd / 2 + eave;
  const r = Math.max(0.4, a - b * 1.1);
  const roof = new THREE.Group();
  roof.position.y = baseH + h + t - 0.2;
  group.add(roof);
  const roofGeo = hipRoofGeometry(a, b, r, rh, t);
  const thatch = toon({ map: tex(paintThatch(), [1, 1]) });
  thatch.map.wrapS = thatch.map.wrapT = THREE.RepeatWrapping;
  const edge = toon({ map: tex(paintThatchEdge()) });
  edge.map.wrapS = THREE.RepeatWrapping;
  add(new THREE.Mesh(roofGeo, [thatch, edge]), roof);
  const roofInk = add(new THREE.Mesh(roofGeo, inkMat), roof, { cast: false, receive: false });
  roofInk.scale.set(1 + 0.1 / a, 1 + 0.1 / rh, 1 + 0.1 / b);
  // 棟と、棟をおさえる木（馬乗り）
  const ridgeLen = r * 2 + 0.9;
  withInk(new THREE.BoxGeometry(ridgeLen, 0.5, 0.75), toon({ color: '#5b4a30' }), [1 + 0.08 / ridgeLen, 1.2, 1.15], roof)
    .position.y = rh + 0.08;
  const n = Math.max(3, Math.round(ridgeLen / 1.3));
  const stickGeo = new THREE.BoxGeometry(0.12, 1.1, 0.12);
  const stickMat = toon({ color: '#3f3122' });
  for (let i = 0; i < n; i++) {
    const x = -ridgeLen / 2 + 0.35 + (i * (ridgeLen - 0.7)) / (n - 1);
    for (const s of [-1, 1]) {
      const m = add(new THREE.Mesh(stickGeo, stickMat), roof);
      m.position.set(x, rh + 0.35, 0);
      m.rotation.x = s * 0.55;
    }
  }

  // 煙出しから、けむり
  const puffTex = tex(paintPuff());
  const puffs = Array.from({ length: 9 }, () => {
    const sp = new THREE.Sprite(new THREE.SpriteMaterial({ map: puffTex, transparent: true, depthWrite: false, opacity: 0 }));
    sp.visible = false;
    roof.add(sp);
    return { sp, t0: 0 };
  });
  let lastPuff = -9;
  let wobbleAt = null;
  const ventX = r + 0.2;
  return {
    group,
    tap(time) { wobbleAt = time; lastPuff = -9; },
    update(time) {
      const k = wobbleAt == null ? 1 : clamp01((time - wobbleAt) / 0.8);
      group.scale.set(1 - Math.sin(k * Math.PI * 3) * 0.02 * (1 - k), 1 + Math.sin(k * Math.PI * 3) * 0.035 * (1 - k), 1);
      if (spec.smoke !== false && time - lastPuff > 0.7) {
        lastPuff = time;
        const p = puffs.find((q) => !q.sp.visible) ?? puffs[0];
        p.t0 = time;
        p.sp.visible = true;
      }
      for (const p of puffs) {
        if (!p.sp.visible) continue;
        const k = (time - p.t0) / 5;
        if (k >= 1 || k < 0) { p.sp.visible = false; continue; }
        p.sp.position.set(ventX + k * 2.6 + Math.sin(k * 7 + p.t0) * 0.25, rh + 0.6 + k * 5.5, 0);
        p.sp.scale.setScalar(0.5 + k * 1.3);
        p.sp.material.opacity = Math.min(1, k * 6) * (1 - k) * 0.85;
      }
    },
  };
}

/* ---------- 立体の小道具：ぱかっと割れる桃 ---------- */

function paintFlesh() {
  const c = makeCanvas(512, 512);
  const ctx = c.getContext('2d');
  const g = ctx.createRadialGradient(256, 250, 30, 256, 256, 300);
  g.addColorStop(0, '#fff3d8');
  g.addColorStop(0.55, '#ffe0b0');
  g.addColorStop(0.8, '#f8b597');
  g.addColorStop(0.93, '#ee8b80');
  g.addColorStop(1, '#d9615e');
  ctx.fillStyle = g;
  ctx.fillRect(0, 0, 512, 512);
  // 赤ちゃんが入っていたくぼみ
  const cav = ctx.createRadialGradient(240, 220, 20, 256, 250, 150);
  cav.addColorStop(0, '#f6d7a5');
  cav.addColorStop(0.8, '#e9b681');
  cav.addColorStop(1, '#d99a68');
  ctx.fillStyle = cav;
  ctx.beginPath();
  ctx.ellipse(256, 250, 118, 150, 0, 0, Math.PI * 2);
  ctx.fill();
  ctx.strokeStyle = 'rgba(255,250,230,.8)';
  ctx.lineWidth = 6;
  ctx.beginPath();
  ctx.ellipse(256, 250, 122, 154, 0, Math.PI * 0.6, Math.PI * 1.5);
  ctx.stroke();
  // 果肉のすじ
  ctx.strokeStyle = 'rgba(230,150,110,.3)';
  ctx.lineWidth = 2;
  for (let k = 0; k < 40; k++) {
    const a = (k / 40) * Math.PI * 2;
    ctx.beginPath();
    ctx.moveTo(256 + Math.cos(a) * 170, 256 + Math.sin(a) * 200);
    ctx.lineTo(256 + Math.cos(a) * 235, 256 + Math.sin(a) * 250);
    ctx.stroke();
  }
  // みずみずしい光
  ctx.fillStyle = 'rgba(255,255,255,.5)';
  for (let k = 0; k < 50; k++) {
    ctx.beginPath();
    ctx.arc(Math.random() * 512, Math.random() * 512, 1.5 + Math.random() * 2.5, 0, Math.PI * 2);
    ctx.fill();
  }
  applyGrain(ctx, 512, 512, 0.3);
  return c;
}

// 半分の桃（切り口つき）。groove=0 のとき割れ目は手前（+z）にあり、x=0 の面で左右に割れる
function peachHalf(R, side, skinMat, fleshMat, inkMat) {
  const segs = 36;
  const phiStart = side > 0 ? 0 : Math.PI;
  const geo = peachGeometry(R, 0, phiStart, Math.PI, segs);
  const n = PEACH_PROFILE.length;
  const pos = geo.attributes.position;
  const at = (i) => new THREE.Vector3().fromBufferAttribute(pos, i);
  const verts = [];
  const uvs = [];
  const toUV = (p) => [0.5 + p.z / (2.1 * R), (p.y + 0.95 * R) / (2.2 * R)];
  for (let j = 0; j < n - 1; j++) {
    const a0 = at(j);
    const a1 = at(j + 1);
    const b0 = at(segs * n + j);
    const b1 = at(segs * n + j + 1);
    for (const p of [a0, a1, b1, a0, b1, b0]) {
      verts.push(0, p.y, p.z);
      uvs.push(...toUV(p));
    }
  }
  const cap = new THREE.BufferGeometry();
  cap.setAttribute('position', new THREE.Float32BufferAttribute(verts, 3));
  cap.setAttribute('uv', new THREE.Float32BufferAttribute(uvs, 2));
  cap.computeVertexNormals();
  const g = new THREE.Group();
  const skin = new THREE.Mesh(geo, skinMat);
  const hull = new THREE.Mesh(geo, inkMat);
  hull.scale.setScalar(1.035);
  const face = new THREE.Mesh(cap, fleshMat);
  face.position.x = side * 0.004;
  for (const m of [skin, face]) { m.castShadow = true; m.receiveShadow = true; }
  g.add(skin, hull, face);
  return g;
}

function paintCushion(color) {
  const c = makeCanvas(256, 256);
  const ctx = c.getContext('2d');
  ctx.fillStyle = color;
  ctx.fillRect(0, 0, 256, 256);
  ctx.strokeStyle = 'rgba(255,230,200,.25)';
  ctx.lineWidth = 3;
  for (let k = 12; k < 256; k += 24) {
    ctx.beginPath(); ctx.moveTo(k, 0); ctx.lineTo(k, 256); ctx.stroke();
    ctx.beginPath(); ctx.moveTo(0, k); ctx.lineTo(256, k); ctx.stroke();
  }
  // とじ糸
  ctx.fillStyle = '#f3ecdc';
  ctx.beginPath(); ctx.arc(128, 128, 9, 0, Math.PI * 2); ctx.fill();
  applyGrain(ctx, 256, 256, 0.5);
  return c;
}

function buildCushion(spec, tex) {
  const w = spec.w ?? 3.4;
  const dd = spec.d ?? 3;
  const h = spec.h ?? 0.36;
  const geo = new THREE.BoxGeometry(w, h, dd, 16, 2, 16);
  const pos = geo.attributes.position;
  const v = new THREE.Vector3();
  for (let i = 0; i < pos.count; i++) {
    v.fromBufferAttribute(pos, i);
    const f = (1 - 0.7 * Math.abs((2 * v.x) / w) ** 5) * (1 - 0.7 * Math.abs((2 * v.z) / dd) ** 5);
    pos.setY(i, v.y * f + h / 2);
  }
  geo.computeVertexNormals();
  const gradientMap = toonGradient();
  const group = new THREE.Group();
  const m = new THREE.Mesh(geo, new THREE.MeshToonMaterial({ gradientMap, map: tex(paintCushion(spec.color ?? '#8f3a3a')) }));
  m.castShadow = true;
  m.receiveShadow = true;
  const ink = new THREE.Mesh(geo, new THREE.MeshBasicMaterial({ color: INK, side: THREE.BackSide }));
  ink.scale.set(1 + 0.08 / w, 1.15, 1 + 0.08 / dd);
  group.add(m, ink);
  return { group };
}

/* ---------- 光のつぶ・花びら ---------- */

function paintStar() {
  const c = makeCanvas(64, 64);
  const ctx = c.getContext('2d');
  const g = ctx.createRadialGradient(32, 32, 0, 32, 32, 30);
  g.addColorStop(0, 'rgba(255,250,220,.9)');
  g.addColorStop(1, 'rgba(255,230,150,0)');
  ctx.fillStyle = g;
  ctx.fillRect(0, 0, 64, 64);
  ctx.fillStyle = '#fff6c8';
  ctx.beginPath();
  ctx.moveTo(32, 4); ctx.quadraticCurveTo(35, 29, 60, 32); ctx.quadraticCurveTo(35, 35, 32, 60);
  ctx.quadraticCurveTo(29, 35, 4, 32); ctx.quadraticCurveTo(29, 29, 32, 4);
  ctx.fill();
  return c;
}

function paintPetal() {
  const c = makeCanvas(64, 64);
  const ctx = c.getContext('2d');
  ctx.fillStyle = '#f7b3c0';
  ctx.strokeStyle = 'rgba(190,90,110,.7)';
  ctx.lineWidth = 2.5;
  ctx.beginPath();
  ctx.moveTo(32, 58);
  ctx.bezierCurveTo(8, 44, 8, 14, 24, 8);
  ctx.lineTo(32, 16);
  ctx.lineTo(40, 8);
  ctx.bezierCurveTo(56, 14, 56, 44, 32, 58);
  ctx.fill();
  ctx.stroke();
  ctx.fillStyle = 'rgba(255,255,255,.45)';
  ctx.beginPath();
  ctx.ellipse(26, 30, 5, 10, 0.3, 0, Math.PI * 2);
  ctx.fill();
  return c;
}

/* ---------- 立体の桃（川を流れてくる桃・割れる桃） ---------- */

function addPeachTop(group, R, gradientMap, makePart) {
  const stem = new THREE.Mesh(new THREE.CylinderGeometry(0.05 * R, 0.08 * R, 0.3 * R, 10), new THREE.MeshToonMaterial({ color: '#7b5a41', gradientMap }));
  stem.position.set(0, 1.22 * R, 0);
  stem.rotation.z = -0.25;
  stem.castShadow = true;
  group.add(stem);
  return Promise.all([[0.45, 0.5, 1], [-0.5, -0.45, -1]].map(async ([rz, ry, flip]) => {
    const leaf = await makePart({ src: 'popup/leaf.svg', w: R * 1.15, border: 0.05, density: 100 }, [0.04, 0.5]);
    const g = new THREE.Group();
    g.position.set(0, 1.2 * R, 0.03);
    g.rotation.set(0, ry, rz);
    g.scale.x = flip;
    g.add(leaf);
    group.add(g);
  }));
}

// トゥーン調の陰影と線画のりんかく、紙の葉。川に浮かぶと水面で下半分がかくれ、まわりに波紋
async function buildPeach(spec, card, makePart, tex, root) {
  const R = spec.r ?? 1.8;
  const groove = spec.groove ?? -0.5;
  const gradientMap = toonGradient();
  const group = new THREE.Group();
  const geo = peachGeometry(R, groove);
  const skin = new THREE.Mesh(geo, new THREE.MeshToonMaterial({ map: tex(paintPeach(groove)), gradientMap }));
  skin.castShadow = true;
  skin.receiveShadow = true;
  const hull = new THREE.Mesh(geo, new THREE.MeshBasicMaterial({ color: INK, side: THREE.BackSide }));
  hull.scale.setScalar(1.035);
  group.add(skin, hull);
  await addPeachTop(group, R, gradientMap, makePart);
  const ringGeo = new THREE.RingGeometry(0.93, 1, 64).rotateX(-Math.PI / 2);
  const rings = Array.from({ length: 5 }, () => {
    const m = new THREE.Mesh(ringGeo, new THREE.MeshBasicMaterial({ color: '#ffffff', transparent: true, opacity: 0, depthWrite: false }));
    m.visible = false;
    root.add(m);
    return { m, t0: 0 };
  });
  let lastRing = -9;
  return {
    group,
    update(t) {
      const p = card.hinge.position;
      if (p.y > -R * 0.4 && t - lastRing > 0.85) {
        lastRing = t;
        const r = rings.find((q) => !q.m.visible) ?? rings[0];
        r.m.position.set(p.x, 0.03, p.z);
        r.t0 = t;
        r.m.visible = true;
      }
      for (const r of rings) {
        if (!r.m.visible) continue;
        const k = (t - r.t0) / 1.6;
        if (k >= 1 || k < 0) { r.m.visible = false; continue; }
        const s = R * (0.95 + k * 0.9);
        r.m.scale.set(s, 1, s);
        r.m.material.opacity = 0.55 * (1 - k);
      }
    },
  };
}

// 左右に割れる桃（中から赤ちゃん）。割れ目を手前に向け、下の先を軸に左右へひらく
async function buildSplitPeach(spec, makePart, tex) {
  const R = spec.r ?? 2;
  const gradientMap = toonGradient();
  const skinMat = new THREE.MeshToonMaterial({ map: tex(paintPeach(0)), gradientMap });
  const fleshMat = new THREE.MeshToonMaterial({ map: tex(paintFlesh()), gradientMap, side: THREE.DoubleSide });
  const inkMat = new THREE.MeshBasicMaterial({ color: INK, side: THREE.BackSide });
  const outer = new THREE.Group();
  const body = new THREE.Group();
  body.position.y = 0.95 * R;
  outer.add(body);
  const halves = [1, -1].map((side) => {
    const hinge = new THREE.Group();
    hinge.position.y = -0.95 * R;
    const half = peachHalf(R, side, skinMat, fleshMat, inkMat);
    half.position.y = 0.95 * R;
    hinge.add(half);
    body.add(hinge);
    return { hinge, half, side };
  });
  await addPeachTop(halves[1].half, R, gradientMap, makePart);
  const center = new THREE.Object3D();
  center.position.y = 0.3 * R;
  body.add(center);
  let splitAt = null;
  let wobbleAt = null;
  return {
    group: outer,
    center,
    split(t) { splitAt = t; },
    tap(t) { wobbleAt = t; },
    update(t) {
      const k = splitAt == null ? 0 : spring(clamp01((t - splitAt) / 1.1));
      for (const h of halves) {
        h.hinge.rotation.z = -h.side * 0.62 * k;
        h.hinge.position.x = h.side * 0.22 * R * k;
      }
      const w = wobbleAt == null ? 1 : clamp01((t - wobbleAt) / 0.7);
      body.rotation.z = Math.sin(w * Math.PI * 4) * 0.05 * (1 - w);
    },
  };
}

/* ---------- 動き ---------- */

const ease = { inOut: easeInOut, back: easeOutBack, out: easeOut, linear: (x) => x };

function oscillate(c, t, list, calm) {
  for (const o of list ?? []) {
    const v = calm ? 0 : Math.sin(t * (o.speed ?? 1) + (o.phase ?? 0) + c.phase) * (o.amp ?? 0);
    if (o.part === 'lean') c.inner.rotation.z += v;
    else if (o.part === 'y') c.inner.position.y += Math.abs(v);
    else if (c.parts[o.part]) c.parts[o.part].rotation.z += v;
  }
}

// 関節の角度を、時間や出来事（ctx.flag）にあわせて切りかえる。
// keys: [{ at|on+after, dur, ease, pose: { 部品: 角度, lean, y }, face: { 部品: 表情 }, show/hide: [部品], osc: [...], say, flag }]
function applyPoses(c, t, st, ctx, p) {
  const pose = {};
  const vis = {};
  let osc = p.osc ?? [];
  let face = null;
  (p.keys ?? []).forEach((key, i) => {
    let t0 = key.at ?? 0;
    if (key.on) {
      const s = ctx.since(key.on);
      if (s == null) return;
      t0 = st - s + (key.after ?? 0);
    }
    const raw = i === 0 && !key.on && t0 <= 0 ? 1 : clamp01((st - t0) / (key.dur ?? 0.5));
    if (raw <= 0) return;
    const w = (ease[key.ease] ?? easeInOut)(raw);
    for (const [k, v] of Object.entries(key.pose ?? {})) pose[k] = lerp(pose[k] ?? 0, v, w);
    for (const id of key.show ?? []) vis[id] = true;
    for (const id of key.hide ?? []) vis[id] = false;
    if (key.osc) osc = key.osc;
    if (key.face) face = { ...(face ?? {}), ...key.face };
    if (key.say && !c.state[`said${i}`]) {
      c.state[`said${i}`] = true;
      ctx.say(c, typeof key.say === 'string' ? key.say : undefined);
    }
    if (key.flag && raw >= 1) ctx.flag(key.flag);
  });
  for (const [k, g] of Object.entries(c.parts)) {
    g.rotation.z = (g.userData.rest ?? 0) + (pose[k] ?? 0);
    g.visible = vis[k] ?? !g.userData.hidden;
  }
  c.inner.rotation.z = pose.lean ?? 0;
  c.inner.position.y = pose.y ?? 0;
  c.inner.position.x = pose.x ?? 0; // 木からとびおりる・空からまいおりる
  oscillate(c, t, osc, ctx.calm);
  if (face) for (const [part, name] of Object.entries(face)) ctx.face(c, part, name);
}

const BEHAVIORS = {
  sway(c, t) { c.inner.rotation.z = Math.sin(t * 0.9 + c.phase) * 0.025; },
  drift(c, t) { c.inner.position.x = Math.sin(t * 0.22 + c.phase) * 0.9; },
  ripple(c, t) { c.inner.position.x = Math.sin(t * 0.7 + c.phase) * 0.4; },

  // 関節の角度を、時間や出来事（ctx.flag）にあわせて切りかえる。
  // keys: [{ at|on+after, dur, ease, pose: { 部品: 角度, lean, y }, face: { 部品: 表情 }, osc: [...], say }]
  poses(c, t, st, dt, ctx) { applyPoses(c, t, st, ctx, c.spec.params ?? {}); },

  // 道にそって歩く（足・腕をふり、体を上下）。着いたら idle の動き
  walk(c, t, st, dt, ctx) {
    const p = { delay: 0.8, duration: 6, bob: 0.08, stride: 0.4, step: 0.75, lean: 0.05, ...c.spec.params };
    if (!c.state.route) {
      const pts = sampleCurve(p.path, 16);
      const cum = [0];
      for (let i = 1; i < pts.length; i++) cum.push(cum[i - 1] + Math.hypot(pts[i][0] - pts[i - 1][0], pts[i][1] - pts[i - 1][1]));
      c.state.route = { pts, cum, total: cum[cum.length - 1] };
    }
    const { pts, cum, total } = c.state.route;
    const k = clamp01((st - p.delay) / p.duration);
    const e = 0.5 * k + 0.5 * k * k * (3 - 2 * k);
    const dist = e * total;
    let i = 1;
    while (i < cum.length - 1 && cum[i] < dist) i++;
    const f = (dist - cum[i - 1]) / Math.max(1e-6, cum[i] - cum[i - 1]);
    c.hinge.position.x = lerp(pts[i - 1][0], pts[i][0], f);
    c.hinge.position.z = lerp(pts[i - 1][1], pts[i][1], f);
    const env = k > 0 && k < 1 ? clamp01(Math.min(k / 0.05, (1 - k) / 0.05)) : 0;
    const ph = (dist / p.step) * Math.PI;
    const g = Math.sin(ph) * env;
    for (const [id, part] of Object.entries(c.parts)) part.rotation.z = part.userData.rest ?? 0;
    (p.legs ?? []).forEach((id, n) => { if (c.parts[id]) c.parts[id].rotation.z += (n ? -g : g) * p.stride; });
    (p.arms ?? []).forEach((a, n) => { if (c.parts[a.part]) c.parts[a.part].rotation.z += (a.base ?? 0) + (n % 2 ? g : -g) * (a.amp ?? 0.3); });
    if (env) {
      c.inner.position.y = Math.abs(Math.sin(ph)) * p.bob * env;
      c.inner.rotation.z = -p.lean * env;
    } else {
      // 立ち止まっているあいだは、ポーズ（keys）とゆれ（idle）
      applyPoses(c, t, st, ctx, { keys: p.keys, osc: p.idle });
    }
    if (k >= 1 && !c.state.arrived) {
      c.state.arrived = true;
      if (p.arrive) ctx.flag(p.arrive);
    }
  },

  // にわとり：ときどき地面をつつき、少しずつ歩く
  peck(c, t, st, dt, ctx) {
    const p = { range: 0.7, ...c.spec.params };
    const cycle = (t * 0.4 + c.phase) % 2;
    const pk = cycle < 1 ? Math.max(0, Math.sin(t * 7 + c.phase)) ** 3 : 0;
    c.inner.rotation.z = -0.5 * pk;
    if (!ctx.calm) c.hinge.position.x = c.base.x + Math.sin(t * 0.3 + c.phase) * p.range;
  },

  // 川上（左）から、水の中から浮かびあがって流れてくる桃
  'peach-drift'(c, t, st, dt, ctx) {
    const p = { from: -16, to: -2.5, delay: 0.3, duration: 5.5, lift: 0.1, ...c.spec.params };
    const k = clamp01((st - p.delay) / p.duration);
    const rise = easeOut(clamp01((st - p.delay) / 1.4));
    c.hinge.position.x = lerp(p.from, p.to, easeOut(k));
    c.hinge.position.y = lerp(-3.2, p.lift, rise) + Math.sin(t * 2.2) * 0.12;
    c.inner.rotation.z = Math.sin(t * 1.7) * 0.07 + (1 - k) * Math.sin(t * 3.1) * 0.05;
    if (c.prop) c.inner.rotation.y = Math.sin(t * 0.45) * 0.4;
    if (k >= 1) ctx.flag('peach-arrived');
  },

  // せんたく（肩とひじでこする）→ 桃に気づいて手ぬぐいを持ちあげ、顔をあげる
  'grandma-wash'(c, t, st, dt, ctx) {
    if (ctx.since('peach-arrived') != null && c.state.noticeAt == null) {
      c.state.noticeAt = t;
      ctx.face(c, 'head', 'surprise');
      ctx.say(c);
    }
    const n = c.state.noticeAt == null ? 0 : clamp01((t - c.state.noticeAt) / 0.6);
    const react = n ? easeOutBack(n) : 0;
    const w = 1 - n;
    const a = t * 5.2;
    const { upper, fore, cloth, head } = c.parts;
    // 腕はたらいの縁をこえた先で上下させる（縁より下がると、腕が縁につきささって見える）
    if (upper) upper.rotation.z = (0.02 + Math.sin(a) * 0.045) * w - react * 0.55;
    if (fore) fore.rotation.z = (0.02 + Math.sin(a + 0.7) * 0.12) * w - react * 0.5;
    // 手ぬぐいは腕の回転を打ち消して、いつも下にたれる
    if (cloth) cloth.rotation.z = -((upper?.rotation.z ?? 0) + (fore?.rotation.z ?? 0)) * 0.9 + Math.sin(t * 2.6) * 0.06;
    if (head) head.rotation.z = (0.1 + Math.sin(a + 1.2) * 0.025) * w - react * 0.14;
    c.inner.rotation.z = (0.012 + Math.sin(a) * 0.006) * w - react * 0.02;
    if (st > 0 && w > 0.6) {
      const beat = Math.floor(a / Math.PI); // こするたびに、しぶき
      if (beat !== c.state.beat) {
        c.state.beat = beat;
        ctx.splashFrom(cloth, 0.8);
      }
    } else if (n >= 1 && t - (c.state.dripAt ?? 0) > 0.45) {
      c.state.dripAt = t; // 持ちあげた手ぬぐいから、しずく
      ctx.dripFrom(cloth, [0, -2.1, 0.02]);
    }
  },

  // ふるえて → ぱかっと割れる（光のつぶ）
  'peach-split'(c, t, st, dt, ctx) {
    const p = { shake: 1.3, split: 2.6, ...c.spec.params };
    if (st >= p.split && !c.state.split) {
      c.state.split = true;
      ctx.flag('peach-split');
      c.prop.split(t);
      ctx.burst(c.prop.center, { count: 28, speed: 3.6, size: 0.7 });
      ctx.petals(true);
    }
    const k = st > p.shake && st < p.split ? (st - p.shake) / (p.split - p.shake) : 0;
    c.inner.rotation.z = Math.sin(t * 40) * 0.05 * k;
    c.inner.position.y = k ? Math.abs(Math.sin(t * 20)) * 0.06 * k : 0;
  },

  // 桃の中から、赤ちゃん
  'baby-birth'(c, t, st, dt, ctx) {
    const s0 = ctx.since('peach-split');
    const k = s0 == null ? 0 : clamp01((s0 - 0.2) / 0.9);
    const s = k ? spring(k) : 0;
    c.inner.scale.setScalar(Math.max(0.001, s));
    for (const [id, g] of Object.entries(c.parts)) g.rotation.z = g.userData.rest ?? 0;
    c.inner.position.y = 0;
    c.inner.rotation.z = 0;
    if (k >= 1) oscillate(c, t, c.spec.params?.osc, ctx.calm);
    if (s0 != null && s0 > 0.7 && !c.state.said) {
      c.state.said = true;
      ctx.say(c);
    }
    if (s0 != null) ctx.face(c, 'head', s0 > 3 && Math.sin(t * 0.8) > 0 ? 'smile' : 'normal');
  },
};

/* ---------- 本 ---------- */

const PAGE_T = 0.06; // 1枚の紙の厚み
const TURN_TIME = 1.8; // ページが、のどを軸に起きあがって背のページに重なるまで
const AMBIENT = new Set(['sway', 'drift', 'ripple', 'peck', 'rig']); // 動きを減らす設定では止める動き

// 本（机・表紙・紙の束）はそのままに、ページだけを差しかえる。
// 本は、のど（背のページと台紙の折り目）で綴じてある。めくると、いまの台紙がのどを軸に起きあがって
// 背のページに重なり、その裏が次の場面の背のページになる。下から出てくる次の台紙のパーツは、
// 起きあがるページにぶつからない角度で、いっしょに立ちあがる（本物の飛び出す絵本と同じ）。

// のどから d の所に立つ高さ h のパーツが、角度 W のすきま（上にかぶさるページとの間）に収まる最大の角度
function riseAngle(d, h, W) {
  if (W >= Math.PI / 2 - 1e-4) return Math.PI / 2;
  const lim = W - 0.035;
  if (lim <= 0) return 0;
  if (d <= 0.05) return lim;
  const tip = (a) => Math.atan2(h * Math.sin(a), d + h * Math.cos(a));
  if (tip(Math.PI / 2) <= lim) return Math.PI / 2;
  let lo = 0;
  let hi = Math.PI / 2;
  for (let i = 0; i < 16; i++) {
    const m = (lo + hi) / 2;
    if (tip(m) <= lim) lo = m; else hi = m;
  }
  return lo;
}
export async function createPopupBook(container, { base = '', speak = () => '', reduceMotion = false, onSwipe = null } = {}) {
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

  const camera = new THREE.PerspectiveCamera(34, 16 / 9, 0.5, 200);
  const camBase = new THREE.Vector3(0.4, 7, 21.5);
  const camTarget = new THREE.Vector3(0.6, 3.2, -1.5);
  camera.position.copy(camBase);
  camera.lookAt(camTarget);

  // 光：やわらかい空の光 ＋ 左手前上からの日ざし（影を落とす）
  const hemi = new THREE.HemisphereLight('#f6f9ff', '#a08e6c', 2.1);
  const sun = new THREE.DirectionalLight('#fff0d8', 2.3);
  sun.position.set(-13, 24, 17);
  sun.target.position.set(0, 2, -3);
  sun.castShadow = true;
  sun.shadow.mapSize.set(2048, 2048);
  Object.assign(sun.shadow.camera, { left: -24, right: 24, top: 24, bottom: -24, near: 1, far: 90 });
  sun.shadow.bias = -0.0004;
  sun.shadow.normalBias = 0.03;
  scene.add(hemi, sun, sun.target);

  const makeTex = (canvas, repeat, bin) => {
    const t = new THREE.CanvasTexture(canvas);
    t.colorSpace = THREE.SRGBColorSpace;
    t.anisotropy = maxAniso;
    if (repeat) {
      t.wrapS = t.wrapT = THREE.RepeatWrapping;
      t.repeat.set(...repeat);
    }
    bin?.push(t);
    return t;
  };

  // 机（風呂敷）と本
  const desk = new THREE.Mesh(new THREE.PlaneGeometry(200, 200), new THREE.MeshLambertMaterial({ map: makeTex(paintCloth(), [40, 80]) }));
  desk.rotation.x = -Math.PI / 2;
  desk.position.y = -0.86;
  desk.receiveShadow = true;
  scene.add(desk);

  const coverMat = new THREE.MeshLambertMaterial({ color: '#9c3d2e' });
  const edgeMat = new THREE.MeshLambertMaterial({ color: '#efe4cc' });
  const endMat = new THREE.MeshLambertMaterial({ map: makeTex(paintEndpaper(), [3, 3]) });
  const midZ = BACK_Z + PAGE_D / 2;
  const underGeo = new THREE.PlaneGeometry(PAGE_W, PAGE_D);
  const cover = new THREE.Mesh(new THREE.BoxGeometry(PAGE_W + 1.6, 0.5, PAGE_D + 0.8), coverMat);
  cover.position.set(0, -0.6, midZ + 0.2);
  const block = new THREE.Mesh(new THREE.BoxGeometry(PAGE_W, 0.34 - PAGE_T, PAGE_D), [edgeMat, edgeMat, endMat, edgeMat, edgeMat, edgeMat]);
  block.position.set(0, -PAGE_T - (0.34 - PAGE_T) / 2, midZ);
  const backBlock = new THREE.Mesh(new THREE.BoxGeometry(PAGE_W, PAGE_D, 0.3), [edgeMat, edgeMat, edgeMat, edgeMat, endMat, edgeMat]);
  backBlock.position.set(0, PAGE_D / 2, BACK_Z - PAGE_T - 0.16);
  const backCover = new THREE.Mesh(new THREE.BoxGeometry(PAGE_W + 1.6, PAGE_D + 0.8, 0.5), coverMat);
  backCover.position.set(0, PAGE_D / 2 + 0.2, BACK_Z - PAGE_T - 0.6);
  for (const m of [cover, block, backBlock, backCover]) { m.castShadow = true; m.receiveShadow = true; }
  scene.add(cover, block, backBlock, backCover);

  const look = { x: 0, y: 0 };
  let current = null;
  let turn = null;
  let clock = 0;

  /* ---------- ページを組み立てる ---------- */
  async function buildPage(def) {
    const bin = [];
    const tex = (canvas, repeat) => makeTex(canvas, repeat, bin);
    const pivot = new THREE.Group(); // めくりの軸（のどの左はし・縦）
    pivot.position.set(-PAGE_W / 2, 0, BACK_Z);
    const root = new THREE.Group();
    root.position.set(PAGE_W / 2, 0, -BACK_Z);
    pivot.add(root);
    // 台紙（のどを軸に起きあがって閉じる）。中身の座標は、これまでどおり本の座標
    const ground = new THREE.Group();
    ground.position.z = BACK_Z;
    const stage = new THREE.Group();
    stage.position.z = -BACK_Z;
    ground.add(stage);
    root.add(ground);

    // ページの裏（見返しと同じ紙）。閉じるとき光の当たらない向きになっても暗く沈まないように
    const paperBack = new THREE.MeshLambertMaterial({ map: endMat.map, emissive: '#ffffff', emissiveMap: endMat.map, emissiveIntensity: 0.45 });
    const groundTex = tex(def.floor === 'tatami' ? paintTatami(def) : paintGround(def));
    const groundMat = new THREE.MeshLambertMaterial({ map: groundTex });
    const backTex = tex(def.back === 'interior' ? paintInterior(def) : paintSky(def));
    // 背のページは印刷の色が沈まないよう、少し自分で明るくする
    const backMat = new THREE.MeshLambertMaterial({ map: backTex, emissive: '#ffffff', emissiveMap: backTex, emissiveIntensity: def.back === 'interior' ? 0.25 : 0.35 });
    bin.push(paperBack, groundMat, backMat);

    const sheet = new THREE.Mesh(new THREE.BoxGeometry(PAGE_W, PAGE_T, PAGE_D), [edgeMat, edgeMat, groundMat, paperBack, edgeMat, edgeMat]);
    sheet.position.set(0, -PAGE_T / 2, midZ);
    sheet.receiveShadow = true;
    sheet.castShadow = true;
    stage.add(sheet);

    // 背のページ（のどを軸に、ふせる・起きる）
    const leaf = new THREE.Group();
    leaf.position.set(0, 0, BACK_Z);
    const leafBox = new THREE.Mesh(new THREE.BoxGeometry(PAGE_W, PAGE_D, PAGE_T), [edgeMat, edgeMat, edgeMat, edgeMat, backMat, paperBack]);
    leafBox.position.set(0, PAGE_D / 2, -PAGE_T / 2 - 0.006);
    leafBox.castShadow = true;
    leafBox.receiveShadow = true;
    leaf.add(leafBox);
    root.add(leaf);

    // 川：全幅の帯（river）か、曲がりくねった小川（stream）
    let flowTex = null;
    if (def.river) {
      const depth = def.river.near - def.river.far;
      flowTex = tex(paintRiver(), [2.2, 1]);
      const river = new THREE.Mesh(new THREE.PlaneGeometry(PAGE_W - 0.4, depth), new THREE.MeshStandardMaterial({ map: flowTex, roughness: 0.35, metalness: 0 }));
      river.rotation.x = -Math.PI / 2;
      river.position.set(0, 0.015, def.river.far + depth / 2);
      river.receiveShadow = true;
      stage.add(river);
    }
    if (def.stream) {
      flowTex = tex(paintRiver(), [1, 1]);
      stage.add(streamMesh(def.stream, flowTex));
    }

    const page = {
      def, pivot, root, ground, stage, leaf, bin, flowTex, backMat,
      cards: [], byId: new Map(), standing: [], flags: new Map(), particles: null,
      time: 0, started: false, leafAt: -99, turnLift: null, cover: null, fullAt: null,
    };
    page.particles = createParticles(stage, tex);

    /* 紙のパーツ */
    const makePart = async (spec, pivotAt = [0.5, 1]) => {
      const art = await loadArt(artURL(spec.src, base));
      const widthCm = spec.w ?? art.w * spec.unit; // unit：原画1単位あたりの cm（部品どうしの線の太さをそろえる）
      const paper = cutPaper(art, {
        widthCm,
        density: spec.density ?? (widthCm > 20 ? 48 : 80),
        border: spec.border ?? 0.1,
        blur: spec.blur ?? 0,
        haze: spec.haze ?? 0,
        hazeColor: def.sky?.bottom,
        shade: spec.shade ?? 0,
      });
      const { canvas: c, W, H, pad, density } = paper;
      const geo = new THREE.PlaneGeometry(c.width / density, c.height / density);
      const px = pad + pivotAt[0] * W;
      const py = pad + pivotAt[1] * H;
      geo.translate((c.width / 2 - px) / density, (py - c.height / 2) / density, 0);
      const map = tex(c);
      const mat = new THREE.MeshLambertMaterial({ map, alphaTest: 0.5, alphaToCoverage: true, side: THREE.DoubleSide });
      const mesh = new THREE.Mesh(geo, mat);
      mesh.customDepthMaterial = new THREE.MeshDepthMaterial({ depthPacking: THREE.RGBADepthPacking, map, alphaTest: 0.5 });
      bin.push(geo, mat, mesh.customDepthMaterial);
      mesh.castShadow = true;
      mesh.receiveShadow = true;
      mesh.userData.paper = c;
      mesh.userData.frame = { scale: widthCm / art.w, pivot: [pivotAt[0] * art.w, pivotAt[1] * art.h] };
      if (spec.flip) mesh.scale.x = -1;
      if (spec.sy) mesh.scale.y = spec.sy;
      return mesh;
    };

    const buildProp = async (spec, card) => {
      switch (spec.type) {
        case 'tub': return buildTub(spec, tex);
        case 'peach': return buildPeach(spec, card, makePart, tex, stage);
        case 'split-peach': return buildSplitPeach(spec, makePart, tex);
        case 'house': return buildHouse(spec, tex);
        case 'cushion': return buildCushion(spec, tex);
        default: return null;
      }
    };

    await Promise.all(def.cards.map(async (spec, index) => {
      const hinge = new THREE.Group(); // 下の辺が折り目
      const closer = new THREE.Group(); // 起きあがり・たおれる（大きさ）
      const inner = new THREE.Group(); // ゆれ・はね・歩き
      hinge.add(closer);
      closer.add(inner);
      const card = { spec, index, hinge, closer, inner, parts: {}, faces: {}, props: [], state: {}, phase: (index * 1.7) % (Math.PI * 2) };
      if (spec.parts) {
        // 部品は親の関節（anchor：親の原画上の位置）にとめる。親が回ると子もいっしょに動く
        for (const p of spec.parts) {
          const g = new THREE.Group();
          if (p.type) {
            const prop = await buildProp(p, card);
            g.add(prop.group);
            card.props.push(prop);
          } else {
            const opt = { ...p, unit: p.unit ?? spec.unit, border: p.border ?? spec.border, density: p.density ?? 100 };
            const mesh = await makePart(opt, p.pivot);
            g.add(mesh);
            g.userData.frame = mesh.userData.frame;
            // 表情などの差しかえ（alt は "surprise" の別名）
            const alts = { ...(p.alts ?? {}), ...(p.alt ? { surprise: p.alt } : {}) };
            if (Object.keys(alts).length) {
              const set = { normal: mesh };
              for (const [name, src] of Object.entries(alts)) {
                const m = await makePart({ ...opt, src }, p.pivot);
                m.visible = false;
                g.add(m);
                set[name] = m;
              }
              card.faces[p.id] = set;
            }
          }
          const parent = p.parent ? card.parts[p.parent] : null;
          if (parent && p.anchor) {
            const f = parent.userData.frame;
            g.position.set((p.anchor[0] - f.pivot[0]) * f.scale, (f.pivot[1] - p.anchor[1]) * f.scale, p.z ?? 0);
          } else {
            g.position.set(p.at?.[0] ?? 0, p.at?.[1] ?? 0, p.z ?? 0);
          }
          if (p.rot) g.rotation.z = p.rot;
          g.userData.rest = g.rotation.z;
          g.userData.hidden = Boolean(p.hidden); // 最初はかくしておく部品（手わたす物など）
          g.visible = !p.hidden;
          (parent ?? inner).add(g);
          card.parts[p.id] = g;
        }
      } else if (spec.type) {
        const prop = await buildProp(spec, card);
        card.prop = prop;
        card.props.push(prop);
        inner.add(prop.group);
      } else {
        inner.add(await makePart(spec));
      }
      if (spec.flip) hinge.scale.x = -1;
      if (spec.attach === 'back') {
        hinge.position.set(spec.x ?? 0, spec.y ?? 0, spec.z ?? 0.3);
        leaf.add(hinge);
      } else {
        hinge.position.set(spec.x ?? 0, spec.lift ?? 0, spec.z ?? 0);
        stage.add(hinge);
      }
      card.base = hinge.position.clone();
      page.cards[index] = card;
      page.byId.set(spec.id, card);
    }));

    // パーツの高さと奥の端（起きあがる角度の計算に使う）
    root.updateMatrixWorld(true);
    const box = new THREE.Box3();
    for (const c of page.cards) {
      if (c.spec.attach === 'back') continue;
      box.setFromObject(c.inner);
      c.h = Math.max(0.2, box.max.y - c.hinge.position.y);
      c.backZ = Math.min(box.min.z, c.hinge.position.z);
    }
    foldPage(page);
    return page;
  }

  // 小川：点列にそって帯をつくり、流れの模様を川下へ動かす
  function streamMesh(stream, map) {
    const pts = sampleCurve(stream.points, 16);
    const w = stream.width;
    const pos = [];
    const uv = [];
    const idx = [];
    let len = 0;
    pts.forEach(([x, z], i) => {
      const [ax, az] = pts[Math.max(0, i - 1)];
      const [bx, bz] = pts[Math.min(pts.length - 1, i + 1)];
      const l = Math.hypot(bx - ax, bz - az) || 1;
      const nx = -(bz - az) / l;
      const nz = (bx - ax) / l;
      if (i) len += Math.hypot(x - pts[i - 1][0], z - pts[i - 1][1]);
      for (const s of [-1, 1]) {
        const px = THREE.MathUtils.clamp(x + (nx * w * s) / 2, -PAGE_W / 2 + 0.1, PAGE_W / 2 - 0.1);
        const pz = THREE.MathUtils.clamp(z + (nz * w * s) / 2, BACK_Z + 0.05, FRONT_Z - 0.05);
        pos.push(px, 0.015, pz);
        uv.push(len / 7, s > 0 ? 1 : 0);
      }
      if (i) {
        const k = i * 2;
        idx.push(k - 2, k, k - 1, k - 1, k, k + 1);
      }
    });
    const geo = new THREE.BufferGeometry();
    geo.setAttribute('position', new THREE.Float32BufferAttribute(pos, 3));
    geo.setAttribute('uv', new THREE.Float32BufferAttribute(uv, 2));
    geo.setIndex(idx);
    geo.computeVertexNormals();
    if (geo.attributes.normal.getY(0) < 0) geo.index.array.reverse();
    geo.computeVertexNormals();
    const m = new THREE.Mesh(geo, new THREE.MeshStandardMaterial({ map, roughness: 0.35, metalness: 0, side: THREE.DoubleSide }));
    m.receiveShadow = true;
    return m;
  }

  /* ---------- 起きあがる・たおれる ---------- */

  // 台紙の起きぐあい（lift：0 = 開いて水平、π/2 = 起きて背のページに重なる）と、
  // パーツが立てるすきまの角度（W）から、すべてのパーツの角度を決める
  function foldPage(page) {
    const t = page.time;
    const Q = Math.PI / 2;
    // めくりで下にあるページ（cover がある）は、台紙はずっと水平
    const lift = page.turnLift ?? (page.cover != null ? 0 : page.started ? Q * (1 - easeInOut(clamp01(t / OPEN_TIME))) : Q);
    const W = page.cover ?? Q - lift;
    page.ground.rotation.x = -lift;
    page.ground.position.z = BACK_Z + 0.16 * (lift / Q);
    const full = W > Q - 0.005;
    if (!full) page.fullAt = null;
    else if (page.fullAt == null) page.fullAt = t;
    // 開ききったとき、紙が少しゆれて止まる
    const since = page.fullAt == null ? 9 : t - page.fullAt;
    const wob = since < 2 ? Math.sin(since * 10) * Math.exp(-5 * since) : 0;
    for (const c of page.cards) {
      if (c.spec.attach === 'back') {
        // 背のページの雲など：台紙が起きてくる前に、たたんでしまう
        const k = Math.min(easeOutBack(clamp01((Q - lift - 0.25) / 0.6)), spring(clamp01((t - page.leafAt) / 0.8)));
        c.closer.scale.setScalar(Math.max(0.001, k));
        c.hinge.visible = k > 0.002 && page.leaf.visible;
      } else if (c.spec.pop === false) {
        // 動きで登場するもの（流れてくる桃など）は、開ききってから
        c.closer.scale.setScalar(1);
        c.hinge.visible = full;
      } else if (c.spec.pop === 'grow') {
        const d = c.backZ - BACK_Z;
        const k = full ? 1 + wob * 0.06 : clamp01((d * Math.tan(W)) / c.h);
        c.closer.scale.set(1 + (1 - Math.min(1, k)) * 0.12, Math.max(0.001, k), 1 + (1 - Math.min(1, k)) * 0.12);
        c.hinge.visible = k > 0.01;
      } else {
        // 手前へたおれて台紙に重なる／上のページにふれない角度まで起きる
        const a = riseAngle(c.hinge.position.z - BACK_Z, c.h, W) + (full ? wob * 0.1 : 0);
        c.hinge.rotation.x = Q - a;
        c.hinge.visible = a > 0.03;
        // 人物が持つ立体の小道具（たらいなど）は、紙がたおれる前にしまう（台紙をつきぬけないように）
        if (c.spec.parts) for (const pr of c.props) pr.group.scale.setScalar(Math.max(0.001, clamp01((a - 0.7) / 0.6)));
      }
    }
  }

  /* ---------- 吹き出し ---------- */
  const bubbles = new Map();
  const tmp = new THREE.Vector3();
  function say(page, card, key = card.spec.tap) {
    const text = speak(key);
    if (!text || page !== current || page.settling) return;
    bubbles.get(card)?.el.remove();
    const el = document.createElement('span');
    el.className = 'bubble bubble-3d';
    el.textContent = text;
    overlay.append(el);
    bubbles.set(card, { el, until: clock + 1.9 });
  }
  function clearBubbles() {
    for (const b of bubbles.values()) b.el.remove();
    bubbles.clear();
  }
  function placeBubbles() {
    const rect = renderer.domElement.getBoundingClientRect();
    for (const [card, b] of bubbles) {
      if (clock > b.until) { b.el.remove(); bubbles.delete(card); continue; }
      const box = new THREE.Box3().setFromObject(card.inner);
      tmp.set((box.min.x + box.max.x) / 2, box.max.y + 0.3, (box.min.z + box.max.z) / 2).project(camera);
      b.el.style.left = `${((tmp.x + 1) / 2) * rect.width}px`;
      // 絵の上の端より外へは出さない（吹き出しの下の端の位置）
      b.el.style.top = `${Math.max(b.el.offsetHeight + 6, ((1 - tmp.y) / 2) * rect.height)}px`;
    }
  }

  /* ---------- 動き ---------- */
  const v3 = new THREE.Vector3();
  function contextFor(page) {
    const tubOf = () => page.cards.find((c) => c.prop?.splash);
    return {
      calm: reduceMotion,
      card: (id) => page.byId.get(id),
      flag(name) { if (!page.flags.has(name)) page.flags.set(name, page.time); },
      since(name) { return page.flags.has(name) ? page.time - page.flags.get(name) : null; },
      face(card, part, name = 'normal') {
        const set = card.faces[part];
        if (!set) return;
        for (const [k, m] of Object.entries(set)) m.visible = k === name || (!set[name] && k === 'normal');
      },
      say(card, key) { say(page, card, key); },
      splashFrom(obj, strength = 1) {
        const tub = tubOf();
        if (!tub || !obj) return;
        obj.getWorldPosition(v3);
        tub.inner.worldToLocal(v3);
        tub.prop.splash(v3.x, v3.z, strength);
      },
      dripFrom(obj, offset) {
        const tub = tubOf();
        if (!tub || !obj) return;
        v3.set(...offset);
        obj.localToWorld(v3);
        tub.inner.worldToLocal(v3);
        tub.prop.drip(v3);
      },
      burst(obj, opts) {
        obj.getWorldPosition(v3);
        page.stage.worldToLocal(v3);
        page.particles.burst(v3, opts);
      },
      petals(on) { page.particles.petals = on && !reduceMotion; },
    };
  }

  function resetPage(page) {
    page.time = 0;
    page.flags.clear();
    page.fullAt = null;
    for (const c of page.cards) {
      c.state = {};
      c.hinge.position.copy(c.base);
      c.inner.position.set(0, 0, 0);
      c.inner.rotation.set(0, 0, 0);
      c.inner.scale.set(1, 1, 1);
      for (const g of Object.values(c.parts)) { g.rotation.z = g.userData.rest ?? 0; g.visible = !g.userData.hidden; }
      for (const set of Object.values(c.faces)) for (const [k, m] of Object.entries(set)) m.visible = k === 'normal';
    }
    page.particles.clear();
    if (page === current) clearBubbles();
    page.ctx = contextFor(page);
  }

  function stepPage(page, dt) {
    if (!page.started) { foldPage(page); return; }
    page.time += dt;
    const t = page.time;
    const st = t - OPEN_TIME;
    const ctx = page.ctx;
    for (const c of page.cards) {
      const fn = BEHAVIORS[c.spec.behavior];
      if (fn && !(reduceMotion && AMBIENT.has(c.spec.behavior) && c.spec.behavior !== 'rig')) fn(c, t, st, dt, ctx);
      for (const p of c.props) p.update?.(t, dt, ctx);
      if (c.state.jumpAt != null) {
        const j = clamp01((t - c.state.jumpAt) / 0.5);
        c.inner.position.y = Math.sin(j * Math.PI) * 0.8;
      }
      // 部品で組んだ人物は、はねずに体をゆらす
      const wk = c.state.wiggleAt == null ? 1 : clamp01((t - c.state.wiggleAt) / 0.6);
      c.hinge.rotation.z = Math.sin(wk * Math.PI * 3) * 0.035 * (1 - wk);
    }
    if (page.flowTex && !reduceMotion) page.flowTex.offset.x -= dt * (page.def.stream ? 0.12 : 0.035);
    page.particles.update(t, dt);
    foldPage(page);
  }

  function startPage(page) {
    resetPage(page);
    page.started = true;
    if (reduceMotion) {
      // 動きを減らす設定：最初から完成した場面を見せる
      const settle = page.def.settle ?? 9;
      page.settling = true;
      while (page.time < settle) stepPage(page, 1 / 30);
      page.settling = false;
      page.particles.clear();
    }
  }

  function disposePage(page) {
    page.pivot.removeFromParent();
    for (const x of page.bin) x.dispose?.();
    // 本と共有している素材（紙の縁・見返し）は残し、このページで作ったものだけ片づける
    const shared = new Set([edgeMat, endMat]);
    page.root.traverse((o) => {
      if (o.isMesh || o.isSprite) {
        o.geometry?.dispose?.();
        for (const m of [].concat(o.material ?? [])) if (!shared.has(m)) m.dispose?.();
      }
    });
  }

  function applyView(def, from = null, k = 1) {
    const cam = def.camera ?? {};
    const pos = new THREE.Vector3(...(cam.position ?? [0.4, 7, 21.5]));
    const tgt = new THREE.Vector3(...(cam.target ?? [0.6, 3.2, -1.5]));
    const amb = def.light?.ambient ?? 2.1;
    const sunI = def.light?.sun ?? 2.3;
    if (from) {
      const fc = from.camera ?? {};
      pos.lerpVectors(new THREE.Vector3(...(fc.position ?? [0.4, 7, 21.5])), pos, k);
      tgt.lerpVectors(new THREE.Vector3(...(fc.target ?? [0.6, 3.2, -1.5])), tgt, k);
      hemi.intensity = lerp(from.light?.ambient ?? 2.1, amb, k);
      sun.intensity = lerp(from.light?.sun ?? 2.3, sunI, k);
    } else {
      hemi.intensity = amb;
      sun.intensity = sunI;
    }
    camBase.copy(pos);
    camTarget.copy(tgt);
    if (cam.fov && Math.abs(camera.fov - cam.fov) > 0.01) {
      camera.fov = from ? lerp(from.camera?.fov ?? 34, cam.fov, k) : cam.fov;
      camera.updateProjectionMatrix();
    }
  }

  /* ---------- めくり ---------- */
  const prepared = new Map();
  function prepare(def) {
    if (!prepared.has(def)) {
      prepared.set(def, buildPage(def));
      // 使われないまま古くなった下ごしらえは片づける
      if (prepared.size > 3) {
        const [oldDef, p] = prepared.entries().next().value;
        prepared.delete(oldDef);
        p.then(disposePage, () => {});
      }
    }
    return prepared.get(def);
  }

  let pending = null;
  async function show(def, { dir = 1 } = {}) {
    const next = await prepare(def);
    prepared.delete(def);
    if (turn) {
      // めくり中なら、終わってから
      pending = { def, dir };
      prepared.set(def, Promise.resolve(next));
      return;
    }
    scene.add(next.pivot);
    if (!current || reduceMotion) {
      if (current) disposePage(current);
      clearBubbles();
      current = next;
      applyView(def);
      startPage(next);
      return;
    }
    clearBubbles();
    current.particles.petals = false;
    current.particles.clear();
    // A：のどを軸に起きる／ふせるページ（進むときは今のページ、もどるときは前のページ）
    // B：その下にある、あとのページ。B の背のページは A の台紙の裏に刷ってある
    const A = dir > 0 ? current : next;
    const B = dir > 0 ? next : current;
    A.under = new THREE.Mesh(underGeo, B.backMat);
    A.under.rotation.x = Math.PI / 2;
    A.under.position.set(0, -PAGE_T - 0.03, midZ);
    A.stage.add(A.under);
    B.leaf.visible = false;
    resetPage(next);
    next.started = true;
    next.time = OPEN_TIME - TURN_TIME; // めくり終わると同時に、場面の動きが始まる
    turn = { A, B, from: current, to: next, dir, t: 0, fromDef: current.def };
    current = next;
    stepTurn(0);
  }

  function stepTurn(dt) {
    turn.t += dt;
    const { A, B, from, to, dir } = turn;
    const k = clamp01(turn.t / TURN_TIME);
    const e = easeInOut(k);
    const lift = (Math.PI / 2) * (dir > 0 ? e : 1 - e);
    A.turnLift = lift;
    B.cover = lift; // B のパーツは、上にかぶさる A の台紙にふれない角度まで起きる
    B.pivot.visible = lift > 0.004;
    // めくるあいだは、少し引いて本全体を見せる
    const d = Math.sin(e * Math.PI);
    dolly.set(0, 0.8 * d, 3 * d);
    applyView(to.def, turn.fromDef, e);
    stepPage(from, dt);
    if (k >= 1) {
      A.under.removeFromParent();
      A.under = null;
      A.turnLift = null;
      B.cover = null;
      B.leaf.visible = true;
      B.leafAt = B.time;
      B.pivot.visible = true;
      disposePage(from);
      dolly.set(0, 0, 0);
      turn = null;
      if (pending) {
        const p = pending;
        pending = null;
        show(p.def, { dir: p.dir });
      }
    }
  }

  /* ---------- 時間 ---------- */
  const dolly = new THREE.Vector3();
  let last = performance.now();
  function update(dt) {
    clock += dt;
    if (turn) stepTurn(dt);
    if (current) stepPage(current, dt);
    // 視点：マウス・指の位置に合わせて少しまわりこむ
    const idle = reduceMotion ? 0 : Math.sin(clock * 0.35) * 0.25;
    const tx = camBase.x + look.x * 2.2 + idle;
    const ty = camBase.y - look.y * 1.2 + dolly.y;
    camera.position.x += (tx - camera.position.x) * 0.06;
    camera.position.y += (ty - camera.position.y) * 0.06;
    camera.position.z = camBase.z + dolly.z;
    camera.lookAt(camTarget);
  }

  /* ---------- 操作 ---------- */
  const raycaster = new THREE.Raycaster();
  const pointer = new THREE.Vector2();
  let down = null;

  function pick(e) {
    if (!current?.started || turn) return null;
    const rect = renderer.domElement.getBoundingClientRect();
    pointer.set(((e.clientX - rect.left) / rect.width) * 2 - 1, -((e.clientY - rect.top) / rect.height) * 2 + 1);
    raycaster.setFromCamera(pointer, camera);
    const meshes = [];
    for (const c of current.cards) if (c.spec.tap && c.hinge.visible) c.inner.traverse((o) => { if (o.isMesh && o.visible) meshes.push([o, c]); });
    const hits = raycaster.intersectObjects(meshes.map(([m]) => m), false);
    for (const hit of hits) {
      // 透明なところは素通りさせる（紙の形どおりにタップを判定）
      const c = hit.object.userData.paper;
      if (!c) return meshes.find(([m]) => m === hit.object)[1];
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
  el.addEventListener('pointerdown', (e) => { down = { x: e.clientX, y: e.clientY, t: performance.now() }; });
  el.addEventListener('pointerup', (e) => {
    const dx = down ? e.clientX - down.x : 0;
    const dy = down ? e.clientY - down.y : 0;
    const quick = down && performance.now() - down.t < 600;
    down = null;
    if (e.pointerType !== 'mouse') { look.x = 0; look.y = 0; }
    // すばやく横にはらうと、ページをめくる（左へはらう = つぎへ）
    if (onSwipe && quick && Math.abs(dx) > 60 && Math.abs(dx) > Math.abs(dy) * 1.5) { onSwipe(dx < 0 ? 1 : -1); return; }
    if (Math.hypot(dx, dy) > 8) return;
    const c = pick(e);
    if (!c) return;
    const t = current.time;
    if (c.prop?.tap) c.prop.tap(t);
    else if (c.spec.parts) c.state.wiggleAt = t;
    else c.state.jumpAt = t;
    say(current, c);
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
  let frozen = false;
  function frame(now) {
    const dt = Math.min(0.05, (now - last) / 1000);
    last = now;
    if (!frozen) update(dt);
    renderer.render(scene, camera);
    placeBubbles();
    raf = requestAnimationFrame(frame);
  }
  raf = requestAnimationFrame(frame);

  return {
    show,
    prepare(def) { prepare(def).catch((err) => console.error(err)); },
    get busy() { return Boolean(turn); },
    // 確認用：いまのページとめくりの状態
    get debug() { return { current, turn, camera }; },
    replay() {
      if (!current || turn) return;
      clearBubbles();
      startPage(current);
    },
    // 確認用：指定した秒数まで一気に進める（めくり中なら、めくりも進める）
    seek(t) {
      if (turn) { while (turn) update(1 / 30); }
      if (!current) return;
      startPage(current);
      while (current.time < t) update(1 / 30);
    },
    // 確認用：時間を止める（止めたまま seek / seekTurn で進める）
    freeze(on = true) { frozen = on; },
    // 確認用：めくりの途中の時点を見る
    seekTurn(t) {
      while (turn && turn.t < t) update(1 / 30);
    },
    destroy() {
      cancelAnimationFrame(raf);
      ro.disconnect();
      if (current) disposePage(current);
      renderer.dispose();
      container.replaceChildren();
    },
  };
}

// 1場面だけの舞台（試作ページ用）
export async function createPopupStage(container, def, opts = {}) {
  const book = await createPopupBook(container, opts);
  await book.show(def);
  return book;
}

/* ---------- 光のつぶ・花びら・けむり ---------- */

function createParticles(root, tex) {
  const starTex = tex(paintStar());
  const petalTex = tex(paintPetal());
  const pool = Array.from({ length: 60 }, () => {
    const sp = new THREE.Sprite(new THREE.SpriteMaterial({ map: starTex, transparent: true, depthWrite: false }));
    sp.visible = false;
    root.add(sp);
    return { sp, v: new THREE.Vector3(), t0: 0, life: 1, size: 0.4, spin: 0, kind: 'star' };
  });
  const take = () => pool.find((p) => !p.sp.visible);
  let lastPetal = 0;
  const api = {
    petals: false,
    burst(pos, { count = 18, speed = 3.2, size = 0.5 } = {}) {
      for (let i = 0; i < count; i++) {
        const p = take();
        if (!p) return;
        const a = Math.random() * Math.PI * 2;
        const up = 0.4 + Math.random() * 0.9;
        p.kind = 'star';
        p.sp.material.map = starTex;
        p.sp.position.copy(pos);
        p.v.set(Math.cos(a) * speed * (0.4 + Math.random() * 0.6), up * speed, Math.sin(a) * speed * 0.4);
        p.t0 = now;
        p.life = 0.9 + Math.random() * 0.6;
        p.size = size * (0.6 + Math.random() * 0.8);
        p.sp.visible = true;
      }
    },
    clear() { for (const p of pool) p.sp.visible = false; },
    update(t, dt) {
      now = t;
      if (api.petals && t - lastPetal > 0.18) {
        lastPetal = t;
        const p = take();
        if (p) {
          p.kind = 'petal';
          p.sp.material.map = petalTex;
          p.sp.position.set((Math.random() - 0.5) * 30, 10 + Math.random() * 3, -8 + Math.random() * 12);
          p.v.set(0.6 + Math.random() * 0.6, -1.1 - Math.random() * 0.5, 0);
          p.t0 = t;
          p.life = 9;
          p.size = 0.35 + Math.random() * 0.2;
          p.spin = (Math.random() - 0.5) * 3;
          p.sp.visible = true;
        }
      }
      for (const p of pool) {
        if (!p.sp.visible) continue;
        const k = (t - p.t0) / p.life;
        if (k >= 1 || k < 0 || p.sp.position.y < 0) { p.sp.visible = false; continue; }
        if (p.kind === 'star') {
          p.v.y -= 4 * dt;
          p.v.multiplyScalar(1 - 1.5 * dt);
          p.sp.material.opacity = 1 - k;
          p.sp.scale.setScalar(p.size * (1 - k * 0.5));
        } else {
          p.v.x = 0.6 + Math.sin(t * 1.3 + p.t0) * 0.8;
          p.sp.material.rotation += p.spin * dt;
          p.sp.material.opacity = Math.min(1, (t - p.t0) * 2);
          p.sp.scale.setScalar(p.size);
        }
        p.sp.position.addScaledVector(p.v, dt);
      }
    },
  };
  let now = 0;
  return api;
}
