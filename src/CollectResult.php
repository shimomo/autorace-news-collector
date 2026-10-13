<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector;

/**
 * 全ソースを束ねた結果。articles は publishedAt の新しい順。
 *
 * @author shimomo
 */
final readonly class CollectResult
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
