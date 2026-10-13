<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector;

use Throwable;

/**
 * 取得・解析の失敗1件。ソース全体の失敗（url は null）にも記事1件の失敗にも使う。
 *
 * @author shimomo
 */
final readonly class SourceFailure
{
    /**
     * @param string $source ソースキー
     * @param ?string $url 失敗した URL。ソース全体の失敗なら null
     * @param string $message
     * @param ?\Throwable $previous
     */
    public function __construct(
        public string $source,
        public ?string $url,
        public string $message,
        public ?Throwable $previous = null,
    ) {
    }
}
