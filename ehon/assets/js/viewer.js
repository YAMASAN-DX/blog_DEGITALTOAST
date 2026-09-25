// お話ビューア：シーンを右方向へめくり、レイヤーを飛び出す絵本のように起こす
import {
  LANGS, UI, detectLang, rememberLang, store, fetchJSON, h, pick,
  rubyHTML, artURL, createLayer, isCreditVerified, reduceMotion,
} from './common.js';

const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];

const els = {
  book: $('.book'),
  stage: $('.stage'),
  text: $('.story-text'),
  title: $('.story-title'),
  back: $('.back-link'),
  prev: $('[data-act="prev"]'),
  next: $('[data-act="next"]'),
  dots: $('.progress'),
  count: $('.page-count'),
  hint: $('.hint'),
  jaTools: $('.ja-tools'),
};

const state = {
  id: 'momotaro',
  base: '',
  story: null,
  text: {},
  credits: new Map(),
  lang: detectLang(),
  index: 0,
  current: null,
  running: new Set(),
  ruby: store.get('ruby', '1') === '1',
};

const pageCount = () => state.story.scenes.length + 1; // 最後は「おしまい・出典」ページ
const isEndPage = (i) => i === pageCount() - 1;

init();

async function init() {
  const id = new URLSearchParams(location.search).get('id') || 'momotaro';
  if (!/^[a-z0-9-]+$/.test(id)) return showError();
  state.id = id;
  state.base = `stories/${id}/`;
  try {
    const [story, ...texts] = await Promise.all([
      fetchJSON(`${state.base}story.json`),
      ...LANGS.map((l) => fetchJSON(`${state.base}text.${l}.json`)),
    ]);
    state.story = story;
    LANGS.forEach((l, k) => { state.text[l] = texts[k]; });
  } catch (err) {
    console.error(err);
    return showError();
  }
  for (const c of state.story.credits ?? []) if (c.id) state.credits.set(c.id, c);
  auditCredits();
  buildDots();
  bindEvents();
  applyLang();
  const m = location.hash.match(/^#p(\d+)$/);
  go(m ? Number(m[1]) - 1 : 0, 0);
}

function showError() {
  els.text.textContent = UI[state.lang].loadError;
}

/* ---------- 出典 ---------- */

// 出典が未登録・未確認の素材は表示しない（公開前の確認漏れを防ぐ）
function layerAllowed(spec) {
  if (!spec.credit) return true;
  return isCreditVerified(state.credits.get(spec.credit));
}

function auditCredits() {
  state.story.scenes.forEach((scene, i) => {
    for (const spec of scene.layers) {
      if (!spec.credit) continue;
      const c = state.credits.get(spec.credit);
      if (!c) console.warn(`[ehon] ${i + 1}ページ目 ${spec.src}: 出典 "${spec.credit}" が credits にないため表示しません`);
      else if (!isCreditVerified(c)) console.warn(`[ehon] 出典 "${spec.credit}" は保護期間満了の確認記録が不足しているため、素材を表示しません`);
    }
  });
}

function renderCredits() {
  const L = state.lang;
  const ui = UI[L];
  const section = h('section', 'credits');
  const heading = h('h2', null, ui.credits);
  heading.id = 'credits-heading';
  section.setAttribute('aria-labelledby', heading.id);
  const list = h('ul', 'credit-list');
  for (const c of state.story.credits ?? []) {
    if (!isCreditVerified(c)) continue;
    const li = h('li');
    if (c.kind === 'ndl') {
      const title = (L !== 'ja' && c.titleEn) || c.title;
      const [before, after] = ui.ndl.format(title, c.year);
      const link = h('a', null, c.url);
      link.href = c.url;
      link.target = '_blank';
      link.rel = 'noopener';
      const p = h('p');
      p.append(before, link, after);
      li.append(p);
      if (c.modified) li.append(h('p', null, ui.ndl.modified));
    } else {
      li.append(h('p', null, ui.creditLine(pick(c.label, L), pick(c.body, L))));
    }
    list.append(li);
  }
  section.append(heading, list);
  return section;
}

/* ---------- ページ生成 ---------- */

function buildScene(scene) {
  const el = h('div', 'scene');
  for (const spec of scene.layers) {
    if (!layerAllowed(spec)) continue;
    const layer = createLayer(spec, state.base);
    if (spec.tap) {
      layer.classList.add('is-tappable');
      layer.dataset.tap = spec.tap;
      layer.tabIndex = 0;
      layer.setAttribute('role', 'button');
    }
    el.append(layer);
  }
  labelScene(el);
  return el;
}

// 言語が変わったときも呼ぶ：タイトル札とタップできる絵の読み上げ名
function labelScene(el) {
  const t = state.text[state.lang];
  const ui = UI[state.lang];
  for (const card of $$('.layer-title', el)) {
    $('.title-main', card).textContent = t.title;
    $('.title-sub', card).textContent = t.subtitle ?? '';
  }
  for (const layer of $$('.is-tappable', el)) {
    layer.setAttribute('aria-label', ui.tapLabel(t.names?.[layer.dataset.tap] ?? layer.dataset.tap));
  }
}

function buildEnd() {
  const el = h('div', 'scene endcard');
  fillEnd(el);
  return el;
}

function fillEnd(el) {
  const ui = UI[state.lang];
  const inner = h('div', 'endcard-inner');
  const actions = h('div', 'end-actions');
  const again = h('button', 'btn btn-primary', ui.again);
  again.type = 'button';
  again.addEventListener('click', () => go(0, 1));
  const others = h('a', 'btn btn-quiet', ui.others);
  others.href = `index.html?lang=${state.lang}`;
  actions.append(again, others);
  inner.append(h('p', 'fin', ui.fin), actions, renderCredits());
  el.replaceChildren(inner);
}

/* ---------- めくり ---------- */

// dir: 1 = 右へ進む（新しいページは右から入る） / -1 = 戻る / 0 = 最初の表示
function go(target, dir) {
  const i = Math.max(0, Math.min(pageCount() - 1, target));
  if (state.current && i === state.index) return;
  if (dir == null) dir = Math.sign(i - state.index) || 1;
  flush();

  const old = state.current;
  const end = isEndPage(i);
  const scene = end ? null : state.story.scenes[i];
  const page = end ? buildEnd() : buildScene(scene);
  state.index = i;
  state.current = page;
  if (scene?.sky) els.stage.style.setProperty('--sky', scene.sky);
  els.book.classList.toggle('is-end', end);
  els.stage.append(page);

  if (old) leave(old, dir);
  enter(page, old ? dir : 0);
  renderText(Boolean(old));
  updateNav();
  syncURL();
  preload(i + 1);
}

function track(anim) {
  state.running.add(anim);
  const done = () => state.running.delete(anim);
  anim.finished.then(done, done);
  return anim;
}

// めくり途中で次の操作が来たら、途中のアニメーションを終わらせてから進む
function flush() {
  for (const a of state.running) {
    try { a.finish(); } catch { /* 取り消し済み */ }
  }
  state.running.clear();
  $$('.scene.is-leaving', els.stage).forEach((s) => s.remove());
}

function enter(page, dir) {
  if (reduceMotion() || page.classList.contains('endcard')) {
    track(page.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 350, delay: dir ? 150 : 0, easing: 'ease-out', fill: 'backwards' }));
    return;
  }
  const W = els.stage.clientWidth;
  $$('.layer', page).forEach((el, k) => {
    // 奥のものほどゆっくり、手前のものほど大きく横に動く（視差）
    const dx = dir * W * (0.05 + Number(el.dataset.depth) * 0.22);
    const delay = (dir ? 160 : 80) + k * 55 + (Number(el.dataset.delay) || 0) * 1000;
    const frames = el.dataset.enter === 'fade'
      ? [{ transform: `translateX(${dx}px)`, opacity: 0 }, { transform: 'translateX(0px)', opacity: 1 }]
      : [
          { transform: `translateX(${dx}px) rotateX(80deg)`, opacity: 0 },
          { opacity: 1, offset: 0.2 },
          { transform: 'translateX(0px) rotateX(-10deg)', offset: 0.68 },
          { transform: 'translateX(0px) rotateX(4deg)', offset: 0.85 },
          { transform: 'translateX(0px) rotateX(0deg)', opacity: 1 },
        ];
    track(el.animate(frames, { duration: 900, delay, easing: 'cubic-bezier(.22,.7,.3,1)', fill: 'backwards' }));
  });
}

