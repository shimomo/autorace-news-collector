# オートレース記事 収集元候補（調査日: 2026-10-13）

各サイトに実際にHTTPアクセスし、RSS/Atom/APIの有無・一覧ページの構造・robots.txt を確認した結果。
Google ニュース RSS を7クエリ（オートレース / SG / 各場名）で集計した「どの媒体がオートレース記事を出しているか」の出現数も併記する。

## Google ニュース出現数（上位）

| 媒体 | 件数 | 備考 |
|---|---|---|
| 日刊スポーツ | 91 | 選手コメント・結果記事が最多。autorace.jp にも「【日刊提供】」として転載 |
| 東スポオートレース | 67 | 専門サブサイト。出走表・選手コメント |
| WINTICKET | 67 | 開催中止・キャンペーンのお知らせ中心 |
| Yahoo!ニュース | 66 | 各紙の転載（一次ソースではない） |
| スポニチ | 57 | |
| netkeirin | 53 | SG/G1 レポート、入門コラム |
| 川口オートレース（公式） | 36 | 選手インタビュー |
| 東スポWEB | 36 | |
| サンスポ | 26 | レーサートーク・選手コメント |
| スポーツ報知 | 19 | |
| PR TIMES / 中日新聞 / 静岡新聞 ほか | 10以下 | |

---

## Tier A: RSS / Atom / API がある（第一候補）

| # | 媒体 | フィード URL | 件数 | 備考 |
|---|---|---|---|---|
| A1 | AUTORACE.JP（公式） | `https://autorace.jp/rss.xml` | 15 | RSS 2.0。description に本文HTML込み。ニュース・整備情報・日刊提供記事 |
| A2 | 日刊スポーツ オート | `https://www.nikkansports.com/public_race/auto/atom.xml` | 10 | Atom。最多ソース |
| A3 | YouTube オートレース公式 | `https://www.youtube.com/feeds/videos.xml?channel_id=UCJAPqcMNYk3HMknRuPMNvKA` | 15 | 中継・ダイジェスト・速報 |
| A4 | 川口オート（公式） | `https://www.kawaguchiauto.jp/news/feed/` / `https://www.kawaguchiauto.jp/interview/feed/` | 各10 | WordPress。`/feed/` 本体は空。REST: `/wp-json/wp/v2/news`, `/wp-json/wp/v2/interview` |
| A5 | 伊勢崎オート（公式） | `https://www.isesaki-auto.jp/feed/` | 10 | WordPress |
| A6 | 山陽オート（公式） | `https://sanyoauto.jp/feed/` | 10 | WordPress |
| A7 | 浜松オート（公式） | `https://www.hamamatsuauto.jp/wp-json/wp/v2/newsinfo` | 任意 | `/feed/` は空。REST API で取得。HTML: `/newsinfo/?newstype=news\|race\|event` |
| A8 | オッズパーク AKIブログ | `https://blog.oddspark.com/aki_blog/atom.xml` | 15 | オート中心だが競馬記事も混在 |
| A9 | PR TIMES（オッズ・パーク社） | `https://prtimes.jp/companyrdf.php?company_id=172252` | 52 | JKA共催イベント等のリリース。JKA本体は PR TIMES 未登録 |
| A10 | Google ニュース RSS | `https://news.google.com/rss/search?q=オートレース&hl=ja&gl=JP&ceid=JP:ja` | 100/クエリ | 発見・網羅性チェック用。リンクは Google リダイレクトURL（デコード要）。利用規約注意 |

## Tier B: HTML スクレイピングが必要

