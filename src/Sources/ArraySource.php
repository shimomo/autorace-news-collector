<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Sources;

use DateTimeImmutable;
use InvalidArgumentException;
use Shimomo\AutoraceNewsCollector\Article;
use Shimomo\AutoraceNewsCollector\Contracts\Source as SourceContract;
use Shimomo\AutoraceNewsCollector\FetchResult;

/**
 * 渡した記事をそのまま返す Source。ネットワークに出ないので、利用側アプリのテストで Collector に差し込む。
 * since の扱いは実ソースと同じ（publishedAt >= since）。
 *
 * @author shimomo
 */
final readonly class ArraySource implements SourceContract
{
    /**
     * @param string $key ソースキー（空不可）
     * @param string $name 表示名（空不可）
     * @param list<\Shimomo\AutoraceNewsCollector\Article> $articles
     * @param list<\Shimomo\AutoraceNewsCollector\SourceFailure> $failures
     */
    public function __construct(
        private string $key,
        private string $name,
        private array $articles = [],
        private array $failures = [],
    ) {
        if ($key === '') {
            throw new InvalidArgumentException('ArraySource::$key must not be empty.');
        }

        if ($name === '') {
            throw new InvalidArgumentException('ArraySource::$name must not be empty.');
        }
    }

    /**
     * @return string
     */
    #[\Override]
    public function key(): string
    {
        return $this->key;
    }

    /**
     * @return string
     */
    #[\Override]
    public function name(): string
    {
        return $this->name;
    }

    /**
     * @param ?\DateTimeImmutable $since
     * @return \Shimomo\AutoraceNewsCollector\FetchResult
     */
    #[\Override]
    public function fetch(?DateTimeImmutable $since): FetchResult
    {
        if ($since === null) {
            return new FetchResult($this->articles, $this->failures);
        }

        return new FetchResult(
            array_values(array_filter(
                $this->articles,
                static fn(Article $article): bool => $article->publishedAt >= $since,
            )),
            $this->failures,
        );
    }
}
