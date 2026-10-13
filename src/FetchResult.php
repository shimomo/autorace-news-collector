<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector;

/**
 * 1ソース分の取得結果。
 *
 * @author shimomo
 */
final readonly class FetchResult
{
    /**
     * @param list<Article> $articles
     * @param list<SourceFailure> $failures
     */
    public function __construct(
        public array $articles = [],
        public array $failures = [],
    ) {
    }
}