function leave(old, dir) {
  old.classList.add('is-leaving');
  old.inert = true;
  old.setAttribute('aria-hidden', 'true');
  let anims;
  if (reduceMotion() || old.classList.contains('endcard')) {
    anims = [old.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 250, fill: 'forwards' })];
  } else {
    const W = els.stage.clientWidth;
    // 手前から順にたたむ
    anims = $$('.layer', old).reverse().map((el, k) => {
      const dx = -dir * W * (0.05 + Number(el.dataset.depth) * 0.22);
      return el.animate([
        { transform: 'translateX(0px) rotateX(0deg)', opacity: 1 },
        { transform: `translateX(${dx}px) rotateX(80deg)`, opacity: 0 },
      ], { duration: 460, delay: k * 20, easing: 'cubic-bezier(.5,0,.75,.35)', fill: 'forwards' });
    });
  }
  anims.forEach(track);
  Promise.allSettled(anims.map((a) => a.finished)).then(() => old.remove());
}

function preload(i) {
  const scene = state.story.scenes[i];
  if (!scene) return;
  for (const spec of scene.layers) {
    if (spec.src) new Image().src = artURL(spec.src, state.base);
  }
}

/* ---------- 文章・操作部 ---------- */

function renderText(animate) {
  const t = state.text[state.lang];
  const src = isEndPage(state.index) ? t.end : t.scenes[state.story.scenes[state.index].text];
  els.text.innerHTML = rubyHTML(src ?? '');
  els.text.scrollTop = 0;
  els.text.scrollLeft = 0;
  if (animate && !reduceMotion()) {
    els.text.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 450, delay: 200, easing: 'ease-out', fill: 'backwards' });
  }
}

