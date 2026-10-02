# 「ももたろう」立体版を、1つのフォルダに置ける形にまとめる（python3 ehon/tools/build_deploy.py 出力先 [公開URL] [OGP画像]）
# 例：python3 ehon/tools/build_deploy.py /tmp/momotaro https://d-toast.com/ai/momotaro/ og.jpg
#   → 出力先の中身を、サーバーの /ai/momotaro/ にそのまま置く
# 入口（index.html）は立体版。WebGL が使えない端末用に、平面の絵本（story.html）も入れる。
import html
import os
import shutil
import sys

SRC = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..')
STORY = 'momotaro'
TITLE = 'とびだす昔話「ももたろう」立体版'
DESC = 'ページをめくると、紙の切り絵が立体に起きあがる飛び出す絵本。日本語・英語で読めて、ふりがなも切りかえられます。'


def copy(rel, dst):
    s = os.path.join(SRC, rel)
    d = os.path.join(dst, rel)
    os.makedirs(os.path.dirname(d), exist_ok=True)
    if os.path.isdir(s):
        shutil.copytree(s, d, dirs_exist_ok=True)
    else:
        shutil.copy2(s, d)


def head_tags(url, og_name):
    if not url:
        return ''
    e = html.escape
    tags = [f'<link rel="canonical" href="{e(url)}">',
            '<meta property="og:type" content="website">',
            f'<meta property="og:title" content="{e(TITLE)}">',
            f'<meta property="og:description" content="{e(DESC)}">',
            f'<meta property="og:url" content="{e(url)}">',
            '<meta name="twitter:card" content="summary_large_image">']
    if og_name:
        tags.append(f'<meta property="og:image" content="{e(url + og_name)}">')
        tags.append('<meta property="og:image:width" content="1200">')
        tags.append('<meta property="og:image:height" content="630">')
    return '\n'.join(tags) + '\n'


def page(src_name, url, og_name, title=None):
    text = open(os.path.join(SRC, src_name), encoding='utf-8').read()
    if title:
        text = text.replace(text[text.index('<title>'):text.index('</title>') + 8], f'<title>{html.escape(title)}</title>')
        text = text.replace('<meta name="description"', head_tags(url, og_name) + '<meta name="description"', 1)
        text = text.replace(text[text.index('<meta name="description"'):text.index('>', text.index('<meta name="description"')) + 1],
                            f'<meta name="description" content="{html.escape(DESC)}">')
    # このフォルダにはお話の一覧がないので、「一覧」へのリンクはかくす
    text = text.replace('<a class="back-link" href="index.html">', '<a class="back-link" href="index.html" hidden style="display:none">')
    return text


def main():
    if len(sys.argv) < 2:
        sys.exit(__doc__ or 'usage: build_deploy.py 出力先 [公開URL] [OGP画像]')
    dst = sys.argv[1]
    url = sys.argv[2] if len(sys.argv) > 2 else ''
    og = sys.argv[3] if len(sys.argv) > 3 else ''
    if url and not url.endswith('/'):
        url += '/'
    shutil.rmtree(dst, ignore_errors=True)
    os.makedirs(dst)
    for rel in ['assets/css', 'assets/vendor/three', 'assets/art', f'stories/{STORY}', '.htaccess',
                'assets/js/common.js', 'assets/js/popup3d.js', 'assets/js/story3d.js', 'assets/js/viewer.js']:
        copy(rel, dst)
    og_name = ''
    if og:
        og_name = 'og' + os.path.splitext(og)[1]
        shutil.copy2(og, os.path.join(dst, og_name))
    open(os.path.join(dst, 'index.html'), 'w', encoding='utf-8').write(page('story3d.html', url, og_name, TITLE))
    open(os.path.join(dst, 'story.html'), 'w', encoding='utf-8').write(page('story.html', url, og_name))
    # 立体版が WebGL を使えないときの行き先は story.html（同じフォルダ）
    n = sum(len(f) for _, _, f in os.walk(dst))
    print(f'{n} files → {dst}')


main()
