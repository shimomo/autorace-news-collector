# autorace-news-collector 設計書

作成日: 2026-10-13

## 1. 目的と前提

オートレースの最新記事を、RSS / Atom / WordPress REST API を持つソースから集めて `Article` の配列として返す PHP ライブラリ。

- 利用者は本人のみ。集めた記事を別アプリ（Laravel 等）から呼び出して DB に保存する。記事を公開・再配信しない。
- 保存、既読管理、重複排除は呼び出し側の責務。ライブラリは取得と正規化だけを行う。
- HTML の一覧ページをスクレイピングするソースは扱わない（管理が煩雑なため）。フィードや API が無いソースは対象外。
- 本文はフィードに含まれていればそれを使い、無いソース（日刊スポーツ）だけ記事ページを取得して固定セレクタで抽出する。

候補ソースの調査結果は [docs/sources.md](../../sources.md) にある。

## 2. 対象ソース

| key | 表示名 | 取得経路 | 本文 | 件数/回 |
|---|---|---|---|---|
| `autorace.jp` | AUTORACE.JP | `https://autorace.jp/rss.xml`（RSS 2.0） | description に本文 HTML | 15 |
| `nikkansports` | 日刊スポーツ | `https://www.nikkansports.com/public_race/auto/atom.xml`（Atom） | 記事ページから抽出 | 10 |
| `kawaguchi` | 川口オートレース | `https://www.kawaguchiauto.jp/wp-json/wp/v2/news` と `/interview` | content.rendered | 最大100 |
| `isesaki` | 伊勢崎オートレース | `https://www.isesaki-auto.jp/wp-json/wp/v2/posts` | content.rendered（空が多い） | 最大100 |
| `hamamatsu` | 浜松オート | `https://www.hamamatsuauto.jp/wp-json/wp/v2/newsinfo` | content.rendered | 最大100 |
| `sanyo` | 山陽オート | `https://sanyoauto.jp/wp-json/wp/v2/posts` | content.rendered | 最大100 |
| `youtube` | オートレース公式 YouTube | `https://www.youtube.com/feeds/videos.xml?channel_id=UCJAPqcMNYk3HMknRuPMNvKA`（Atom） | 動画説明文 | 15 |

対象外: 飯塚オート公式（RSS も REST も無い）、オッズパーク、PR TIMES、Google ニュース、スポーツ紙各社の HTML 一覧。

## 3. 公開 API

```php
use Shimomo\AutoraceNewsCollector\Collector;

$collector = Collector::default();
$result    = $collector->collect(since: new DateTimeImmutable('-1 day'));

$result->articles;  // list<Article> 全ソース混在、publishedAt の新しい順
$result->failures;  // list<SourceFailure>
```

- `Collector::default(?HttpFetcher $fetcher = null): self` は8つの `Source`（ソースキーは7種。川口は news と interview で2つ）を既定の `HttpFetcher` で組み立てる。
- `new Collector(list<Source> $sources)` で任意の組み合わせを渡せる。
- `collect(?DateTimeImmutable $since = null): CollectResult`。`since` を渡すと `publishedAt >= since` の記事だけ返す。省略時は各ソースが1回で返せる範囲をそのまま返す。
- `collect()` は例外を投げない。失敗はすべて `failures` に載る。

### 3.1 `Article`（readonly）

| プロパティ | 型 | 内容 |
|---|---|---|
| `source` | `non-empty-string` | ソースキー（上表の key） |
| `sourceName` | `non-empty-string` | 表示名 |
| `id` | `non-empty-string` | ソース内で安定した ID。autorace.jp は URL 末尾の番号（`038810`）、日刊は記事番号（`202610120001800`）、WordPress は投稿 ID、YouTube は videoId |
| `url` | `non-empty-string` | 記事 URL |
| `title` | `non-empty-string` | |
| `categories` | `list<string>` | ソース固有の分類。autorace.jp は `<category>` 全部（例: ニュース / レース情報 / 飯塚）、WordPress は分類名、日刊と YouTube は空 |
| `publishedAt` | `DateTimeImmutable` | Asia/Tokyo に正規化 |
| `summary` | `?string` | フィードの要約テキスト。無ければ null |
| `bodyHtml` | `?string` | 整形済み本文 HTML。無ければ null |
| `bodyText` | `?string` | 本文のプレーンテキスト。YouTube は説明文がここに入る |
| `thumbnailUrl` | `?string` | アイキャッチまたは本文先頭画像、YouTube はサムネイル |

