// 立体版のお話ビューア：場面ごとに、ページをふせて左へめくり、次の場面が起きあがる
import { UI, LANGS, detectLang, rememberLang, store, fetchJSON, rubyHTML, reduceMotion, h, pick, isCreditVerified } from './common.js';
import { createPopupBook } from './popup3d.js';

const $ = (sel) => document.querySelector(sel);
const EXTRA = {
  ja: {
    loading: 'よみこみ中…',
    replay: 'もういちど',
    hint3d: '絵をタップしたり、なぞったりしてみてね',
    noWebGL: 'この端末では立体表示ができません。平面の絵本をひらきます…',
    more: 'この先は、立体版を制作中です。',
    moreLink: '平面の絵本で、つづきを読む',
    endTitle: 'めでたし めでたし',
  },
  en: {
    loading: 'Loading…',
    replay: 'Replay',
    hint3d: 'Tap the pictures, or drag to look around',
    noWebGL: 'This device cannot show the 3D pop-up. Opening the flat picture book…',
    more: 'The rest of the 3D book is still being made.',
    moreLink: 'Read on in the flat picture book',
    endTitle: 'The End',
  },
};

const params = new URLSearchParams(location.search);
const id = /^[a-z0-9-]+$/.test(params.get('id') ?? '') ? params.get('id') : 'momotaro';
const base = `stories/${id}/`;
const state = { lang: detectLang(), ruby: store.get('ruby', '1') === '1', text: {}, defs: [], book: null, index: -1, credits: [] };
const ui = (key) => EXTRA[state.lang][key] ?? UI[state.lang][key];
let stage = null;

