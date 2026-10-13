# Changelog

## Unreleased

## 0.2.0 - 2026-10-13

### Added

- `Collector::only()` / `Collector::without()` でソースキーによる絞り込み。未知のキーと空の `only()` は `InvalidArgumentException`
- `Collector::default()` の `maxPages` 引数（WordPress 4場で `since` 指定時のページ送り上限）
- `Article::toArray()`（snake_case。`id` は `source_id`）
- `Sources\ArraySource`（テスト用。渡した記事を返す）

### Changed

- `Collector::collect()` の `since` が `DateTimeInterface` を受ける（Laravel の `now()` など mutable な値もそのまま渡せる）。後方互換あり

## 0.1.0 - 2026-10-13

### Added

- AUTORACE.JP の RSS、日刊スポーツの Atom と記事ページ、川口・伊勢崎・浜松・山陽の WordPress REST、公式 YouTube から記事を集める `Collector`