| # | 媒体 | 一覧 URL | 構造メモ | robots.txt |
|---|---|---|---|---|
| B1 | 東スポオートレース | `https://autorace.tokyo-sports.co.jp/list/news` | 記事 `/articles/-/{id}`。sitemap 月別 `common/files/sitemap-YYYY-M.xml`。og:title あり | 全許可 |
| B2 | 東スポWEB | `https://www.tokyo-sports.co.jp/list/public_race/autorace` | 全体RSS `/list/feed/rss`（20件、category "公営競技" で絞込可だがボート・競輪混在） | `?page=` は 5 まで。AI系ボット拒否 |
| B3 | スポニチ | `https://www.sponichi.co.jp/gamble/tokusyu/auto/` | 2026年記事リンク112件。`/gamble/news/YYYY/MM/DD/kiji/...html` | Crawl-delay 5。AI系拒否 |
| B4 | サンスポ | `https://www.sanspo.com/race/auto/` | Arc XP。`/race/article/general/YYYYMMDD-{id}/`。2026年23件 | AI系拒否 |
| B5 | デイリースポーツ | `https://www.daily.co.jp/horse/autorace/` | `/horse/YYYY/MM/DD/{id}.shtml`。JSON-LD CollectionPage | 緩い |
| B6 | スポーツ報知 | `https://hochi.news/search?q=オートレース` | 専用カテゴリなし。検索結果 56件。`/articles/YYYYMMDD-OHT1T5xxxx.html` | AI系拒否 |
| B7 | 中日スポーツ | `https://www.chunichi.co.jp/chuspo/race/autorace` | `/article/{id}`。浜松オート中心。23件 | `/search` 禁止。AI系拒否 |
| B8 | netkeirin | `https://keirin.netkeiba.com/perfectanavi/news_list.html` | 競輪と混在。`category=` パラメータは効かない。タイトルで「オート」フィルタ要 | 制限なし |
| B9 | WINTICKET | `https://www.winticket.jp/autorace/topics/` | `?page=N`。開催中止・キャンペーン告知中心 | 緩い |
| B10 | JKA | `https://www.keirin-autorace.or.jp/NEWS/` | 静的HTML `/NEWS/YYYYMMDD.html`。表彰・補助事業。低頻度 | なし(404) |
| B11 | 飯塚オート（公式） | `https://www.iizuka-auto.jp/news/` | RSSなし。`/information/{id}.html`、`/column/` | — |
| B12 | オッズパーク | `/autorace/info/`, `/autorace/pickup/`, `/autorace/blog/column/` | Movable Type 年月ディレクトリ。RSSなし | Crawl-delay 10。AI系拒否 |
| B13 | 日刊ゲンダイ オートレースWEB | `https://keirin-autorace.nikkan-gendai.com/Autorace/NewsList` | 一覧の記事リンクが取得できず、構造要調査 | なし |
| B14 | Gamboo | `https://gamboo.jp/column/latest/` | 競輪混在のブログ一覧。優先度低 | — |

## Tier C: 非推奨・補助

- Yahoo!ニュース検索: 利用規約でスクレイピング禁止、robots.txt も AI 系を全拒否。各紙の転載なので一次ソース側を取ればよい。
- X `@auto_official`: API 有料。TikTok `@autorace_official`。
- 地方紙（静岡新聞アットエス、山口宇部経済新聞、川口経済新聞、埼玉新聞、上毛新聞、西日本新聞）: 専用カテゴリなし。Google ニュース経由で拾う程度。
- 気象（ウェザーニュース各場天気）、ABEMA 中継ページ: 記事ではない。

## 留意点

- autorace.jp のサイトポリシーは「事前の許可なく転載、複製、出版、頒布、送信、公開」を禁止。見出し・リンク・短い要約に留めるか、用途（私的 / 公開）に応じて扱いを決める。
- スポーツ紙の多くは robots.txt で AI クローラを拒否している。独自 UA で収集する場合も、Crawl-delay（スポニチ 5 秒、オッズパーク 10 秒）を守り、アクセス頻度を抑える。
- Google ニュース RSS のリンクは `news.google.com/rss/articles/...` 形式で、元 URL への復元にはデコード処理が要る。
- 日刊スポーツの記事は autorace.jp にも「【日刊提供】」として載るため、重複排除が必要。
