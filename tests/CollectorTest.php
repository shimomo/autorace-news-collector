<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Tests;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Shimomo\AutoraceNewsCollector\Article;
use Shimomo\AutoraceNewsCollector\Collector;
use Shimomo\AutoraceNewsCollector\Contracts\Source as SourceContract;
use Shimomo\AutoraceNewsCollector\Http\HttpFetcher;
use Shimomo\AutoraceNewsCollector\SourceFailure;
use Shimomo\AutoraceNewsCollector\Sources\AutoraceJpSource;
use Shimomo\AutoraceNewsCollector\Sources\NikkanSportsSource;
use Shimomo\AutoraceNewsCollector\Sources\WordPressSite;
use Shimomo\AutoraceNewsCollector\Sources\WordPressSource;
use Shimomo\AutoraceNewsCollector\Sources\YouTubeSource;
use Shimomo\AutoraceNewsCollector\Tests\Support\Fixtures;
use Shimomo\AutoraceNewsCollector\Tests\Support\SpySleeper;
use Shimomo\AutoraceNewsCollector\Tests\Support\StubSource;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @author shimomo
 */
final class CollectorTest extends TestCase
{
    /**
     * @return void
     */
    #[Test]
    public function sortsArticlesNewestFirstAcrossSources(): void
    {
        $a1 = self::article('a', '1', '2026-10-12 10:00:00');
        $a2 = self::article('a', '2', '2026-10-12 12:00:00');
        $b1 = self::article('b', '1', '2026-10-12 11:00:00');

        $result = (new Collector([new StubSource('a', [$a1, $a2]), new StubSource('b', [$b1])]))->collect();

        self::assertSame([$a2, $b1, $a1], $result->articles);
        self::assertSame([], $result->failures);
    }

    /**
     * @return void
     */
    #[Test]
    public function keepsSourceOrderForEqualTimestamps(): void
    {
        $a1 = self::article('a', '1', '2026-10-12 10:00:00');
        $a2 = self::article('a', '2', '2026-10-12 10:00:00');
        $b1 = self::article('b', '1', '2026-10-12 10:00:00');

        $result = (new Collector([new StubSource('a', [$a1, $a2]), new StubSource('b', [$b1])]))->collect();

        self::assertSame([$a1, $a2, $b1], $result->articles);
    }

    /**
     * @return void
     */
    #[Test]
    public function isolatesSourceExceptions(): void
    {
        $exception = new RuntimeException('feed down');
        $b1 = self::article('b', '1', '2026-10-12 10:00:00');

        $result = (new Collector([new StubSource('a', [], [], $exception), new StubSource('b', [$b1])]))->collect();

        self::assertSame([$b1], $result->articles);
        self::assertCount(1, $result->failures);
        self::assertSame('a', $result->failures[0]->source);
        self::assertNull($result->failures[0]->url);
        self::assertSame('feed down', $result->failures[0]->message);
        self::assertSame($exception, $result->failures[0]->previous);
    }

    /**
     * @return void
     */
    #[Test]
    public function propagatesItemFailures(): void
    {
        $failure = new SourceFailure('a', 'https://example.test/x', 'broken item');

        $result = (new Collector([new StubSource('a', [], [$failure])]))->collect();

        self::assertSame([], $result->articles);
        self::assertSame([$failure], $result->failures);
    }

    /**
     * @return void
     */
    #[Test]
    public function passesSinceToEverySource(): void
    {
        $since = new DateTimeImmutable('2026-10-12 00:00:00 +09:00');
        $a = new StubSource('a');
        $b = new StubSource('b');

        (new Collector([$a, $b]))->collect($since);
        (new Collector([$a]))->collect();

        self::assertSame([$since, null], $a->received);
        self::assertSame([$since], $b->received);
    }