重複判定は `(source, id)` で呼び出し側が行う。日刊スポーツの記事は autorace.jp にも「【日刊提供】」として載るが、URL も ID も異なるためライブラリでは束ねず両方返す。

### 3.2 `CollectResult` / `FetchResult` / `SourceFailure`

- `FetchResult`: `articles: list<Article>`、`failures: list<SourceFailure>`。`Source::fetch()` の戻り値。
- `CollectResult`: 同じ2プロパティ。`Collector` が全ソースの `FetchResult` を束ね、`articles` を `publishedAt` 降順に並べたもの。
- `SourceFailure`: `source`、`url: ?string`、`message`、`previous: ?Throwable`。

### 3.3 `Source` 契約

```php
interface Source
{
    public function key(): string;      // non-empty-string
    public function name(): string;     // non-empty-string
    public function fetch(?DateTimeImmutable $since): FetchResult;
}
```

## 4. 構成

```
src/
  Collector.php  CollectResult.php  FetchResult.php  Article.php  SourceFailure.php
  Contracts/Source.php  Contracts/Sleeper.php
  Http/HttpFetcher.php  Http/HttpResponse.php  Http/HttpException.php  Http/NativeSleeper.php
  Html/HtmlDocument.php  Html/BodyNormalizer.php  Html/NormalizedBody.php
  Feed/XmlDocument.php  Feed/FeedException.php
  Sources/AutoraceJpSource.php  Sources/NikkanSportsSource.php
  Sources/WordPressSource.php   Sources/WordPressSite.php  Sources/YouTubeSource.php
tests/            # src と同じ構成。fixtures/ と Support/ を持つ
docs/sources.md
docs/superpowers/specs/
```

名前空間は `Shimomo\AutoraceNewsCollector\`。PHP-DI やファサードは使わず、コンストラクタに依存を渡すだけの構成にする。

## 5. 各ソースの仕様

### 5.1 AutoraceJpSource

- `https://autorace.jp/rss.xml` を1回取得。記事ページは取りに行かない。
- `id`: `link` の末尾ディレクトリ名（`https://autorace.jp/news/2026/10/12/038810/` → `038810`）。
- `categories`: `<category>` 要素のテキストを出現順にすべて。
- `publishedAt`: `pubDate`（`+0900` 付き RFC 2822）。
- `bodyHtml` / `bodyText` / `thumbnailUrl`: `description` を `BodyNormalizer` に通した結果。画像はルート相対（`/g2/.../img-05.jpg`）なので記事 URL を基準に絶対化する。
- `summary`: null。

### 5.2 NikkanSportsSource

- `https://www.nikkansports.com/public_race/auto/atom.xml` を取得。各 `entry` から `id`（`link[@rel=alternate]/@href` のファイル名から拡張子を除いたもの）、`title`、`published`、`summary`、`link[@rel=enclosure]/@href`（写真）を読む。`content` も要約と同じ切り詰めテキストなので使わない。
- `since` で絞った後、残った記事だけ記事ページを取得する。
- 記事ページの抽出（`Html\HtmlDocument`、DOMDocument ＋ XPath）:
  - 本文: `section#articleMain` 内の `p.prologue` と `div#news.article-body` を順に連結した HTML を `BodyNormalizer` に通す。
  - サムネイル: Atom の `link[@rel='enclosure']/@href`（記事写真）。無ければ本文先頭画像。記事ページの写真は `img@src` が空画像で実体は `style` の背景画像なので使わない。
