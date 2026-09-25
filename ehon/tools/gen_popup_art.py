# 立体版（popup）の背景・小道具の絵を生成する（python3 ehon/tools/gen_popup_art.py）。
# おばあさんの部品は手描きのため、ここでは生成しない。1単位 = 0.022cm（おばあさんと線の太さをそろえる）
import math
import random

import os

# 出力先：ehon/assets/art/popup/（このファイルは ehon/tools/ にある）
OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'assets', 'art', 'popup') + os.sep
INK = '#4a3a30'


def f(v):
    s = f'{v:.1f}'
    return s[:-2] if s.endswith('.0') else s


def write(name, w, h, body, defs='', top=0):
    # top：上にはみだす絵（屋根や煙など）のための余白。下端（地面）の位置は変わらない
    d = f'  <defs>\n{defs}\n  </defs>\n' if defs else ''
    open(OUT + name, 'w').write(
        f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 {-top} {w} {h + top}">\n{d}{body}\n</svg>\n')


def catmull(pts, samples=10):
    """Catmull-Rom で点列をなめらかにつなぎ、細かい点列を返す"""
    out = []
    n = len(pts)
    for i in range(n - 1):
        p0 = pts[max(i - 1, 0)]
        p1, p2 = pts[i], pts[i + 1]
        p3 = pts[min(i + 2, n - 1)]
        for k in range(samples):
            t = k / samples
            t2, t3 = t * t, t * t * t
            x = 0.5 * ((2 * p1[0]) + (-p0[0] + p2[0]) * t + (2 * p0[0] - 5 * p1[0] + 4 * p2[0] - p3[0]) * t2 + (-p0[0] + 3 * p1[0] - 3 * p2[0] + p3[0]) * t3)
            y = 0.5 * ((2 * p1[1]) + (-p0[1] + p2[1]) * t + (2 * p0[1] - 5 * p1[1] + 4 * p2[1] - p3[1]) * t2 + (-p0[1] + 3 * p1[1] - 3 * p2[1] + p3[1]) * t3)
            out.append((x, y))
    out.append(pts[-1])
    return out


def smooth(pts):
    """Catmull-Rom → 3次ベジェの path 文字列"""
    d = f'M{f(pts[0][0])} {f(pts[0][1])}'
    n = len(pts)
    for i in range(n - 1):
        p0 = pts[max(i - 1, 0)]
        p1, p2 = pts[i], pts[i + 1]
        p3 = pts[min(i + 2, n - 1)]
        c1 = (p1[0] + (p2[0] - p0[0]) / 6, p1[1] + (p2[1] - p0[1]) / 6)
        c2 = (p2[0] - (p3[0] - p1[0]) / 6, p2[1] - (p3[1] - p1[1]) / 6)
        d += f'C{f(c1[0])} {f(c1[1])} {f(c2[0])} {f(c2[1])} {f(p2[0])} {f(p2[1])}'
    return d


def y_at(curve, x):
    for (x0, y0), (x1, y1) in zip(curve, curve[1:]):
        if x0 <= x <= x1:
            t = 0 if x1 == x0 else (x - x0) / (x1 - x0)
            return y0 + (y1 - y0) * t
    return curve[-1][1] if x > curve[-1][0] else curve[0][1]


def peaks_and_valleys(pts):
    """制御点から、山頂→次の谷 の組を返す（右斜面の陰に使う）"""
    pairs = []
    for i in range(1, len(pts) - 1):
        if pts[i][1] < pts[i - 1][1] and pts[i][1] <= pts[i + 1][1]:
            j = i + 1
            while j < len(pts) - 1 and pts[j + 1][1] > pts[j][1]:
                j += 1
            pairs.append((pts[i], pts[j]))
    return pairs


def slope_shadows(pts, H, color, opacity, spine=0.22):
    out = []
    for (px, py), (vx, vy) in peaks_and_valleys(pts):
        dx = vx - px
        dy = H - py
        out.append(
            f'<path d="M{f(px)} {f(py - 40)}L{f(vx)} {f(vy - 80)}L{f(vx)} {f(vy)}'
            f'C{f(vx + 2)} {f(vy + (H - vy) * 0.4)} {f(vx - dx * 0.05)} {f(vy + (H - vy) * 0.8)} {f(vx - dx * 0.1)} {H}'
            f'L{f(px + dx * 0.55)} {H}C{f(px + dx * 0.34)} {f(py + dy * 0.72)} {f(px + dx * 0.02)} {f(py + dy * 0.38)} {f(px)} {f(py - 40)}Z" '
            f'fill="{color}" opacity="{opacity}"/>')
        # 山ひだ（谷へおりる細い線）
        out.append(
            f'<path d="M{f(px + dx * 0.25)} {f(py + dy * 0.12)}C{f(px + dx * 0.32)} {f(py + dy * 0.4)} {f(px + dx * 0.5)} {f(py + dy * 0.6)} {f(px + dx * 0.62)} {f(py + dy * 0.9)}" '
            f'fill="none" stroke="{color}" stroke-width="3" stroke-linecap="round" opacity="{opacity}"/>')
    return ''.join(out)


def blob(cx, cy, rx, ry, rnd, n=9, jitter=0.12):
    """ゆがんだ丸（石・茂み）"""
    pts = []
    for k in range(n):
        a = k / n * math.tau
        r = 1 + rnd.uniform(-jitter, jitter)
        pts.append((cx + math.cos(a) * rx * r, cy + math.sin(a) * ry * r))
    pts = pts + pts[:3]
    d = smooth(pts)
    return d + 'Z'


# ---------------------------------------------------------------- 遠くの山
def mountains_far():
    W, H = 1500, 420
    back = [(-20, 250), (80, 190), (170, 150), (260, 200), (360, 165), (470, 120), (560, 175), (660, 205), (760, 150),
            (870, 110), (980, 170), (1080, 200), (1180, 145), (1290, 125), (1390, 185), (1520, 170)]
    front = [(-20, 300), (60, 262), (150, 215), (250, 266), (350, 240), (430, 200), (520, 252), (620, 292), (720, 240),
             (820, 190), (930, 242), (1040, 282), (1140, 230), (1230, 205), (1330, 252), (1430, 236), (1520, 262)]
    rnd = random.Random(3)
    backd = smooth(back) + f'L1520 {H}L-20 {H}Z'
    frontd = smooth(front) + f'L1520 {H}L-20 {H}Z'
    mist = ''.join(
        f'<ellipse cx="{f(x + rnd.uniform(-30, 30))}" cy="{f(H - 34 + rnd.uniform(-10, 10))}" rx="{f(rnd.uniform(120, 170))}" ry="{f(rnd.uniform(20, 30))}"/>'
        for x in range(0, 1560, 150))
    defs = (f'    <clipPath id="front"><path d="{frontd}"/></clipPath>\n    <clipPath id="back"><path d="{backd}"/></clipPath>\n'
            '    <filter id="mist" x="-20%" y="-60%" width="140%" height="220%"><feGaussianBlur stdDeviation="9"/></filter>')
    body = f'''  <path d="{backd}" fill="#c4d7d4"/>
  <g clip-path="url(#back)">{slope_shadows(back, H, '#aac2c0', .6)}</g>
  <path d="{smooth(back)}" fill="none" stroke="#9fb7b5" stroke-width="2"/>
  <path d="{frontd}" fill="#a9c3c1"/>
  <g clip-path="url(#front)">
    {slope_shadows(front, H, '#8fadac', .65)}
  </g>
  <path d="{smooth(front)}" fill="none" stroke="#7f9a9a" stroke-width="2.6"/>
  <g fill="#ffffff" opacity=".5" filter="url(#mist)">{mist}</g>'''
    write('mountains-far.svg', W, H, body, defs)


# ---------------------------------------------------------------- 近くの山（森）
def scallop_path(curve_fn, x0, x1, rnd, step=(16, 26), bulge=0.5):
    x = x0
    y = curve_fn(x)
    d = f'M{f(x)} {f(y)}'
    while x < x1:
        s = rnd.uniform(*step)
        nx = min(x + s, x1 + 20)
        ny = curve_fn(nx)
        d += f'A{f(s * 0.55)} {f(s * bulge)} 0 0 1 {f(nx)} {f(ny)}'
        x, y = nx, ny
    return d


def mountains_mid():
    W, H = 1500, 360
    pts = [(-30, 205), (100, 152), (220, 112), (330, 152), (430, 186), (540, 150), (640, 96), (760, 122), (860, 172),
           (960, 192), (1060, 150), (1170, 106), (1280, 142), (1390, 182), (1530, 160)]
    curve = catmull(pts, 16)
    fy = lambda x: y_at(curve, x)
    rnd = random.Random(11)
    edge = scallop_path(fy, -30, 1530, rnd)
    sil = edge + f'L1530 {H}L-30 {H}Z'
    rows = []
    rnd2 = random.Random(5)
    for k in range(1, 9):
        x = -30 + rnd2.uniform(0, 20)
        while x < 1530:
            s = rnd2.uniform(18, 30)
            y = fy(x + s / 2) + 22 * k + rnd2.uniform(-4, 4)
            rows.append(f'M{f(x)} {f(y)}A{f(s * 0.55)} {f(s * 0.5)} 0 0 1 {f(x + s)} {f(y)}')
            x += s + rnd2.uniform(2, 10)
    mist = ''.join(
        f'<ellipse cx="{f(x + rnd.uniform(-30, 30))}" cy="{f(H - 26 + rnd.uniform(-8, 8))}" rx="{f(rnd.uniform(110, 160))}" ry="{f(rnd.uniform(18, 26))}"/>'
        for x in range(0, 1560, 140))
    defs = (f'    <clipPath id="forest"><path d="{sil}"/></clipPath>\n'
            '    <filter id="mist" x="-20%" y="-60%" width="140%" height="220%"><feGaussianBlur stdDeviation="8"/></filter>')
    body = f'''  <path d="{sil}" fill="#86ad84"/>
  <g clip-path="url(#forest)">
    <path d="{''.join(rows)}" fill="none" stroke="#6b956d" stroke-width="2.2" stroke-linecap="round" opacity=".9"/>
    {slope_shadows(pts, H, '#5f8a64', .5)}
    <path d="{edge}" transform="translate(0 8)" fill="none" stroke="#a9cb9c" stroke-width="5" opacity=".75"/>
  </g>
  <path d="{edge}" fill="none" stroke="#3f5a45" stroke-width="2.8" stroke-linejoin="round"/>
  <g fill="#ffffff" opacity=".42" filter="url(#mist)">{mist}</g>'''
    write('mountains-mid.svg', W, H, body, defs)


# ---------------------------------------------------------------- 向こう岸の丘（茂み・花・小道・家）
def bush(cx, cy, s, rnd):
    parts = [(cx, cy, s), (cx - s * 0.8, cy + s * 0.25, s * 0.72), (cx + s * 0.85, cy + s * 0.2, s * 0.75)]
    if rnd.random() < 0.6:
        parts.append((cx + s * 0.25, cy - s * 0.45, s * 0.7))
    under = ''.join(f'<circle cx="{f(x)}" cy="{f(y)}" r="{f(r)}"/>' for x, y, r in parts)
    light = ''.join(f'<circle cx="{f(x - r * 0.28)}" cy="{f(y - r * 0.3)}" r="{f(r * 0.55)}"/>' for x, y, r in parts)
    dots = ''.join(
        f'<path d="M{f(x + rnd.uniform(-r, r) * 0.7)} {f(y + rnd.uniform(-r, r) * 0.6)}q3 -4 6 0"/>'
        for x, y, r in parts for _ in range(3))
    return (f'<g><g fill="{INK}" stroke="{INK}" stroke-width="6">{under}</g>'
            f'<g fill="#6c9d57">{under}</g>'
            f'<g fill="#8cbc70" opacity=".8">{light}</g>'
            f'<g fill="none" stroke="#4f7f40" stroke-width="1.6" stroke-linecap="round">{dots}</g></g>')


def flower(x, y, color, r=3.2):
    petals = ''.join(f'<circle cx="{f(x + math.cos(a) * r)}" cy="{f(y + math.sin(a) * r)}" r="{f(r * 0.7)}"/>'
                     for a in [k * math.tau / 5 for k in range(5)])
    return f'<g fill="{color}">{petals}</g><circle cx="{f(x)}" cy="{f(y)}" r="{f(r * 0.55)}" fill="#e9a93a"/>'


def little_house(x, y):
    # 丘の上の小さな家（おじいさんとおばあさんの家）
    return f'''<g transform="translate({x} {y})" stroke="{INK}" stroke-width="2.2" stroke-linejoin="round">
    <rect x="-40" y="-28" width="80" height="30" fill="#ecdcb6"/>
    <rect x="-8" y="-20" width="16" height="22" fill="#5a4332"/>
    <rect x="-32" y="-22" width="16" height="12" fill="#fbf6ea"/><rect x="16" y="-22" width="16" height="12" fill="#fbf6ea"/>
    <path d="M-56 -26L-22 -64H22L56 -26Q0 -18 -56 -26Z" fill="#caa960"/>
    <path d="M-40 -34L-14 -58M-20 -32L0 -58M0 -31L14 -58M20 -32L28 -56" stroke="#ae8d49" stroke-width="2"/>
    <rect x="-26" y="-70" width="52" height="8" rx="3" fill="#6b5a3a"/>
  </g>
  <path d="M{x + 18} {y - 78}c-8 -10 8 -16 0 -26s8 -16 2 -24" fill="none" stroke="#ffffff" stroke-width="5" stroke-linecap="round" opacity=".75"/>'''


def hill():
    W, H = 1500, 250
    pts = [(-20, 96), (150, 62), (330, 84), (520, 46), (720, 74), (900, 42), (1100, 66), (1300, 52), (1520, 78)]
    top = catmull(pts, 16)
    fy = lambda x: y_at(top, x)
    rnd = random.Random(21)
    sil = smooth(pts) + f'L1520 {H}L-20 {H}Z'
    grass = []
    for _ in range(620):
        x = rnd.uniform(0, W)
        y = rnd.uniform(fy(x) + 14, H - 6)
        h = rnd.uniform(6, 11)
        grass.append(f'M{f(x)} {f(y)}q{f(rnd.uniform(-2, 2))} {f(-h * 0.6)} {f(rnd.uniform(1, 4))} {f(-h)}')
    shade = ''.join(
        f'<ellipse cx="{f(x)}" cy="{f(fy(x) + 60)}" rx="{f(rnd.uniform(90, 140))}" ry="{f(rnd.uniform(26, 40))}"/>'
        for x in range(60, 1500, 190))
    bushes = ''.join(bush(x, fy(x) + rnd.uniform(20, 34), rnd.uniform(18, 26), rnd) for x in [80, 300, 700, 860, 1020, 1200, 1410])
    # 小道（家からふもとへ）
    path_l = [(466, fy(474) + 6), (454, 110), (484, 160), (540, 208), (566, 252)]
    path_r = [(626, 252), (590, 206), (526, 158), (494, 108), (488, fy(488) + 6)]
    lane = smooth(path_l) + 'L' + smooth(path_r)[1:] + 'Z'
    flowers = ''.join(
        flower(x, rnd.uniform(fy(x) + 24, H - 12), rnd.choice(['#fffaf0', '#f3cf4a', '#f2a6b9', '#fffaf0']), rnd.uniform(2.4, 3.4))
        for x in [rnd.uniform(0, W) for _ in range(46)])
    defs = f'''    <linearGradient id="g" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#a9cf86"/><stop offset="1" stop-color="#8ab86b"/></linearGradient>
    <clipPath id="hill"><path d="{sil}"/></clipPath>'''
    body = f'''  <path d="{sil}" fill="url(#g)"/>
  <g clip-path="url(#hill)">
    <g fill="#7aa85e" opacity=".45">{shade}</g>
    <path d="{smooth([(x, y + 8) for x, y in pts])}" fill="none" stroke="#c0dd9c" stroke-width="7" opacity=".7"/>
    <path d="{''.join(grass)}" fill="none" stroke="#6f9d52" stroke-width="2" stroke-linecap="round" opacity=".8"/>
    <path d="{lane}" fill="#e6d4a4" stroke="#b89f6c" stroke-width="2"/>
    {flowers}
  </g>
  <path d="{smooth(pts)}" fill="none" stroke="{INK}" stroke-width="3" stroke-linejoin="round"/>
  {bushes}
  {little_house(480, fy(480) + 8)}
  {bush(566, fy(566) + 10, 17, rnd)}'''
    write('hill.svg', W, H, body, defs, top=48)


# ---------------------------------------------------------------- 松
def pad(cx, cy, w, h, rnd):
    n = max(4, int(w / 22))
    left, right = cx - w / 2, cx + w / 2
    base = cy + h * 0.28
    d = f'M{f(left)} {f(base)}'
    xs = [left + (w * (k + 1) / (n + 1)) for k in range(n)]
    pts = [(left, base)] + [(x, cy - h * 0.5 + abs(x - cx) / w * h * 0.55 + rnd.uniform(-4, 4)) for x in xs] + [(right, base)]
    for (x0, y0), (x1, y1) in zip(pts, pts[1:]):
        s = x1 - x0
        d += f'A{f(s * 0.62)} {f(s * 0.55)} 0 0 1 {f(x1)} {f(y1)}'
    d += f'Q{f(cx)} {f(base + h * 0.28)} {f(left)} {f(base)}Z'
    return d


def needles(cx, cy, w, h, rnd, color, count):
    out = []
    for _ in range(count):
        x = cx + rnd.uniform(-w * 0.42, w * 0.42)
        y = cy + rnd.uniform(-h * 0.25, h * 0.2)
        for a in (-0.5, 0, 0.5):
            out.append(f'M{f(x)} {f(y)}l{f(math.sin(a) * 7)} {f(-math.cos(a) * 7)}')
    return f'<path d="{"".join(out)}" fill="none" stroke="{color}" stroke-width="1.3" stroke-linecap="round"/>'


def pine():
    W, H = 212, 292
    rnd = random.Random(8)
    trunk = 'M84 290C86 252 70 222 82 188C92 162 110 150 104 118C100 100 104 86 112 72L122 76C116 92 114 106 118 122C126 156 104 172 100 194C92 226 110 254 112 290Z'
    bark = ''.join(f'<path d="M{f(x)} {f(y)}q5 -4 10 0"/>' for x, y in
                   [(88, 270), (96, 252), (86, 236), (92, 214), (100, 200), (104, 172), (108, 150), (106, 130), (110, 110)])
    branches = ('M100 196C82 188 62 184 46 190M104 170C122 164 138 164 152 172M106 138C92 130 76 128 62 132'
                'M110 112C124 104 136 102 146 104M114 84C108 74 102 68 98 62')
    pads = [(46, 200, 92, 40), (150, 174, 86, 40), (62, 138, 104, 44), (142, 106, 88, 38), (100, 62, 118, 50)]
    pad_svg = []
    for cx, cy, w, h in pads:
        d = pad(cx, cy, w, h, rnd)
        pad_svg.append(f'''<g>
    <path d="{d}" fill="#3f7050" stroke="{INK}" stroke-width="3" stroke-linejoin="round"/>
    <clipPath id="p{cx}{cy}"><path d="{d}"/></clipPath>
    <g clip-path="url(#p{cx}{cy})">
      <rect x="{f(cx - w)}" y="{f(cy + h * 0.12)}" width="{f(w * 2)}" height="{f(h)}" fill="#2c5439" opacity=".7"/>
      <path d="{d}" transform="translate(0 6)" fill="none" stroke="#6b9c74" stroke-width="6" opacity=".8"/>
      {needles(cx, cy, w, h, rnd, '#2c5439', int(w / 7))}
      {needles(cx, cy - h * 0.18, w * 0.8, h * 0.5, rnd, '#86b58e', int(w / 12))}
    </g></g>''')
    body = f'''  <g transform="translate(12 0)">
  <path d="{branches}" fill="none" stroke="{INK}" stroke-width="11" stroke-linecap="round"/>
  <path d="{branches}" fill="none" stroke="#6b4d37" stroke-width="6" stroke-linecap="round"/>
  <path d="{trunk}" fill="#7b5a41" stroke="{INK}" stroke-width="3" stroke-linejoin="round"/>
  <path d="M104 118C112 150 96 170 96 194C92 222 104 250 106 290H112C110 254 92 226 100 194C104 172 126 156 118 122Z" fill="#5e4431" opacity=".7"/>
  <g fill="none" stroke="#4f3827" stroke-width="1.8" stroke-linecap="round">{bark}</g>
  {''.join(pad_svg)}
  </g>'''
    write('tree-pine.svg', W, H, body)


# ---------------------------------------------------------------- 丸い木（広葉樹）
def round_tree():
    W, H = 180, 250
    rnd = random.Random(4)
    blobs = [(90, 96, 62), (42, 112, 38), (138, 110, 40), (58, 62, 36), (122, 58, 38), (90, 38, 34), (68, 140, 32), (114, 142, 32)]
    under = ''.join(f'<circle cx="{x}" cy="{y}" r="{r}"/>' for x, y, r in blobs)
    light = ''.join(f'<circle cx="{f(x - r * 0.3)}" cy="{f(y - r * 0.32)}" r="{f(r * 0.58)}"/>' for x, y, r in blobs)
    dark = ''.join(f'<circle cx="{f(x + r * 0.35)}" cy="{f(y + r * 0.4)}" r="{f(r * 0.55)}"/>' for x, y, r in blobs)
    leaves = []
    for x, y, r in blobs:
        for _ in range(int(r / 3)):
            a = rnd.uniform(0, math.tau)
            d = rnd.uniform(0, r * 0.85)
            lx, ly = x + math.cos(a) * d, y + math.sin(a) * d
            rot = rnd.uniform(-60, 60)
            col = '#4f8040' if ly > y else '#a3d087'
            leaves.append(f'<ellipse cx="{f(lx)}" cy="{f(ly)}" rx="4.2" ry="2.2" fill="{col}" transform="rotate({f(rot)} {f(lx)} {f(ly)})"/>')
    body = f'''  <path d="M80 250C84 220 82 190 86 160L98 160C100 190 98 220 106 250Z" fill="#7b5a41" stroke="{INK}" stroke-width="3" stroke-linejoin="round"/>
  <path d="M96 160C98 190 96 220 102 250H106C98 220 100 190 98 160Z" fill="#5e4431"/>
  <path d="M90 196C74 176 60 160 50 140M92 188C108 170 122 156 132 138M88 176C86 156 88 140 90 124" fill="none" stroke="{INK}" stroke-width="9" stroke-linecap="round"/>
  <path d="M90 196C74 176 60 160 50 140M92 188C108 170 122 156 132 138M88 176C86 156 88 140 90 124" fill="none" stroke="#6b4d37" stroke-width="4.5" stroke-linecap="round"/>
  <g fill="{INK}" stroke="{INK}" stroke-width="6">{under}</g>
  <g fill="#6ea35a">{under}</g>
  <g fill="#5a8f48" opacity=".75">{dark}</g>
  <g fill="#8cbe71" opacity=".85">{light}</g>
  {''.join(leaves)}'''
    write('tree-round.svg', W, H, body)


# ---------------------------------------------------------------- 草むら（奥・手前）
def blade(x, base, h, lean, w):
    return (f'M{f(x - w / 2)} {f(base)}Q{f(x + lean * 0.3)} {f(base - h * 0.6)} {f(x + lean)} {f(base - h)}'
            f'Q{f(x + lean * 0.3 + w * 0.35)} {f(base - h * 0.5)} {f(x + w / 2)} {f(base)}Z')


def grass_strip(name, W, H, hmin, hmax, seed, flowers_n, stones=False):
    rnd = random.Random(seed)
    base = H - 10
    blades = []
    x = -10
    while x < W + 10:
        h = rnd.uniform(hmin, hmax)
        blades.append((rnd.choice(['#5e9950', '#6fa55c', '#7fb86a', '#8cc376']), blade(x, base + 4, h, rnd.uniform(-12, 12), rnd.uniform(7, 12))))
        x += rnd.uniform(4, 8)
    order = {'#5e9950': 0, '#6fa55c': 1, '#7fb86a': 2, '#8cc376': 3}
    blades.sort(key=lambda b: order[b[0]])
    under = ''.join(f'<path d="{d}"/>' for _, d in blades)
    fills = ''.join(f'<path d="{d}" fill="{c}"/>' for c, d in blades)
    extra = []
    for _ in range(flowers_n):
        fx = rnd.uniform(10, W - 10)
        top = base - rnd.uniform(hmin * 0.8, hmax * 0.9)
        extra.append(f'<path d="M{f(fx)} {base}Q{f(fx + 3)} {f((base + top) / 2)} {f(fx + 1)} {f(top)}" fill="none" stroke="#4f8a42" stroke-width="2"/>')
        extra.append(flower(fx + 1, top, rnd.choice(['#fffaf0', '#f3cf4a', '#f2a6b9', '#c9b3e6', '#fffaf0']), rnd.uniform(2.8, 4)))
    body = f'''  <rect x="-10" y="{base}" width="{W + 20}" height="{H - base + 2}" fill="{INK}"/>
  <g fill="{INK}" stroke="{INK}" stroke-width="3.2" stroke-linejoin="round">{under}</g>
  <rect x="-10" y="{base + 1.5}" width="{W + 20}" height="{H - base}" fill="#5e9950"/>
  {fills}
  {''.join(extra)}'''
    write(name, W, H, body)


# ---------------------------------------------------------------- 川べりの石
def stones():
    W, H = 1500, 46
    rnd = random.Random(31)
    out = []
    x = 20
    while x < W:
        for _ in range(rnd.randint(2, 4)):
            rx = rnd.uniform(8, 22)
            ry = rx * rnd.uniform(0.55, 0.8)
            cx = x + rnd.uniform(-10, 30)
            cy = H - ry - rnd.uniform(0, 3)
            col = rnd.choice(['#9a958c', '#a8a298', '#8a857c', '#b3ab9c'])
            d = blob(cx, cy, rx, ry, rnd)
            out.append(f'''<path d="{d}" fill="{col}" stroke="{INK}" stroke-width="2.4"/>
  <path d="{blob(cx - rx * 0.25, cy - ry * 0.3, rx * 0.5, ry * 0.4, rnd)}" fill="#ffffff" opacity=".28"/>
  <path d="M{f(cx - rx * 0.6)} {f(cy + ry * 0.55)}Q{f(cx)} {f(cy + ry * 0.95)} {f(cx + rx * 0.75)} {f(cy + ry * 0.35)}" fill="none" stroke="#5b5750" stroke-width="2.2" opacity=".6"/>''')
        x += rnd.uniform(90, 210)
    body = '  ' + '\n  '.join(out) + f'\n  <rect y="{H - 6}" width="{W}" height="6" fill="#3f6f8c" opacity=".35"/>'
    write('stones.svg', W, H, body)


# ---------------------------------------------------------------- さざ波
def ripples():
    W, H = 1500, 44
    rnd = random.Random(41)
    out = []
    x = 10
    while x < W - 40:
        w = rnd.uniform(60, 110)
        h = rnd.uniform(14, 26)
        x0, x1 = x, x + w
        crest = x0 + w * 0.62
        d = f'M{f(x0)} {H}C{f(x0 + w * 0.25)} {H}{f(crest - w * 0.3)} {f(H - h)} {f(crest)} {f(H - h)}C{f(crest + w * 0.12)} {f(H - h)} {f(x1 - w * 0.08)} {f(H - h * 0.4)} {f(x1)} {H}Z'
        out.append(f'''<path d="{d}" fill="#8ec6de" stroke="#4b7f9c" stroke-width="1.8" stroke-linejoin="round"/>
  <path d="M{f(x0 + w * 0.2)} {f(H - h * 0.35)}C{f(x0 + w * 0.4)} {f(H - h * 0.85)} {f(crest - w * 0.08)} {f(H - h)} {f(crest + w * 0.06)} {f(H - h * 0.95)}" fill="none" stroke="#ffffff" stroke-width="3.2" stroke-linecap="round"/>
  <circle cx="{f(crest + w * 0.14)}" cy="{f(H - h * 0.72)}" r="2.6" fill="#ffffff"/>''')
        x += w + rnd.uniform(30, 120)
    write('ripples.svg', W, H, '  ' + '\n  '.join(out))


# ---------------------------------------------------------------- 葦
def reeds():
    W, H = 196, 250
    rnd = random.Random(12)
    leaves = [
        (40, 250, -60, 110, 12, '#5e8f4c'), (70, 250, -30, 170, 12, '#6ea35a'), (96, 250, 34, 150, 12, '#7fb266'),
        (120, 250, 60, 120, 11, '#6ea35a'), (60, 250, 30, 90, 10, '#7fb266'), (110, 250, -20, 70, 10, '#5e8f4c'),
    ]
    under, fills, ribs = [], [], []
    for x, base, lean, h, w, col in leaves:
        d = (f'M{f(x - w / 2)} {base}Q{f(x + lean * 0.2)} {f(base - h * 0.55)} {f(x + lean)} {f(base - h)}'
             f'Q{f(x + lean * 0.35 + w * 0.6)} {f(base - h * 0.5)} {f(x + w / 2)} {base}Z')
        under.append(f'<path d="{d}"/>')
        fills.append(f'<path d="{d}" fill="{col}"/>')
        ribs.append(f'M{f(x)} {base}Q{f(x + lean * 0.3 + 2)} {f(base - h * 0.55)} {f(x + lean * 0.96)} {f(base - h * 0.96)}')
    stems = [(84, 250, 100, 40), (56, 250, 36, 70), (124, 250, 146, 92)]
    stem_d = ''.join(f'M{x0} {y0}Q{f((x0 + x1) / 2 + 4)} {f((y0 + y1) / 2)} {x1} {y1}' for x0, y0, x1, y1 in stems)
    heads = []
    for _, _, x1, y1 in stems:
        heads.append(f'''<rect x="{f(x1 - 6)}" y="{f(y1 - 34)}" width="12" height="36" rx="6" fill="#8a5a36" stroke="{INK}" stroke-width="2.4"/>
  <rect x="{f(x1 + 1)}" y="{f(y1 - 30)}" width="4" height="28" rx="2" fill="#6b4226"/>
  <path d="M{f(x1 - 3)} {f(y1 - 28)}v20" stroke="#b07a4e" stroke-width="2" stroke-linecap="round"/>
  <path d="M{x1} {f(y1 - 34)}v-16" stroke="{INK}" stroke-width="2" stroke-linecap="round"/>''')
    body = f'''  <path d="{stem_d}" fill="none" stroke="{INK}" stroke-width="7" stroke-linecap="round"/>
  <path d="{stem_d}" fill="none" stroke="#6b8f4a" stroke-width="3.5" stroke-linecap="round"/>
  <g fill="{INK}" stroke="{INK}" stroke-width="4.5" stroke-linejoin="round">{''.join(under)}</g>
  {''.join(fills)}
  <path d="{''.join(ribs)}" fill="none" stroke="#9ccb80" stroke-width="1.6" stroke-linecap="round" opacity=".8"/>
  {''.join(heads)}'''
    write('reeds.svg', W, H, body)


# ---------------------------------------------------------------- 雲
def cloud():
    W, H = 240, 110
    back = 'M40 90C18 92 14 66 34 60C30 38 60 28 76 44C82 18 128 12 140 40C156 22 196 30 196 56C222 54 234 82 212 92Z'
    front = 'M30 100C8 100 6 74 28 70C26 48 56 38 72 54C80 30 122 24 134 50C150 34 186 40 186 64C212 62 226 90 204 100Z'
    body = f'''  <path d="{back}" fill="#d9e5ee" transform="translate(12 -8)"/>
  <path d="{front}" fill="#ffffff" stroke="#a9bccb" stroke-width="2.6" stroke-linejoin="round"/>
  <path d="M34 100C60 90 150 90 204 100" fill="none" stroke="#d9e5ee" stroke-width="8" stroke-linecap="round"/>
  <g fill="none" stroke="#c6d5e0" stroke-width="2.2" stroke-linecap="round">
    <path d="M84 66c-6 -12 10 -20 18 -10c6 8 -4 16 -10 10"/>
    <path d="M150 72c-6 -10 8 -18 15 -9c5 7 -3 13 -8 9"/>
  </g>'''
    write('cloud.svg', W, H, body)


# ---------------------------------------------------------------- 桃の葉
def leaf():
    W, H = 100, 44
    shape = 'M4 22C24 4 64 2 96 20C64 40 26 40 4 22Z'
    body = f'''  <clipPath id="leaf"><path d="{shape}"/></clipPath>
  <path d="{shape}" fill="#7bb85e"/>
  <g clip-path="url(#leaf)"><path d="M0 22L96 20L100 44H0Z" fill="#5e9a48"/></g>
  <path d="M6 22L94 20" stroke="#4a7a3a" stroke-width="2" stroke-linecap="round"/>
  <path d="M26 21L36 11M46 21L56 9M66 20L74 10M26 22L36 31M46 22L56 33M66 21L74 30" fill="none" stroke="#4a7a3a" stroke-width="1.4" stroke-linecap="round" opacity=".8"/>
  <path d="{shape}" fill="none" stroke="{INK}" stroke-width="2.5" stroke-linejoin="round"/>'''
    write('leaf.svg', W, H, body)


mountains_far()
mountains_mid()
hill()
pine()
round_tree()
grass_strip('grass-far.svg', 1500, 70, 18, 48, 51, 30)
grass_strip('grass-near.svg', 1500, 50, 14, 34, 52, 22)
stones()
ripples()
reeds()
cloud()
leaf()
print('ok')