    /**
     * @return void
     */
    #[Test]
    public function defaultBuildsEightSources(): void
    {
        $sources = Collector::default(new HttpFetcher(new MockHttpClient(), new SpySleeper(), 0.0))->sources();

        self::assertSame(
            ['autorace.jp', 'nikkansports', 'kawaguchi', 'kawaguchi', 'isesaki', 'hamamatsu', 'sanyo', 'youtube'],
            array_map(static fn(SourceContract $source): string => $source->key(), $sources),
        );
        self::assertInstanceOf(AutoraceJpSource::class, $sources[0]);
        self::assertInstanceOf(NikkanSportsSource::class, $sources[1]);
        self::assertInstanceOf(WordPressSource::class, $sources[2]);
        self::assertInstanceOf(WordPressSource::class, $sources[6]);
        self::assertInstanceOf(YouTubeSource::class, $sources[7]);
        self::assertSame('川口オートレース', $sources[3]->name());
    }

    /**
     * 8ソースのフィクスチャを URL で振り分け、default() の配線とスロットリングを通しで確かめる。
     *
     * @return void
     */
    #[Test]
    public function defaultCollectsEveryFixture(): void
    {
        $client = new MockHttpClient(static function (string $method, string $url): MockResponse {
            $wordpress = [
                WordPressSite::kawaguchiNews()->endpoint() => 'wordpress/kawaguchi-news.json',
                WordPressSite::kawaguchiInterview()->endpoint() => 'wordpress/kawaguchi-interview.json',
                WordPressSite::isesaki()->endpoint() => 'wordpress/isesaki-posts.json',
                WordPressSite::hamamatsu()->endpoint() => 'wordpress/hamamatsu-newsinfo.json',
                WordPressSite::sanyo()->endpoint() => 'wordpress/sanyo-posts.json',
            ];

            if ($url === AutoraceJpSource::FEED_URL) {
                return new MockResponse(Fixtures::read('autorace-jp/rss.xml'));
            }

            if ($url === NikkanSportsSource::FEED_URL) {
                return new MockResponse(Fixtures::read('nikkansports/atom.xml'));
            }

            if (str_starts_with($url, 'https://www.nikkansports.com/public_race/news/')) {
                return new MockResponse(Fixtures::read('nikkansports/article-202610120001800.html'));
            }

            if (str_starts_with($url, 'https://www.youtube.com/feeds/videos.xml?channel_id=')) {
                return new MockResponse(Fixtures::read('youtube/videos.xml'));
            }

            foreach ($wordpress as $endpoint => $fixture) {
                if (str_starts_with($url, $endpoint . '?')) {
                    return new MockResponse(Fixtures::read($fixture), ['response_headers' => ['x-wp-totalpages' => '1']]);
                }
            }

            self::fail('Unexpected request: ' . $method . ' ' . $url);
        });
        $sleeper = new SpySleeper();

        $result = Collector::default(new HttpFetcher($client, $sleeper))->collect();

        self::assertCount(90, $result->articles);
        self::assertSame([], $result->failures);
        self::assertSame(18, $client->getRequestsCount());
        self::assertCount(17, $sleeper->calls);
        self::assertSame('autorace.jp', $result->articles[0]->source);
        self::assertSame('038810', $result->articles[0]->id);

        $keys = array_values(array_unique(array_map(static fn(Article $article): string => $article->source, $result->articles)));
        sort($keys);

        self::assertSame(['autorace.jp', 'hamamatsu', 'isesaki', 'kawaguchi', 'nikkansports', 'sanyo', 'youtube'], $keys);
    }

    /**
     * @return void
     */
    #[Test]
    public function onlyKeepsSourcesWithTheGivenKeys(): void
    {
        $a = new StubSource('a');
        $b = new StubSource('b');
        $c = new StubSource('c');
        $collector = new Collector([$a, $b, $c]);

        $only = $collector->only('c', 'a');

        self::assertSame([$a, $c], $only->sources());
        self::assertSame([$a, $b, $c], $collector->sources());
    }

