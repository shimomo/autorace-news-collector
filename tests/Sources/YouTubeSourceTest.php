<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Tests\Sources;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shimomo\AutoraceNewsCollector\Http\HttpFetcher;
use Shimomo\AutoraceNewsCollector\Sources\YouTubeSource;
use Shimomo\AutoraceNewsCollector\Tests\Support\Fixtures;
use Shimomo\AutoraceNewsCollector\Tests\Support\SpySleeper;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @author shimomo
 */
final class YouTubeSourceTest extends TestCase
{
    private const string BROKEN_FEED = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <feed xmlns:yt="http://www.youtube.com/xml/schemas/2015" xmlns:media="http://search.yahoo.com/mrss/" xmlns="http://www.w3.org/2005/Atom">
          <entry>
            <yt:videoId>ok123</yt:videoId>
            <title>説明なし</title>
            <published>2026-10-12T13:30:45+00:00</published>
            <media:group>
              <media:description></media:description>
            </media:group>
          </entry>
          <entry>
            <title>videoId なし</title>
            <published>2026-10-12T13:30:45+00:00</published>
          </entry>
          <entry>
            <yt:videoId>bad456</yt:videoId>
            <title>日付が壊れている</title>
            <published>soon</published>
          </entry>
        </feed>
        XML;

    /**
     * @return void
     */
    #[Test]
    public function parsesEveryEntryOfTheFixture(): void
    {
        $result = $this->source(Fixtures::read('youtube/videos.xml'))->fetch(null);

        self::assertCount(15, $result->articles);
        self::assertSame([], $result->failures);
    }

    /**
     * @return void
     */
    #[Test]
    public function mapsTheFirstEntry(): void
    {
        $article = $this->source(Fixtures::read('youtube/videos.xml'))->fetch(null)->articles[0];

        self::assertSame('youtube', $article->source);
        self::assertSame('オートレース公式 YouTube', $article->sourceName);
        self::assertSame('byxqNooSqmI', $article->id);
        self::assertSame('https://www.youtube.com/watch?v=byxqNooSqmI', $article->url);
        self::assertSame('飯塚オートレース中継 2026年10月12日 庄内のやきにく王国presentsＧⅡオーバルチャンピオンカップ　最終日', $article->title);
        self::assertSame([], $article->categories);
        self::assertSame('2026-10-12T22:30:45+09:00', $article->publishedAt->format(DATE_ATOM));
        self::assertNull($article->summary);
        self::assertNull($article->bodyHtml);
        self::assertNotNull($article->bodyText);
        self::assertStringStartsWith('#オートレース #autorace #公営競技', $article->bodyText);
        self::assertStringContainsString('https://autorace.jp/news/campaign/view/?utm_source=youtube&utm_medium=social', $article->bodyText);
        self::assertSame('https://i3.ytimg.com/vi/byxqNooSqmI/hqdefault.jpg', $article->thumbnailUrl);
    }

    /**
     * @return void
     */
    #[Test]
    public function filtersBySince(): void
    {
        $articles = $this->source(Fixtures::read('youtube/videos.xml'))->fetch(new DateTimeImmutable('2026-10-12 00:00:00 +09:00'))->articles;

        self::assertCount(6, $articles);
        self::assertSame('2026-10-12T07:00:06+09:00', $articles[5]->publishedAt->format(DATE_ATOM));
    }

    /**
     * @return void
     */
    #[Test]
    public function skipsEntryWithoutVideoId(): void
    {
        $result = $this->source(self::BROKEN_FEED)->fetch(null);

        self::assertCount(1, $result->articles);
        self::assertSame('ok123', $result->articles[0]->id);
        self::assertNull($result->articles[0]->bodyText);
        self::assertNull($result->articles[0]->thumbnailUrl);
        self::assertCount(2, $result->failures);
        self::assertNull($result->failures[0]->url);
        self::assertSame('Feed entry is missing videoId, title, or published.', $result->failures[0]->message);
        self::assertSame('https://www.youtube.com/watch?v=bad456', $result->failures[1]->url);
        self::assertSame('Unparseable published "soon".', $result->failures[1]->message);
    }

    /**
     * @return void
     */
    #[Test]
    public function buildsTheFeedUrlFromTheChannelId(): void
    {
        $fetcher = new HttpFetcher(new MockHttpClient(), new SpySleeper(), 0.0);

        self::assertSame('https://www.youtube.com/feeds/videos.xml?channel_id=UCJAPqcMNYk3HMknRuPMNvKA', (new YouTubeSource($fetcher))->feedUrl());
        self::assertSame('https://www.youtube.com/feeds/videos.xml?channel_id=UCother', (new YouTubeSource($fetcher, 'UCother'))->feedUrl());
    }

    /**
     * @param string $feed
     * @return \Shimomo\AutoraceNewsCollector\Sources\YouTubeSource
     */
    private function source(string $feed): YouTubeSource
    {
        $client = new MockHttpClient(function (string $method, string $url) use ($feed): MockResponse {
            self::assertSame('GET', $method);
            self::assertSame('https://www.youtube.com/feeds/videos.xml?channel_id=' . YouTubeSource::CHANNEL_ID, $url);

            return new MockResponse($feed);
        });

        return new YouTubeSource(new HttpFetcher($client, new SpySleeper(), 0.0));
    }
}
