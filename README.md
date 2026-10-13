# 🏍️ autorace-news-collector

[![test](https://github.com/shimomo/autorace-news-collector/actions/workflows/test.yml/badge.svg)](https://github.com/shimomo/autorace-news-collector/actions/workflows/test.yml)
[![psalm](https://github.com/shimomo/autorace-news-collector/actions/workflows/psalm.yml/badge.svg)](https://github.com/shimomo/autorace-news-collector/actions/workflows/psalm.yml)
[![lint](https://github.com/shimomo/autorace-news-collector/actions/workflows/lint.yml/badge.svg)](https://github.com/shimomo/autorace-news-collector/actions/workflows/lint.yml)

オートレースの最新記事を、公式サイトの RSS、日刊スポーツの Atom、各レース場公式サイトの WordPress REST API、公式 YouTube チャンネルのフィードから集めて `Article` の配列として返す PHP ライブラリです。

保存・既読管理・重複排除は呼び出し側の責務です。HTML の一覧ページはスクレイピングしません。

---

## 📦 インストール

PHP 8.4 以上（`ext-dom`、`ext-json`、`ext-libxml`）。

```bash
composer require shimomo/autorace-news-collector
```

---

## 🚀 使い方

```php
use Shimomo\AutoraceNewsCollector\Collector;

$collector = Collector::default();
$result    = $collector->collect(since: new DateTimeImmutable('-1 day'));

foreach ($result->articles as $article) {
    // $article->source, $article->id, $article->title, $article->bodyText, ...
}

foreach ($result->failures as $failure) {
    // $failure->source, $failure->url, $failure->message, $failure->previous
}
```

ソースを選ぶ場合は `Collector` に直接渡します。

```php
use Shimomo\AutoraceNewsCollector\Collector;
use Shimomo\AutoraceNewsCollector\Http\HttpFetcher;
use Shimomo\AutoraceNewsCollector\Http\NativeSleeper;
use Shimomo\AutoraceNewsCollector\Sources\AutoraceJpSource;
use Shimomo\AutoraceNewsCollector\Sources\WordPressSite;
use Shimomo\AutoraceNewsCollector\Sources\WordPressSource;
use Symfony\Component\HttpClient\HttpClient;

$fetcher   = new HttpFetcher(HttpClient::create(), new NativeSleeper(), interval: 2.0);
$collector = new Collector([
    new AutoraceJpSource($fetcher),
    new WordPressSource(WordPressSite::kawaguchiNews(), $fetcher),
]);
```

`HttpFetcher` は User-Agent、タイムアウト（既定 15 秒）、リクエスト間隔（既定 1 秒）を持ちます。再試行が要るときは symfony の `RetryableHttpClient` で包んだクライアントを渡してください。

---

## 📰 収集元

| `source` | 表示名 | 取得経路 | 本文 | 1回の件数 |
|---|---|---|---|---|
| `autorace.jp` | AUTORACE.JP | RSS | フィードに本文 HTML | 15 |
| `nikkansports` | 日刊スポーツ | Atom ＋ 記事ページ | 記事ページから抽出 | 10 |
| `kawaguchi` | 川口オートレース | WordPress REST（お知らせ・選手インタビュー） | API に本文 | 最大 100 |
| `isesaki` | 伊勢崎オートレース | WordPress REST | API に本文（空の投稿が多い） | 最大 100 |
| `hamamatsu` | 浜松オート | WordPress REST | API に本文 | 最大 100 |
| `sanyo` | 山陽オート | WordPress REST | API に本文 | 最大 100 |
| `youtube` | オートレース公式 YouTube | Atom | 動画説明文 | 15 |

飯塚オート公式は RSS も API も無いため対象外です。

---

## 📄 `Article`

| プロパティ | 型 | 内容 |
|---|---|---|
| `source` | `string` | ソースキー |
| `sourceName` | `string` | 表示名 |
| `id` | `string` | ソース内で安定した ID。`(source, id)` で重複判定できる |
| `url` | `string` | 記事 URL |
| `title` | `string` | |
| `categories` | `list<string>` | ソース固有の分類 |
| `publishedAt` | `DateTimeImmutable` | Asia/Tokyo |
| `summary` | `?string` | フィードの要約 |
| `bodyHtml` | `?string` | 整形済み本文 HTML（script 等を除去、URL を絶対化） |
| `bodyText` | `?string` | 本文のプレーンテキスト |
| `thumbnailUrl` | `?string` | アイキャッチまたは本文先頭画像 |

---

## 🔁 `since` と重複

- `collect(since)` は `publishedAt >= since` の記事を返します。省略時は各ソースが1回で返せる範囲をそのまま返します。
- 日刊スポーツの記事は AUTORACE.JP にも「【日刊提供】」として載るため、同じ内容が2ソースから返ります。URL も ID も異なるのでライブラリでは束ねません。

---

## ⚠️ 失敗の扱い

`collect()` は例外を投げません。ソース全体の失敗（HTTP エラー、フィードの解析失敗）も、1件単位の失敗（必須項目の欠落、日刊の記事ページ取得失敗）も `failures` に `SourceFailure` として積まれ、他の記事は返ります。日刊の記事ページ取得に失敗した記事は要約だけを持つ `Article` になります。

---

## ⏱️ 実行間隔の目安

日刊スポーツの Atom は最新 10 件しか無く、SG 開催日は 1 日に 10 本を超えます。1〜2 時間おきに実行してください。各サイトへのアクセスは 1 秒間隔です。

---

## 📝 注意

AUTORACE.JP のサイトポリシーは無断転載・複製・公開を禁じています。本ライブラリは本人の私的な保存に限って使うものです。

---

## 🛠️ 開発

```bash
composer test       # phpunit
composer analyse    # psalm
composer lint       # pint --test
composer fix        # pint
composer normalize  # composer.json の整形
```

テストはネットワークへ出ません。`tests/fixtures/` は 2026-10-13 に採取した実レスポンスです。