    /**
     * 川口のように同じキーを持つソースは、キー指定でまとめて残す。
     *
     * @return void
     */
    #[Test]
    public function onlyKeepsEverySourceSharingAKey(): void
    {
        $news = new StubSource('kawaguchi');
        $interview = new StubSource('kawaguchi');
        $other = new StubSource('youtube');

        $only = (new Collector([$news, $interview, $other]))->only('kawaguchi');

        self::assertSame([$news, $interview], $only->sources());
    }

    /**
     * @return void
     */
    #[Test]
    public function withoutDropsSourcesWithTheGivenKeys(): void
    {
        $a = new StubSource('a');
        $b = new StubSource('b');
        $c = new StubSource('b');
        $d = new StubSource('d');
        $collector = new Collector([$a, $b, $c, $d]);

        $without = $collector->without('b', 'd');

        self::assertSame([$a], $without->sources());
        self::assertSame([$a, $b, $c, $d], $collector->sources());
    }

    /**
     * @return void
     */
    #[Test]
    public function onlyRejectsAnUnknownKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Unknown source key "nope".');

        (new Collector([new StubSource('a')]))->only('a', 'nope');
    }

    /**
     * 設定が空のまま only(...$keys) されても黙って0件にしない。
     *
     * @return void
     */
    #[Test]
    public function onlyRequiresAtLeastOneKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('At least one source key is required.');

        (new Collector([new StubSource('a')]))->only();
    }

    /**
     * @return void
     */
    #[Test]
    public function withoutWithNoKeysKeepsEverySource(): void
    {
        $a = new StubSource('a');
        $b = new StubSource('b');

        self::assertSame([$a, $b], (new Collector([$a, $b]))->without()->sources());
    }

    /**
     * @return void
     */
    #[Test]
    public function withoutRejectsAnUnknownKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Unknown source key "nope".');

        (new Collector([new StubSource('a')]))->without('nope');
    }

    /**
     * @return void
     */
    #[Test]
    public function defaultPassesMaxPagesToTheWordPressSources(): void
    {
        $since = new DateTimeImmutable('2000-01-01 00:00:00', new DateTimeZone('Asia/Tokyo'));
        $client = static fn(): MockHttpClient => new MockHttpClient(static fn(): MockResponse => new MockResponse(
            Fixtures::read('wordpress/sanyo-posts.json'),
            ['response_headers' => ['x-wp-totalpages' => '5']],
        ));

        $limited = $client();
        $default = $client();

        Collector::default(new HttpFetcher($limited, new SpySleeper()), maxPages: 2)->only('sanyo')->collect($since);
        Collector::default(new HttpFetcher($default, new SpySleeper()))->only('sanyo')->collect($since);

        self::assertSame(2, $limited->getRequestsCount());
        self::assertSame(3, $default->getRequestsCount());
    }

    /**
     * Laravel の now() は mutable な Carbon（DateTime）なので、DateTimeImmutable 以外も受ける。
     *
     * @return void
     */
    #[Test]
    public function collectAcceptsAnyDateTimeInterfaceAsSince(): void
    {
        $since = new DateTime('2026-10-12 00:00:00', new DateTimeZone('Asia/Tokyo'));
        $source = new StubSource('a');

        (new Collector([$source]))->collect($since);

        self::assertCount(1, $source->received);
        self::assertInstanceOf(DateTimeImmutable::class, $source->received[0]);
        self::assertSame('2026-10-12T00:00:00+09:00', $source->received[0]->format(DATE_ATOM));
    }

    /**
     * @param string $source
     * @param string $id
     * @param string $time Asia/Tokyo
     * @return \Shimomo\AutoraceNewsCollector\Article
     */
    private static function article(string $source, string $id, string $time): Article
    {
        return new Article(
            $source,
            'name ' . $source,
            $id,
            'https://example.test/' . $source . '/' . $id,
            'title ' . $id,
            [],
            new DateTimeImmutable($time, new DateTimeZone('Asia/Tokyo')),
        );
    }
}