async function init() {
  const [book, story, ...texts] = await Promise.all([
    fetchJSON(`${base}3d/book.json`),
    fetchJSON(`${base}story.json`).catch(() => ({})),
    ...LANGS.map((l) => fetchJSON(`${base}text.${l}.json`)),
  ]);
  LANGS.forEach((l, k) => { state.text[l] = texts[k]; });
  state.book = book;
  state.credits = story.credits ?? [];
  state.defs = await Promise.all(book.scenes.map((f) => fetchJSON(`${base}3d/${f}`)));
  buildDots();
  bind();

  try {
    stage = await createPopupBook($('.stage-3d'), {
      base,
      reduceMotion: reduceMotion(),
      speak: (key) => state.text[state.lang].say?.[key] ?? '',
      // 題字の札の文字（表紙の題名、おしまいの「めでたし めでたし」）
      label: (key) => (key === 'end-title' ? ui('endTitle') : state.text[state.lang][key] ?? ''),
      onSwipe: (dir) => go(state.index + dir, dir),
    });
  } catch (err) {
    // WebGL が使えないときは、平面の絵本へ
    console.error(err);
    $('.stage3d-status').textContent = ui('noWebGL');
    setTimeout(() => location.replace(`story.html?id=${id}&lang=${state.lang}`), 1500);
    return;
  }
  if (params.has('debug')) window.ehonStage = stage;
  const m = location.hash.match(/^#p(\d+)$/);
  await go(m ? Number(m[1]) - 1 : 0, 1);
  $('.stage3d-status')?.remove();
}

async function go(target, dir) {
  const i = Math.max(0, Math.min(state.defs.length - 1, target));
  if (i === state.index) return;
  if (dir == null) dir = Math.sign(i - state.index) || 1;
  state.index = i;
  render();
  syncURL();
  await stage.show(state.defs[i], { dir });
  // となりのページを下ごしらえしておく
  for (const k of [i + 1, i - 1]) if (state.defs[k]) stage.prepare(state.defs[k]);
}

function buildDots() {
  $('.progress').replaceChildren(...state.defs.map((_, k) => {
    const li = h('li');
    const b = h('button', 'dot');
    b.type = 'button';
    b.addEventListener('click', () => go(k));
    li.append(b);
    return li;
  }));
}

function render() {
  const t = state.text[state.lang];
  const def = state.defs[state.index];
  document.documentElement.lang = state.lang;
  document.body.dataset.lang = state.lang;
  document.title = `${t.title}（${state.lang === 'ja' ? '立体版' : '3D'}）｜${UI[state.lang].site}`;
  document.querySelectorAll('[data-ui]').forEach((el) => { el.textContent = ui(el.dataset.ui); });
  document.querySelectorAll('[data-lang]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.lang === state.lang)));
  $('[data-toggle="ruby"]').setAttribute('aria-pressed', String(state.ruby));
  $('.ja-tools').hidden = state.lang !== 'ja';
  document.body.classList.toggle('hide-ruby', !state.ruby);
  $('.story-title').textContent = t.title;
  $('.stage-3d').setAttribute('aria-label', UI[state.lang].stage);
  $('.hint').textContent = ui('hint3d');
  $('[data-act="prev"]').setAttribute('aria-label', ui('prev'));
  $('[data-act="next"]').setAttribute('aria-label', ui('next'));
  if (!def) return;
  $('.story-text').innerHTML = rubyHTML((def.end ? t.end : t.scenes[def.text]) ?? '');
  // おしまいのページ：素材・出典
  $('.credits')?.remove();
  if (def.end) $('.continue-note').before(renderCredits());
  $('.page-count').textContent = `${state.index + 1} / ${state.defs.length}`;
  document.querySelectorAll('.dot').forEach((d, k) => {
    if (k === state.index) d.setAttribute('aria-current', 'step'); else d.removeAttribute('aria-current');
    d.setAttribute('aria-label', `${k + 1}`);
  });
  $('[data-act="prev"]').disabled = state.index === 0;
  $('[data-act="next"]').disabled = state.index === state.defs.length - 1;
  // 最後の立体ページ：つづきは平面の絵本で
  const note = $('.continue-note');
  const cont = state.book.continue;
  note.hidden = !(cont && state.index === state.defs.length - 1);
  if (!note.hidden) {
    const a = h('a', null, ui('moreLink'));
    a.href = `story.html?id=${id}&lang=${state.lang}#p${cont.page}`;
    note.replaceChildren(h('span', null, `${ui('more')} `), a);
  }
}

function renderCredits() {
  const L = state.lang;
  const u = UI[L];
  const section = h('section', 'credits');
  const heading = h('h2', null, u.credits);
  const list = h('ul', 'credit-list');
  for (const c of state.credits) {
    if (!isCreditVerified(c)) continue;
    const li = h('li');
    if (c.kind === 'ndl') {
      const [before, after] = u.ndl.format((L !== 'ja' && c.titleEn) || c.title, c.year);
      const link = h('a', null, c.url);
      link.href = c.url;
      link.target = '_blank';
      link.rel = 'noopener';
      const p = h('p');
      p.append(before, link, after);
      li.append(p);
      if (c.modified) li.append(h('p', null, u.ndl.modified));
    } else {
      li.append(h('p', null, u.creditLine(pick(c.label, L), pick(c.body, L))));
    }
    list.append(li);
  }
  section.append(heading, list);
  return section;
}

function syncURL() {
  try {
    const url = new URL(location.href);
    url.searchParams.set('lang', state.lang);
    url.hash = `p${state.index + 1}`;
    history.replaceState(null, '', url);
  } catch {
    /* URL を変えられない環境ではそのまま */
  }
}

function bind() {
  document.querySelectorAll('[data-lang]').forEach((b) => b.addEventListener('click', () => {
    state.lang = b.dataset.lang;
    rememberLang(state.lang);
    render();
    syncURL();
    stage?.relabel();
  }));
  $('[data-toggle="ruby"]').addEventListener('click', () => {
    state.ruby = !state.ruby;
    store.set('ruby', state.ruby ? '1' : '0');
    render();
  });
  $('[data-act="prev"]').addEventListener('click', () => go(state.index - 1, -1));
  $('[data-act="next"]').addEventListener('click', () => go(state.index + 1, 1));
  $('[data-act="replay"]').addEventListener('click', () => stage?.replay());
  document.addEventListener('keydown', (e) => {
    if (e.altKey || e.ctrlKey || e.metaKey || e.target.closest?.('input, textarea')) return;
    if (e.key === 'ArrowRight') go(state.index + 1, 1);
    if (e.key === 'ArrowLeft') go(state.index - 1, -1);
  });
}

init().catch((err) => {
  console.error(err);
  $('.story-text').textContent = UI[state.lang].loadError;
});
