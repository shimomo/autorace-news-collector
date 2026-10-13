<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * 1件の記事。
 *
 * 必須項目が空の Article を作らせないため、コンストラクタで検査する。
 * 重複判定は (source, id) で呼び出し側が行う。日刊スポーツの記事は autorace.jp にも
 * 「【日刊提供】」として載るが、URL も id も異なるのでここでは束ねない。
 *
 * @author shimomo
 */
final readonly class Article
{
    /**
     * @param string $source ソースキー（空不可）
     * @param string $sourceName 表示名（空不可）
     * @param string $id ソース内で安定した ID（空不可）
     * @param string $url 記事 URL（空不可）
     * @param string $title 見出し（空不可）
     * @param list<string> $categories ソース固有の分類
     * @param \DateTimeImmutable $publishedAt Asia/Tokyo に正規化済み
     * @param ?string $summary フィードの要約
     * @param ?string $bodyHtml 整形済み本文 HTML
     * @param ?string $bodyText 本文のプレーンテキスト
     * @param ?string $thumbnailUrl アイキャッチまたは本文先頭画像
     */
    public function __construct(
        public string $source,
        public string $sourceName,
        public string $id,
        public string $url,
        public string $title,
        public array $categories,
        public DateTimeImmutable $publishedAt,
        public ?string $summary = null,
        public ?string $bodyHtml = null,
        public ?string $bodyText = null,
        public ?string $thumbnailUrl = null,
    ) {
        $required = [
            'source' => $source,
            'sourceName' => $sourceName,
            'id' => $id,
            'url' => $url,
            'title' => $title,
        ];

        foreach ($required as $name => $value) {
            if ($value === '') {
                throw new InvalidArgumentException(sprintf('Article::$%s must not be empty.', $name));
            }
        }
    }
}
