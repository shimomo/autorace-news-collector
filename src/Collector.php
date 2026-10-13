<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector;

use DateTimeImmutable;
use Shimomo\AutoraceNewsCollector\Html\BodyNormalizer;
use Shimomo\AutoraceNewsCollector\Http\HttpFetcher;
use Shimomo\AutoraceNewsCollector\Http\NativeSleeper;
use Shimomo\AutoraceNewsCollector\Sources\AutoraceJpSource;
use Shimomo\AutoraceNewsCollector\Sources\NikkanSportsSource;
use Shimomo\AutoraceNewsCollector\Sources\WordPressSite;
use Shimomo\AutoraceNewsCollector\Sources\WordPressSource;
use Shimomo\AutoraceNewsCollector\Sources\YouTubeSource;
use Symfony\Component\HttpClient\HttpClient;
use Throwable;

/**
 * 複数のソースを順に回して結果を束ねる。collect() は例外を投げず、失敗は failures に載せる。
 *
 * @author shimomo
 */
final class Collector
{
    /**
     * @param list<\Shimomo\AutoraceNewsCollector\Contracts\Source> $sources
     */
    public function __construct(
        private array $sources,
    ) {
    }

    /**
     * 既定の8ソース。ソースキーは7種で、川口だけ news と interview の2つ。
     * 同じ HttpFetcher を全ソースで共有するので、待機間隔もソースをまたいで効く。
     *
     * @param ?\Shimomo\AutoraceNewsCollector\Http\HttpFetcher $fetcher
     * @return self
     */
    public static function default(?HttpFetcher $fetcher = null): self
    {
        $fetcher ??= new HttpFetcher(HttpClient::create(), new NativeSleeper());
        $normalizer = new BodyNormalizer();

        return new self([
            new AutoraceJpSource($fetcher, $normalizer),
            new NikkanSportsSource($fetcher, $normalizer),
            new WordPressSource(WordPressSite::kawaguchiNews(), $fetcher, normalizer: $normalizer),
            new WordPressSource(WordPressSite::kawaguchiInterview(), $fetcher, normalizer: $normalizer),
            new WordPressSource(WordPressSite::isesaki(), $fetcher, normalizer: $normalizer),
            new WordPressSource(WordPressSite::hamamatsu(), $fetcher, normalizer: $normalizer),
            new WordPressSource(WordPressSite::sanyo(), $fetcher, normalizer: $normalizer),
            new YouTubeSource($fetcher),
        ]);
    }

    /**
     * @return list<\Shimomo\AutoraceNewsCollector\Contracts\Source>
     */
    public function sources(): array
    {
        return $this->sources;
    }

    /**
     * @param ?\DateTimeImmutable $since publishedAt >= since の記事だけ返す
     * @return \Shimomo\AutoraceNewsCollector\CollectResult
     */
    public function collect(?DateTimeImmutable $since = null): CollectResult
    {
        $articles = [];
        $failures = [];

        foreach ($this->sources as $source) {
            try {
                $result = $source->fetch($since);
            } catch (Throwable $e) {
                // 1ソースの失敗で全体を止めない。呼び出し側は failures を見て判断する
                $failures[] = new SourceFailure($source->key(), null, $e->getMessage(), $e);

                continue;
            }

            foreach ($result->articles as $article) {
                $articles[] = $article;
            }

            foreach ($result->failures as $failure) {
                $failures[] = $failure;
            }
        }

        // 同時刻はソース順・フィード順を保つ。PHP 8 の usort は安定
        usort($articles, static fn(Article $a, Article $b): int => $b->publishedAt <=> $a->publishedAt);

        return new CollectResult($articles, $failures);
    }
}
