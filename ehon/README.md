# とびだす昔話（プロトタイプ）

ページをめくると切り絵の絵が起きあがる、日本の昔話のインタラクティブ絵本です。
WordPress とは別の静的ページとして `/ehon/` に置いています（ビルド不要・外部 JS ライブラリなし）。

- `index.html` … お話一覧（本棚）
- `story.html?id=momotaro&lang=ja` … お話ビューア（`#p5` で5ページ目から）

## 見かた（ローカル）

JSON を `fetch` で読むため、ファイルを直接開くのではなく簡易サーバーで確認します。

```sh
cd ehon
python3 -m http.server 8000   # または php -S localhost:8000
# http://localhost:8000/ を開く
```

本番では、ルートの WordPress 用 `.htaccess` が「実在するファイル・フォルダ」はそのまま配信するので、`ehon/` をアップロードすれば `/ehon/` で表示されます（`ehon/.htaccess` で `index.html` を既定にしています）。

## 操作と演出

- 物語は日本語・英語とも **右方向へ進む**（「つぎへ」ボタン、左スワイプ、→キー）
- ページが変わると、各レイヤーが下の辺を折り目にして起きあがる（飛び出す絵本）。奥のレイヤーほど横移動が小さく、視差で奥行きが出る
- PC ではマウスの位置でレイヤーが少しずれる
- キャラクターをタップ（Enter）すると、はねてセリフが出る
- 日本語は **縦書き／横書き** と **ふりがな** を切り替えられる（英語は横書き）
- 動きを減らす設定（`prefers-reduced-motion`）では、フェードのみになる

## フォルダ構成

```
ehon/
  index.html, story.html
  assets/css/ehon.css
  assets/js/common.js     言語・ルビ変換・レイヤー生成・出典チェック
  assets/js/viewer.js     ビューア（めくり、タップ、スワイプ）
  assets/js/library.js    一覧
  assets/art/*.svg        共通の切り絵素材（人物・動物・背景）
  stories/index.json      一覧に並べるお話
  stories/<id>/story.json シーン構成（絵の配置と動き）と出典
  stories/<id>/text.ja.json, text.en.json  文章（言語ごと）
```

絵と文章を分けているので、**言語を増やすときは `text.<lang>.json` を足し、`common.js` の `LANGS` と `UI` に追加するだけ**です。絵の中に文字は描きません。

## お話を追加する

1. `stories/<id>/` を作り、`story.json` と `text.ja.json`・`text.en.json` を置く
2. `stories/index.json` に追加（`"status": "ready"` で公開、`"soon"` で準備中）

### story.json のレイヤー

```json
{ "src": "momotaro.svg", "x": 49, "y": 5, "w": 16, "depth": 0.75, "anim": "bob", "tap": "momotaro" }
```

| キー | 意味 |
|---|---|
| `src` | 絵。共通素材は `assets/art/` からの名前、お話専用は `"./img/xxx.png"`（お話フォルダから） |
| `x` `y` `w` | 舞台（16:9）に対する %。`x` は中心、`y` は下端、`w` は幅 |
| `depth` | 0（遠い）〜1（近い）。めくり時の横移動とマウス視差の大きさ |
| `anim` | 待機中の動き：`bob` `hop` `sway` `float` `drift` `hover` `tremble` `rock` `wave` `peach` `split-l` `split-r` |
| `enter` | `"fade"` で起きあがらずにフェード（雲・太陽など） |
| `flip` | `true` で左右反転 |
| `tap` | タップできる絵にする。`text.*.json` の `say`（セリフ）と `names`（読み上げ名）のキー |
| `credit` | 出典のある素材は `credits` の `id` を指定（下記） |
| `type: "title"` | 表紙のタイトル札（文字は `text.*.json` の `title` / `subtitle`） |

### 文章（ルビ）

青空文庫と同じ記法でふりがなを付けます。

```
桃太郎《ももたろう》は、鬼ヶ島《おにがしま》へ …
｜日本一《にっぽんいち》 … 範囲を明示したいとき
```

改行（`\n`）は、縦書きでは次の行（左の列）になります。

## 素材・出典の方針

各お話の最後のページに「素材・出典」を自動で表示します。出典は `story.json` の `credits` に書きます。

### 国立国会図書館デジタルコレクションの画像を使う場合

- 使えるのは、資料ごとに「**インターネット公開（保護期間満了）**」と表示されているものだけ。この場合は図書館への利用申請は不要です。
- 「長谷川武次郎のちりめん本シリーズだから全部使える」とはせず、**採用する巻・言語版・画像ごとに**表示を確認し、確認日を記録します。
- 転載元の明示は図書館から協力を求められているもので、画像の近くが望ましいものの巻末やエンドロールでもよい、と案内されています。このサイトでは各お話の最後にまとめて表示します。
  - [国立国会図書館：資料の転載](https://www.ndl.go.jp/use/reproduction)
  - [国立国会図書館：よくある質問（利用）](https://www.ndl.go.jp/help/utilizing)

`credits` への書き方：

```json
{
  "id": "ndl-momotaro-1",
  "kind": "ndl",
  "title": "［書名］",
  "titleEn": "［英語版で表示する書名（任意）］",
  "year": "［出版年］",
  "url": "https://dl.ndl.go.jp/pid/［資料ID］",
  "modified": true,
  "rights": {
    "status": "インターネット公開（保護期間満了）",
    "checkedOn": "YYYY-MM-DD",
    "note": "巻・言語版・コマ番号など、確認した範囲"
  }
}
```

絵のレイヤーには `"credit": "ndl-momotaro-1"` を付けます。最後のページには次のように表示されます。

> 画像出典：『［書名］』（［出版年］）、国立国会図書館デジタルコレクション（［資料URL］）。
> 掲載画像を切り抜き・加工し、アニメーションを付加しています。

**安全装置：** `rights.status` が上の文言と一致しない・`checkedOn` がない・URL が `https://dl.ndl.go.jp/` でない出典は、その出典を使う絵ごと表示されません（ブラウザのコンソールに警告が出ます）。確認が済むまで素材が公開されないようにするためです。

### いまの「ももたろう」

文章は伝承をもとにした書きおろし、絵はすべてこのサイトのオリジナル（SVG）です。国立国会図書館の画像はまだ使っていません。
