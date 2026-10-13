<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Tests\Sources;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shimomo\AutoraceNewsCollector\Http\HttpException;
use Shimomo\AutoraceNewsCollector\Http\HttpFetcher;
use Shimomo\AutoraceNewsCollector\Sources\AutoraceJpSource;
use Shimomo\AutoraceNewsCollector\Tests\Support\Fixtures;
use Shimomo\AutoraceNewsCollector\Tests\Support\SpySleeper;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @author shimomo
 */
final class AutoraceJpSourceTest extends TestCase
{
    private const string BROKEN_FEED = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0">
          <channel>
            <item>
              <title>正常</title>
              <link>https://autorace.jp/news/2026/10/12/000001/</link>
              <description><![CDATA[<p>本文</p>]]></description>
              <pubDate>Mon, 12 Oct 2026 10:00:00 +0900</pubDate>
            </item>
            <item>
              <title>日付なし</title>
              <link>https://autorace.jp/news/2026/10/12/000002/</link>
            </item>
            <item>
              <title>番号なし</title>
              <link>https://autorace.jp/news/campaign/view/</link>
              <pubDate>Mon, 12 Oct 2026 09:00:00 +0900</pubDate>
            </item>
            <item>
              <title>日付が壊れている</title>
              <link>https://autorace.jp/news/2026/10/12/000004/</link>
              <pubDate>yesterday</pubDate>
            </item>
          </channel>
        </rss>
        XML;

    /**
     * @return void
     */
    #[Test]
    public function parsesEveryItemOfTheFixture(): void
    {
        $result = $this->source(Fixtures::read('autorace-jp/rss.xml'))->fetch(null);

        self::assertCount(15, $result->articles);
        self::assertSame([], $result->failures);
    }

    /**
     * @return void
     */
    #[Test]
    public function mapsTheFirstItem(): void
    {
        $article = $this->source(Fixtures::read('autorace-jp/rss.xml'))->fetch(null)->articles[0];

        self::assertSame('autorace.jp', $article->source);
        self::assertSame('AUTORACE.JP', $article->sourceName);
        self::assertSame('038810', $article->id);
        self::assertSame('https://autorace.jp/news/2026/10/12/038810/', $article->url);
        self::assertSame('【日刊提供】佐藤励は序盤展開悔やむ「前に出られていたら」落車避けて3着／飯塚', $article->title);
        self::assertSame(['ニュース', 'レース情報', '飯塚'], $article->categories);
        self::assertSame('2026-10-12T23:20:03+09:00', $article->publishedAt->format(DATE_ATOM));
        self::assertSame('Asia/Tokyo', $article->publishedAt->getTimezone()->getName());
        self::assertNull($article->summary);
        self::assertSame('https://autorace.jp/g2/20261008/images/1012/img-05.jpg', $article->thumbnailUrl);
    }

    /**
     * @return void
     */
    #[Test]
    public function normalizesTheBody(): void
    {
        $article = $this->source(Fixtures::read('autorace-jp/rss.xml'))->fetch(null)->articles[0];

        self::assertNotNull($article->bodyHtml);
        self::assertNotNull($article->bodyText);
        self::assertStringContainsString('src="https://autorace.jp/g2/20261008/images/1012/img-05.jpg"', $article->bodyHtml);
        self::assertStringContainsString('href="https://autorace.jp/g2/20261008/"', $article->bodyHtml);
        self::assertStringStartsWith('3着でレースを終えて悔しそうな表情の佐藤励', $article->bodyText);
        self::assertStringContainsString("\n佐藤励（26＝川口）は、序盤の展開を悔やんだ。", $article->bodyText);
        self::assertStringEndsWith('特設サイトへ', $article->bodyText);
    }

    /**
     * @return void
     */
    #[Test]
    public function filtersBySinceInclusive(): void
    {
        $source = $this->source(Fixtures::read('autorace-jp/rss.xml'));

        $sameSecond = $source->fetch(new DateTimeImmutable('2026-10-11 22:00:02 +09:00'))->articles;
        $midnight = $source->fetch(new DateTimeImmutable('2026-10-12 00:00:00 +09:00'))->articles;

        self::assertCount(7, $sameSecond);
        self::assertSame('2026-10-11T22:00:02+09:00', $sameSecond[6]->publishedAt->format(DATE_ATOM));
        self::assertCount(5, $midnight);
    }

    /**
     * @return void
     */
    #[Test]
    public function skipsBrokenItems(): void
    {
        $result = $this->source(self::BROKEN_FEED)->fetch(null);

        self::assertCount(1, $result->articles);
        self::assertSame('000001', $result->articles[0]->id);
        self::assertSame('<p>本文</p>', $result->articles[0]->bodyHtml);
        self::assertCount(3, $result->failures);
        self::assertSame('https://autorace.jp/news/2026/10/12/000002/', $result->failures[0]->url);
        self::assertSame('Feed item is missing link, title, or pubDate.', $result->failures[0]->message);
        self::assertSame('Could not derive the article id from the link.', $result->failures[1]->message);
        self::assertSame('Unparseable pubDate "yesterday".', $result->failures[2]->message);
        self::assertSame('autorace.jp', $result->failures[2]->source);
    }

    /**
     * @return void
     */
    #[Test]
    public function propagatesFeedFailures(): void
    {
        $this->expectException(HttpException::class);

        $this->source('', 500)->fetch(null);
    }

    /**
     * @param string $feed
     * @param int $status
     * @return \Shimomo\AutoraceNewsCollector\Sources\AutoraceJpSource
     */
    private function source(string $feed, int $status = 200): AutoraceJpSource
    {
        $client = new MockHttpClient(function (string $method, string $url) use ($feed, $status): MockResponse {
            self::assertSame('GET', $method);
            self::assertSame(AutoraceJpSource::FEED_URL, $url);

            return new MockResponse($feed, ['http_code' => $status]);
        });

        return new AutoraceJpSource(new HttpFetcher($client, new SpySleeper(), 0.0));
    }
}
