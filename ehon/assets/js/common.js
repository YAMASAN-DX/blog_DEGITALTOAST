// 一覧・ビューア共通：言語、保存、データ読み込み、ルビ、レイヤー生成、出典チェック

export const LANGS = ['ja', 'en'];

export const UI = {
  ja: {
    site: 'とびだす昔話',
    siteSub: 'ページをめくると、絵が起きあがる。日本の昔話えほん。',
    libNote: 'のせているのは、作者のいない伝承の昔話です。文章と絵はこのサイトで作ったもので、各お話の最後に「素材・出典」をのせています。',
    back: '一覧',
    backLabel: 'おはなし一覧へもどる',
    prev: 'まえへ',
    next: 'つぎへ',
    ruby: 'ふりがな',
    stage: 'お話の絵',
    tapHint: '絵をタップしてみてね',
    tapLabel: (name) => `${name}（タップすると動きます）`,
    goPage: (n, total) => `${n}ページ目へ（全${total}ページ）`,
    goEnd: 'おしまいのページへ',
    fin: 'おしまい',
    again: 'はじめから読む',
    others: 'ほかのお話をえらぶ',
    credits: '素材・出典',
    read: 'よむ',
    read3d: '立体版で読む（1〜10場面・制作中）',
    soon: 'じゅんびちゅう',
    loadError: 'お話を読みこめませんでした。時間をおいて、ページを再読みこみしてください。',
    creditLine: (label, body) => `${label}：${body}`,
    ndl: {
      // 画像出典：『［書名］』（［出版年］）、国立国会図書館デジタルコレクション（［資料URL］）。
      format: (title, year) => [`画像出典：『${title}』（${year}）、国立国会図書館デジタルコレクション（`, '）。'],
      modified: '掲載画像を切り抜き・加工し、アニメーションを付加しています。',
    },
  },
  en: {
    site: 'Pop-up Folktales',
    siteSub: 'Japanese folktales that stand up off the page.',
    libNote: 'Every story here is a traditional folktale with no single author. The text and pictures are made for this site, and each story ends with a Sources & Credits page.',
    back: 'Stories',
    backLabel: 'Back to all stories',
    prev: 'Back',
    next: 'Next',
    ruby: 'Furigana',
    stage: 'Story illustration',
    tapHint: 'Tap the pictures!',
    tapLabel: (name) => `${name} (tap to animate)`,
    goPage: (n, total) => `Go to page ${n} of ${total}`,
    goEnd: 'Go to the last page',
    fin: 'The End',
    again: 'Read again',
    others: 'Choose another story',
    credits: 'Sources & Credits',
    read: 'Read',
    read3d: 'Read the 3D pop-up (scenes 1–10, in progress)',
    soon: 'Coming soon',
    loadError: 'The story could not be loaded. Please reload the page in a moment.',
    creditLine: (label, body) => `${label}: ${body}`,
    ndl: {
      format: (title, year) => [`Image source: “${title}” (${year}), National Diet Library Digital Collections (`, ').'],
      modified: 'The images have been cropped and edited, and animation has been added.',
    },
  },
};

export const store = {
  get(key, fallback = null) {
    try {
      const v = localStorage.getItem(`ehon:${key}`);
      return v === null ? fallback : v;
    } catch {
      return fallback;
    }
  },
  set(key, value) {
    try {
      localStorage.setItem(`ehon:${key}`, value);
    } catch {
      /* 保存できない環境では何もしない */
    }
  },
};

// URL の ?lang= → 前回の選択 → ブラウザの言語 の順で決める
export function detectLang() {
  const q = new URLSearchParams(location.search).get('lang');
  if (LANGS.includes(q)) return q;
  const saved = store.get('lang');
  if (LANGS.includes(saved)) return saved;
  return (navigator.language || 'ja').toLowerCase().startsWith('ja') ? 'ja' : 'en';
}

export const rememberLang = (lang) => store.set('lang', lang);

export const reduceMotion = () => matchMedia('(prefers-reduced-motion: reduce)').matches;

export async function fetchJSON(url) {
  const res = await fetch(url, { cache: 'no-cache' });
  if (!res.ok) throw new Error(`${url}: ${res.status}`);
  return res.json();
}

export function h(tag, className, text) {
  const el = document.createElement(tag);
  if (className) el.className = className;
  if (text != null) el.textContent = text;
  return el;
}

export const pick = (obj, lang) => obj?.[lang] ?? obj?.ja ?? '';

const escapeHTML = (s) => s.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);
const toRuby = (_, base, rt) => `<ruby>${base}<rp>（</rp><rt>${rt}</rt><rp>）</rp></ruby>`;

// 青空文庫式のルビ記法を <ruby> に変換する。
//   漢字《かんじ》 … 直前の漢字の連なりにふりがな
//   ｜親文字《おやもじ》 … 範囲を明示
export function rubyHTML(src = '') {
  return escapeHTML(src)
    .replace(/｜([^｜《》]+)《([^》]+)》/g, toRuby)
    .replace(/([㐀-鿿々〆ヶ]+)《([^》]+)》/g, toRuby)
    .replace(/\n/g, '<br>');
}

// 絵の置き場所：共通素材は assets/art/、お話専用は "./" で始めてお話フォルダから
export const artURL = (src, storyBase = '') =>
  src.startsWith('./') ? storyBase + src.slice(2) : `assets/art/${src}`;

// 位置は舞台（16:9）に対する %：x = 中心、y = 下端、w = 幅
const PHASED = new Set(['bob', 'hop', 'hover', 'sway', 'drift', 'float', 'tremble']);

export function createLayer(spec, storyBase = '') {
  const w = spec.w ?? 20;
  const layer = h('div', 'layer');
  layer.style.left = `${spec.x - w / 2}%`;
  layer.style.bottom = `${spec.y}%`;
  layer.style.width = `${w}%`;
  layer.dataset.depth = spec.depth ?? 0.5;
  if (spec.delay) layer.dataset.delay = spec.delay;
  if (spec.enter) layer.dataset.enter = spec.enter;

  const idle = h('div', 'idle');
  if (spec.anim) {
    idle.classList.add(`anim-${spec.anim}`);
    // 同じ動きのキャラクターがそろって動かないよう、位相をずらす
    if (PHASED.has(spec.anim)) idle.style.animationDelay = `${(-Math.random() * 2).toFixed(2)}s`;
  }
  layer.append(idle);

  if (spec.type === 'title') {
    layer.classList.add('layer-title');
    const card = h('div', 'title-card');
    card.append(h('span', 'title-main'), h('span', 'title-sub'));
    idle.append(card);
  } else {
    const img = document.createElement('img');
    img.src = artURL(spec.src, storyBase);
    img.alt = '';
    img.decoding = 'async';
    img.draggable = false;
    if (spec.flip) img.classList.add('flip');
    idle.append(img);
  }
  return layer;
}

// 国立国会図書館の画像は「インターネット公開（保護期間満了）」の確認記録があるものだけ使う
export const NDL_PUBLIC_DOMAIN = 'インターネット公開（保護期間満了）';

export function isCreditVerified(credit) {
  if (!credit) return false;
  if (credit.kind !== 'ndl') return true;
  return (
    credit.rights?.status === NDL_PUBLIC_DOMAIN &&
    /^\d{4}-\d{2}-\d{2}$/.test(credit.rights?.checkedOn ?? '') &&
    /^https:\/\/dl\.ndl\.go\.jp\//.test(credit.url ?? '') &&
    Boolean(credit.title) &&
    Boolean(credit.year)
  );
}
