// お話一覧：stories/index.json から本棚を作る
import { UI, detectLang, rememberLang, fetchJSON, createLayer, h, pick } from './common.js';

const shelf = document.querySelector('.shelf');
let lang = detectLang();
let stories = [];

init();

async function init() {
  document.querySelectorAll('[data-lang]').forEach((b) => b.addEventListener('click', () => {
    lang = b.dataset.lang;
    rememberLang(lang);
    render();
  }));
  try {
    ({ stories } = await fetchJSON('stories/index.json'));
  } catch (err) {
    console.error(err);
  }
  render();
}

function render() {
  const ui = UI[lang];
  document.documentElement.lang = lang;
  document.title = ui.site;
  document.querySelectorAll('[data-ui]').forEach((el) => { el.textContent = ui[el.dataset.ui]; });
  document.querySelectorAll('[data-lang]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.lang === lang)));
  shelf.replaceChildren(...(stories.length ? stories.map(card) : [h('li', 'shelf-error', ui.loadError)]));
  try {
    history.replaceState(null, '', `?lang=${lang}`);
  } catch {
    /* URL を変えられない環境ではそのまま */
  }
}

function card(story) {
  const ui = UI[lang];
  const ready = story.status === 'ready';
  const li = h('li', ready ? 'story-card' : 'story-card is-soon');
  const wrap = h(ready ? 'a' : 'div', 'card-link');
  if (ready) wrap.href = `story.html?id=${encodeURIComponent(story.id)}&lang=${lang}`;

  const thumb = h('div', 'thumb');
  thumb.style.setProperty('--sky', story.sky ?? '#cfe6ee');
  (story.thumb ?? []).forEach((spec, k) => {
    const layer = createLayer(spec, `stories/${story.id}/`);
    layer.style.setProperty('--i', k);
    thumb.append(layer);
  });
  if (!ready) thumb.append(h('span', 'badge', ui.soon));

  const body = h('div', 'card-body');
  body.append(
    h('h2', 'card-title', pick(story.title, lang)),
    h('p', 'card-sub', pick(story.subtitle, lang)),
    h('p', 'card-summary', pick(story.summary, lang)),
  );
  if (ready) body.append(h('span', 'cta', ui.read));
  wrap.append(thumb, body);
  li.append(wrap);
  // 立体版（試作）があれば、別のリンクで
  if (ready && story.pop3d) {
    const a3 = h('a', 'card-3d', ui.read3d);
    a3.href = `${story.pop3d}?id=${encodeURIComponent(story.id)}&lang=${lang}`;
    li.append(a3);
  }
  return li;
}