- 記事ページの取得または本文要素の抽出に失敗した場合、その記事は `summary` のみを持つ `Article` として返し、`failures` に記録する。
- Atom は10件しか無い。SG 開催日のように1日10本を超える日は取りこぼし得るため、1〜2時間おきの実行を README に書く。

### 5.3 WordPressSource

川口（news / interview）、伊勢崎、浜松、山陽を1クラスで扱う。設定は `WordPressSite`（readonly）に持たせる。

| プリセット | key | baseUrl | 投稿タイプ | 固定ラベル |
|---|---|---|---|---|
| `WordPressSite::kawaguchiNews()` | `kawaguchi` | `https://www.kawaguchiauto.jp` | `news` | なし |
| `WordPressSite::kawaguchiInterview()` | `kawaguchi` | `https://www.kawaguchiauto.jp` | `interview` | `選手インタビュー` |
| `WordPressSite::isesaki()` | `isesaki` | `https://www.isesaki-auto.jp` | `posts` | なし |
| `WordPressSite::hamamatsu()` | `hamamatsu` | `https://www.hamamatsuauto.jp` | `newsinfo` | なし |
| `WordPressSite::sanyo()` | `sanyo` | `https://sanyoauto.jp` | `posts` | なし |

- リクエスト: `{baseUrl}/wp-json/wp/v2/{type}` に次のクエリ。
  - `per_page=100`、`page={n}`、`orderby=date`、`order=desc`
  - `_embed=wp:featuredmedia,wp:term`
  - `_fields=id,date_gmt,link,title,content,excerpt,_links,_embedded`
  - `since` があれば `after={since を UTC にして ISO 8601、1秒引いたもの}`。WP の `after` は「より後」なので、`>=` に揃えるため1秒引く。
- ページ送り: `since` がある場合のみ、`X-WP-TotalPages` ヘッダを見て2ページ目以降を取る。上限は `WordPressSource` のコンストラクタ引数 `maxPages`（既定3）。`since` が無ければ1ページだけ。
- マッピング:
  - `id`: `id` を文字列化。
  - `url`: `link`。
  - `title`: `title.rendered` の実体参照を戻してタグを除いたもの。
  - `publishedAt`: `date_gmt` を UTC として読み、Asia/Tokyo に変換。
  - `categories`: `_embedded['wp:term']` の全グループの `name` を出現順に。固定ラベルがあれば先頭に足す。
  - `bodyHtml` / `bodyText`: `content.rendered` を `BodyNormalizer` に通す。空なら null（伊勢崎は本文が空の投稿が多い）。
  - `summary`: `excerpt.rendered` をテキスト化。空または無ければ null（浜松 `newsinfo` は空）。
  - `thumbnailUrl`: `_embedded['wp:featuredmedia'][0].source_url`。無ければ本文先頭画像。
- `after` で絞っても `publishedAt >= since` の判定はライブラリ側でも行う。WP が `after` の時差をどう解釈しても、UTC の値を渡せば結果は「必要な分を含む集合」になるので、取りこぼしは起きず余分だけを落とせばよい。
- 伊勢崎の `xo_event` 投稿タイプは対象外。浜松の区分（news / race / event）は REST に露出していないため `categories` は空になる。

### 5.4 YouTubeSource

- チャンネルフィードを取得。名前空間は Atom、`yt`、`media` の3つ。
- `id`: `yt:videoId`。`url`: `https://www.youtube.com/watch?v={videoId}`。`title`、`publishedAt`: `published`。
- `bodyText`: `media:group/media:description`（空なら null）。`bodyHtml`: null。
- `thumbnailUrl`: `media:group/media:thumbnail/@url`。`categories`: 空。`summary`: null。

## 6. HTTP 層

`Http\HttpFetcher`（final）が symfony/http-client の `HttpClientInterface` を包む。

