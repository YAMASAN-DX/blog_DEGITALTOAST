// 立体版の試作ページ：1場面（川上から桃が流れてくる）
import { UI, LANGS, detectLang, rememberLang, store, fetchJSON, rubyHTML, reduceMotion } from './common.js';
import { createPopupStage } from './popup3d.js';

const $ = (sel) => document.querySelector(sel);
const STORY = 'momotaro';
const SCENE = new URLSearchParams(location.search).get('scene')?.replace(/[^a-z0-9-]/g, '') || 'peach';
const base = `stories/${STORY}/`;
const EXTRA = {
  ja: { loading: 'よみこみ中…', replay: 'もういちど', hint3d: '絵をタップしたり、なぞったりしてみてね', noWebGL: 'この端末では立体表示ができません。' },
  en: { loading: 'Loading…', replay: 'Replay', hint3d: 'Tap the pictures, or drag to look around', noWebGL: 'This device cannot show the 3D pop-up.' },
};

const state = { lang: detectLang(), ruby: store.get('ruby', '1') === '1', text: {}, def: null };
const ui = (key) => EXTRA[state.lang][key] ?? UI[state.lang][key];

async function init() {
  const [def, ...texts] = await Promise.all([
    fetchJSON(`${base}3d/${SCENE}.json`),
    ...LANGS.map((l) => fetchJSON(`${base}text.${l}.json`)),
  ]);
  state.def = def;
  LANGS.forEach((l, k) => { state.text[l] = texts[k]; });
  bind();
  render();

  const stageEl = $('.stage-3d');
  try {
    const stage = await createPopupStage(stageEl, def, {
      base,
      reduceMotion: reduceMotion(),
      speak: (key) => state.text[state.lang].say?.[key] ?? '',
      label: (key) => state.text[state.lang][key] ?? (key === 'end-title' ? 'めでたし めでたし' : ''),
    });
    $('.stage3d-status')?.remove();
    if (new URLSearchParams(location.search).has('debug')) window.ehonStage = stage;
    $('[data-act="replay"]').addEventListener('click', () => stage.replay());
  } catch (err) {
    console.error(err);
    $('.stage3d-status').textContent = ui('noWebGL');
  }
}

function render() {
  const t = state.text[state.lang];
  document.documentElement.lang = state.lang;
  document.body.dataset.lang = state.lang;
  document.querySelectorAll('[data-ui]').forEach((el) => { el.textContent = ui(el.dataset.ui); });
  document.querySelectorAll('[data-lang]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.lang === state.lang)));
  $('[data-toggle="ruby"]').setAttribute('aria-pressed', String(state.ruby));
  $('.ja-tools').hidden = state.lang !== 'ja';
  document.body.classList.toggle('hide-ruby', !state.ruby);
  $('.story-title').textContent = t.title;
  $('.story-text').innerHTML = rubyHTML(t.scenes[state.def.text] ?? '');
  $('.hint').textContent = ui('hint3d');
  $('.stage-3d').setAttribute('aria-label', UI[state.lang].stage);
}

function bind() {
  document.querySelectorAll('[data-lang]').forEach((b) => b.addEventListener('click', () => {
    state.lang = b.dataset.lang;
    rememberLang(state.lang);
    render();
  }));
  $('[data-toggle="ruby"]').addEventListener('click', () => {
    state.ruby = !state.ruby;
    store.set('ruby', state.ruby ? '1' : '0');
    render();
  });
}

init().catch((err) => {
  console.error(err);
  $('.story-text').textContent = UI[state.lang].loadError;
});