function buildDots() {
  const total = pageCount();
  for (let k = 0; k < total; k++) {
    const li = h('li');
    if (k === total - 1) li.className = 'is-end';
    const b = h('button', 'dot');
    b.type = 'button';
    b.dataset.page = k;
    li.append(b);
    els.dots.append(li);
  }
}

function updateNav() {
  const i = state.index;
  const total = pageCount();
  els.prev.disabled = i === 0;
  els.next.disabled = i === total - 1;
  $$('.dot', els.dots).forEach((b, k) => {
    if (k === i) b.setAttribute('aria-current', 'step');
    else b.removeAttribute('aria-current');
  });
  els.count.textContent = `${i + 1} / ${total}`;
  els.hint.textContent = state.current?.querySelector('.is-tappable') ? UI[state.lang].tapHint : '';
}

function applyLang() {
  const L = state.lang;
  const ui = UI[L];
  const t = state.text[L];
  document.documentElement.lang = L;
  document.body.dataset.lang = L;
  $$('[data-ui]').forEach((el) => { el.textContent = ui[el.dataset.ui]; });
  $$('[data-lang]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.lang === L)));
  $('[data-toggle="ruby"]').setAttribute('aria-pressed', String(state.ruby));
  els.jaTools.hidden = L !== 'ja';
  document.body.classList.toggle('hide-ruby', !state.ruby);

  els.title.textContent = t.title;
  document.title = `${t.title}｜${ui.site}`;
  els.back.href = `index.html?lang=${L}`;
  els.back.setAttribute('aria-label', ui.backLabel);
  els.stage.setAttribute('aria-label', ui.stage);
  els.prev.setAttribute('aria-label', ui.prev);
  els.next.setAttribute('aria-label', ui.next);
  const total = pageCount();
  $$('.dot', els.dots).forEach((b, k) => {
    b.setAttribute('aria-label', k === total - 1 ? ui.goEnd : ui.goPage(k + 1, total));
  });

  if (state.current) {
    if (state.current.classList.contains('endcard')) fillEnd(state.current);
    else labelScene(state.current);
    renderText(false);
    updateNav();
  }
}

function syncURL() {
  try {
    const url = new URL(location.href);
    url.searchParams.set('id', state.id);
    url.searchParams.set('lang', state.lang);
    url.hash = `p${state.index + 1}`;
    history.replaceState(null, '', url);
  } catch {
    /* 埋め込み表示などで URL を変えられない場合はそのまま */
  }
}

/* ---------- タップ・スワイプ・キー操作 ---------- */

function poke(layer) {
  const img = $('img', layer);
  if (img && !reduceMotion()) {
    img.animate([
      { transform: 'translateY(0) scale(1, 1)' },
      { transform: 'translateY(0) scale(1.06, .92)', offset: 0.15 },
      { transform: 'translateY(-16%) scale(.96, 1.05)', offset: 0.45 },
      { transform: 'translateY(0) scale(1.04, .96)', offset: 0.8 },
      { transform: 'translateY(0) scale(1, 1)' },
    ], { duration: 560, easing: 'ease-out' });
  }
  const line = state.text[state.lang].say?.[layer.dataset.tap];
  if (!line) return;
  $('.bubble', layer)?.remove();
  const bubble = h('span', 'bubble', line);
  layer.append(bubble);
  layer.classList.add('is-speaking');
  clearTimeout(layer.bubbleTimer);
  layer.bubbleTimer = setTimeout(() => {
    bubble.remove();
    layer.classList.remove('is-speaking');
  }, 1700);
}

function bindEvents() {
  els.prev.addEventListener('click', () => go(state.index - 1, -1));
  els.next.addEventListener('click', () => go(state.index + 1, 1));
  els.dots.addEventListener('click', (e) => {
    const b = e.target.closest('.dot');
    if (b) go(Number(b.dataset.page));
  });

  $$('[data-lang]').forEach((b) => b.addEventListener('click', () => {
    if (state.lang === b.dataset.lang) return;
    state.lang = b.dataset.lang;
    rememberLang(state.lang);
    applyLang();
    syncURL();
  }));
  $('[data-toggle="ruby"]').addEventListener('click', () => {
    state.ruby = !state.ruby;
    store.set('ruby', state.ruby ? '1' : '0');
    applyLang();
  });

  // スワイプ：左へ払うと次のページ（物語は右へ進む）
  let start = null;
  let swiped = false;
  els.stage.addEventListener('pointerdown', (e) => {
    start = { x: e.clientX, y: e.clientY, id: e.pointerId };
    swiped = false;
  });
  els.stage.addEventListener('pointerup', (e) => {
    if (!start || start.id !== e.pointerId) return;
    const dx = e.clientX - start.x;
    const dy = e.clientY - start.y;
    start = null;
    if (Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy) * 1.2) {
      swiped = true;
      if (dx < 0) go(state.index + 1, 1);
      else go(state.index - 1, -1);
    }
  });
  els.stage.addEventListener('pointercancel', () => { start = null; });

  els.stage.addEventListener('click', (e) => {
    if (swiped) { swiped = false; return; }
    const layer = e.target.closest('.layer.is-tappable');
    if (layer) poke(layer);
  });
  els.stage.addEventListener('keydown', (e) => {
    if ((e.key === 'Enter' || e.key === ' ') && e.target.matches('.layer.is-tappable')) {
      e.preventDefault();
      poke(e.target);
    }
  });

  // マウスの位置でレイヤーを少しずらし、奥行きを見せる
  els.stage.addEventListener('pointermove', (e) => {
    if (e.pointerType !== 'mouse' || reduceMotion() || !state.current) return;
    const r = els.stage.getBoundingClientRect();
    const nx = (e.clientX - r.left) / r.width - 0.5;
    const ny = (e.clientY - r.top) / r.height - 0.5;
    for (const el of $$('.layer', state.current)) {
      const d = Number(el.dataset.depth);
      el.style.translate = `${(-nx * d * 26).toFixed(1)}px ${(-ny * d * 10).toFixed(1)}px`;
    }
  });
  els.stage.addEventListener('pointerleave', () => {
    if (state.current) $$('.layer', state.current).forEach((el) => { el.style.translate = ''; });
  });

  document.addEventListener('keydown', (e) => {
    if (e.defaultPrevented || e.altKey || e.ctrlKey || e.metaKey) return;
    const step = { ArrowRight: 1, PageDown: 1, ArrowLeft: -1, PageUp: -1 }[e.key];
    if (step) go(state.index + step, step);
    else if (e.key === 'Home') go(0, -1);
    else if (e.key === 'End') go(pageCount() - 1, 1);
    else return;
    e.preventDefault();
  });
}