- `get(string $url): HttpResponse`。`HttpResponse` は `body: string` と `headers: array<string, list<string>>` を持つ readonly。
- `getJson(string $url): array` は `get($url)->json()` の短縮。`HttpResponse` は `header(name)`（大文字小文字を無視して先頭値）と `json()`（連想配列に復号、失敗は `HttpException`）を持つ。
- 2xx 以外とトランスポート失敗は `Http\HttpException`（`RuntimeException` の子）に包んで投げる。symfony の例外はそのまま伝播させない。ソース側で `failures` に変換する。
- ヘッダ: `User-Agent: autorace-news-collector (+https://github.com/shimomo/autorace-news-collector)`、`Accept-Language: ja`。UA は差し替え可能。バージョン番号は入れない（取得経路が環境で変わり、値が無くても困らないため）。
- タイムアウト: 既定15秒。
- 間隔: 2回目以降のリクエスト前に既定1秒待つ。待機は `Contracts\Sleeper`（`sleep(float $seconds): void`）経由。本番は `Http\NativeSleeper`（`usleep`）、テストは `tests/Support/SpySleeper`（待たずに秒数を記録）。
- 再試行は持たない。必要なら呼び出し側が `RetryableHttpClient` で包んだクライアントを渡す。
- `Collector::default()` は `HttpClient::create()` と既定設定で `HttpFetcher` を組む。

## 7. 本文整形（`Html\BodyNormalizer`）

- 入力: HTML 断片と基準 URL。出力: `bodyHtml`、`bodyText`、`thumbnailUrl` の3つ（`NormalizedBody` readonly）。
- `script`、`style`、`noscript`、`iframe`、HTML コメントを除去。それ以外の要素と属性は残す（保存用の掃除であり、公開向けのサニタイズではない）。
- `img@src`、`a@href` を基準 URL で絶対化（ルート相対・相対の両方）。
- `bodyText`: 整形後の HTML からブロック要素（`p`、`div`、`br`、`li`、`h1`〜`h6`、`tr`）の境目を改行にしてタグを除去し、実体参照を戻し、連続する空白を1つに、3つ以上の連続改行を2つに畳む。前後の空白は除く。
- `bodyText` が空なら `bodyHtml` と `bodyText` は両方 null。
- `thumbnailUrl`: 整形後の先頭 `img@src`。無ければ null。
- DOMDocument の読み込みは `<?xml encoding="UTF-8"?>` を前置して UTF-8 を明示する（libxml の HTML パーサは既定が ISO-8859-1 のため）。

## 8. フィード解析（`Feed\XmlDocument`）

- `DOMDocument` ＋ `DOMXPath` の薄い包み。名前空間（Atom、`yt`、`media`、`content`）を登録し、`items(xpath)`、`text(node, xpath)`、`attr(node, xpath, name)` を提供する。
- XML の解析失敗は例外にし、ソース側でソース単位の失敗にする。
- 日時変換は各ソースで `DateTimeImmutable::createFromFormat` を使い、失敗した項目はその1件だけ飛ばして `failures` に載せる。

## 9. エラー処理

- ソース単位で隔離する。`Collector::collect()` は各ソースの `fetch()` を `try` で包み、例外は `SourceFailure` に変換して続行する。
- フィード内の1件が壊れている（必須項目の欠落、日時の解析失敗）場合はその1件だけ飛ばし、`failures` に記録する。
- 日刊の記事ページ取得は記事単位で隔離する（5.2）。
- `Article` は必須項目（`source`、`sourceName`、`id`、`url`、`title`、`publishedAt`）が揃わない限り作らない。

## 10. テスト

- ネットワークへは出ない。調査時に取得した実レスポンスを `tests/fixtures/` に置き、`MockHttpClient` で返す。
  - `autorace-jp/rss.xml`
  - `nikkansports/atom.xml`、`nikkansports/article-202610120001800.html`
  - `wordpress/kawaguchi-news.json`、`kawaguchi-interview.json`、`isesaki-posts.json`、`hamamatsu-newsinfo.json`、`sanyo-posts.json`
  - `youtube/videos.xml`
