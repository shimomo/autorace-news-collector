<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Shimomo\AutoraceNewsCollector\Contracts\Source as SourceContract;
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
     * @param int $maxPages WordPress 4場の since 指定時のページ送り上限
     * @return self
     */
    public static function default(?HttpFetcher $fetcher = null, int $maxPages = 3): self
    {
        $fetcher ??= new HttpFetcher(HttpClient::create(), new NativeSleeper());
        $normalizer = new BodyNormalizer();

        return new self([
            new AutoraceJpSource($fetcher, $normalizer),
            new NikkanSportsSource($fetcher, $normalizer),
            new WordPressSource(WordPressSite::kawaguchiNews(), $fetcher, $maxPages, $normalizer),
            new WordPressSource(WordPressSite::kawaguchiInterview(), $fetcher, $maxPages, $normalizer),
            new WordPressSource(WordPressSite::isesaki(), $fetcher, $maxPages, $normalizer),
            new WordPressSource(WordPressSite::hamamatsu(), $fetcher, $maxPages, $normalizer),
            new WordPressSource(WordPressSite::sanyo(), $fetcher, $maxPages, $normalizer),
            new YouTubeSource($fetcher),
        ]);
    }

    /**
     * 指定したキーのソースだけを持つ Collector を返す。同じキーのソース（川口の news と interview）はまとめて残る。
     * 未知のキーと空の指定は例外にする。設定ファイルの typo や設定漏れが黙って「0件」になると気づけないため。
     *
     * @param string ...$keys
     * @return self
     */
    public function only(string ...$keys): self
    {
        if ($keys === []) {
            throw new InvalidArgumentException('At least one source key is required.');
        }

        $this->assertKnownKeys($keys);

        return new self(array_values(array_filter(
            $this->sources,
            static fn(SourceContract $source): bool => in_array($source->key(), $keys, true),
        )));
    }

    /**
     * 指定したキーのソースを除いた Collector を返す。未知のキーは only() と同じく例外にする。
     *
     * @param string ...$keys
     * @return self
     */
    public function without(string ...$keys): self
    {
        $this->assertKnownKeys($keys);

        return new self(array_values(array_filter(
            $this->sources,
            static fn(SourceContract $source): bool => !in_array($source->key(), $keys, true),
        )));
    }

    /**
     * @return list<\Shimomo\AutoraceNewsCollector\Contracts\Source>
     */
    public function sources(): array
    {
        return $this->sources;
    }

    /**
     * @param ?\DateTimeInterface $since publishedAt >= since の記事だけ返す
     * @return \Shimomo\AutoraceNewsCollector\CollectResult
     */
    public function collect(?DateTimeInterface $since = null): CollectResult
    {
        // Laravel の now() は mutable な Carbon。ソースへは不変にして渡す（WordPressSource が since から1秒引くため）。
        // 既に不変なら同じインスタンスを渡す
        if ($since !== null && !$since instanceof DateTimeImmutable) {
            $since = DateTimeImmutable::createFromInterface($since);
        }

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

    /**
     * @param array<string> $keys
     * @return void
     */
    private function assertKnownKeys(array $keys): void
    {
        $known = array_map(static fn(SourceContract $source): string => $source->key(), $this->sources);

        foreach ($keys as $key) {
            if (!in_array($key, $known, true)) {
                throw new InvalidArgumentException(sprintf('Unknown source key "%s".', $key));
            }
        }
    }
}