- テストクラスは `src` と同じ構成で `XxxTest`。`#[Test]` 属性と英語の lowerCamelCase メソッド名。データは必要に応じて `XxxDataProvider` に置く。
- 主な検証:
  - 各ソース: 件数、各プロパティのマッピング、`since` での絞り込み、壊れた1件が飛ばされて `failures` に載ること。
  - 日刊: `since` で落ちた記事のページを取りに行かないこと（`MockHttpClient` の呼び出し回数）、本文と前書きの連結、ページ取得失敗時の要約のみ返却。
  - WordPress: クエリ文字列（`per_page`、`_embed`、`_fields`、`after`）、`X-WP-TotalPages` に従うページ送りと `maxPages` の上限、`date_gmt` → JST、`_embedded` からの分類名とサムネイル、空本文の null 化、プリセット5つの設定値。
  - `BodyNormalizer`: 除去対象、絶対 URL 化、テキスト化の改行規則、空判定、先頭画像。
  - `HttpFetcher`: UA ヘッダ、2回目以降の待機（`Sleeper` のスパイ）、非 2xx の例外。
  - `Collector`: 混在ソート、ソース単位の失敗隔離、`default()` が8つの `Source` を組むこと。
- 通ることだけでなく、規約を故意に壊して落ちることも確認する。

## 11. ツール・リポジトリ

- `composer.json`: `shimomo/autorace-news-collector`、`type: library`、MIT、PHP `^8.4`。
  - require: `symfony/http-client ^8.0` のみ（HTML も XML も PHP 標準の DOMDocument で読むため dom-crawler は使わない）
  - require-dev: `phpunit/phpunit ^13.2`、`laravel/pint ^1.27`、`vimeo/psalm ^6.0`、`ergebnis/composer-normalize ^2.52`
  - scripts: `test`（phpunit）、`analyse`（psalm）、`lint`（pint --test）、`fix`（pint）。整形は plugin の `composer normalize` を直接使う
  - インデントは4スペース。
- `pint.json` は boatrace の複製（preset `empty`、docblock 系 fixer なし）。
- `psalm.xml.dist`: errorLevel 1、`findUnusedCode="false"`、対象は `src` と `tests`。
- `phpunit.xml.dist`: testsuite は `tests/`、source は `src/`。
- `.editorconfig`（boatrace と同じ。`tests/fixtures/**` は verbatim）、`.gitattributes`（`/tests` と自身を export-ignore）、`.gitignore`（boatrace と同じ。`CLAUDE.md` はコミットしない）。
- GitHub Actions: `test.yml`（PHP 8.4 / 8.5 × prefer-lowest / prefer-stable、PHPUnit 13.2）、`psalm.yml`、`lint.yml`、`dependabot.yml`。
- `README.md`（日本語）: インストール、使い方、ソース一覧、実行間隔の目安、各ソースの癖。
- `CLAUDE.md`（ローカル、未コミット）: コマンド、規約、ソースごとの癖。
- `CHANGELOG.md`、`LICENSE`（MIT）。

## 12. コーディング規約

boatrace と同じ。

- `declare(strict_types=1);`、クラスは `final`（継承される側だけ `abstract`）、クラス docblock に `@author shimomo`。
- すべてのメソッドに `@param` / `@return`。型は psalm の記法（`non-empty-string`、`list<...>`）。
- インターフェース実装には `#[\Override]`。契約の import は `use ... as XxxContract`。
- コメントは日本語で「なぜ」を書く。第三者向けの解説は足さない。
- コミットは Conventional Commits 形式の英語1行。GPG 署名のため実行は本人が行う。

## 13. 運用上の注意

- autorace.jp のサイトポリシーは無断転載・複製・公開を禁じている。本ライブラリは本人の私的な保存に限って使う。
- 各サイトへのアクセスは1秒間隔を守り、実行頻度は1〜2時間に1回程度に留める。
- 日刊スポーツは robots.txt で AI クローラを拒否しているが、本ライブラリは独自 UA で、フィードに載った記事ページだけを取得する。

## 14. 将来の拡張（今回は実装しない）

- 飯塚オート公式（HTML 一覧のスクレイピングが必要）。
- Tier B の各スポーツ紙、オッズパーク、PR TIMES。
- 日刊提供記事の autorace.jp 側との突き合わせ。
