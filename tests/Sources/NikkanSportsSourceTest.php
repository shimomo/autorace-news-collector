<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Tests\Sources;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shimomo\AutoraceNewsCollector\Http\HttpFetcher;
use Shimomo\AutoraceNewsCollector\Sources\NikkanSportsSource;
use Shimomo\AutoraceNewsCollector\Tests\Support\Fixtures;
use Shimomo\AutoraceNewsCollector\Tests\Support\SpySleeper;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @author shimomo
 */
final class NikkanSportsSourceTest extends TestCase
{
    private const string FIRST_ARTICLE_URL = 'https://www.nikkansports.com/public_race/news/202610120001800.html';

    private const string BROKEN_FEED = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <feed xmlns="http://www.w3.org/2005/Atom">
          <entry>
            <title>リンクなし</title>
            <published>2026-10-12T22:51:55+09:00</published>
          </entry>
          <entry>
            <title>番号なし</title>
            <link rel="alternate" type="text/html" href="https://www.nikkansports.com/public_race/auto/index.html" />
            <published>2026-10-12T22:51:55+09:00</published>
          </entry>
          <entry>
            <title>正常</title>
            <link rel="alternate" type="text/html" href="https://www.nikkansports.com/public_race/news/202610120001800.html" />
            <published>2026-10-12T22:51:55+09:00</published>
            <summary>要約</summary>
          </entry>
        </feed>
        XML;

    private const string PAGE_WITHOUT_BODY = '<html><body><section id="articleMain"><p>本文の無いレイアウト</p></section></body></html>';

    /**
     * @return void
     */
    #[Test]
    public function fetchesEveryEntryAndItsArticlePage(): void
    {
        $client = $this->client(Fixtures::read('nikkansports/atom.xml'));

        $result = $this->source($client)->fetch(null);

        self::assertCount(10, $result->articles);
        self::assertSame(11, $client->getRequestsCount());
        // 記事ページのフィクスチャは先頭の1件分だけなので、残り9件は 404 で要約のみになる
        self::assertCount(9, $result->failures);
        self::assertNotNull($result->articles[0]->bodyHtml);
        self::assertNull($result->articles[1]->bodyHtml);
        self::assertNotNull($result->articles[1]->summary);
        self::assertStringEndsWith('.html', (string) $result->failures[0]->url);
        self::assertStringContainsString('returned HTTP 404.', $result->failures[0]->message);
        self::assertSame('nikkansports', $result->failures[0]->source);
    }

    /**
     * @return void
     */
    #[Test]
    public function mapsTheFirstEntry(): void
    {
        $article = $this->source($this->client(Fixtures::read('nikkansports/atom.xml')))->fetch(null)->articles[0];

        self::assertSame('nikkansports', $article->source);
        self::assertSame('日刊スポーツ', $article->sourceName);
        self::assertSame('202610120001800', $article->id);
        self::assertSame(self::FIRST_ARTICLE_URL, $article->url);
        self::assertSame('【オートレース】佐藤励は優勝戦３着も序盤の展開を悔やむ「チャンスがあったかも…」／飯塚G2', $article->title);
        self::assertSame([], $article->categories);
        self::assertSame('2026-10-12T22:51:55+09:00', $article->publishedAt->format(DATE_ATOM));
        self::assertNotNull($article->summary);
        self::assertStringStartsWith('佐藤励（26＝川口）は、序盤の展開を悔やんだ。', $article->summary);
        self::assertSame('https://www.nikkansports.com/public_race/news/img/202610120001800-w500_0.jpg', $article->thumbnailUrl);
    }

    /**
     * @return void
     */
    #[Test]
    public function extractsPrologueAndBodyFromTheArticlePage(): void
    {
        $article = $this->source($this->client(Fixtures::read('nikkansports/atom.xml')))->fetch(null)->articles[0];

        self::assertNotNull($article->bodyHtml);
        self::assertNotNull($article->bodyText);
        self::assertStringStartsWith('<p class="prologue">＜飯塚オート：オーバルチャンピオンカップ＞◇G2◇最終日◇12日</p>', $article->bodyHtml);
        self::assertStringContainsString('<p>佐藤励（26＝川口）は、序盤の展開を悔やんだ。', $article->bodyHtml);
        self::assertStringNotContainsString('<script', $article->bodyHtml);
        self::assertStringStartsWith('＜飯塚オート：オーバルチャンピオンカップ＞◇G2◇最終日◇12日', $article->bodyText);
        self::assertStringContainsString("\n佐藤励（26＝川口）は、序盤の展開を悔やんだ。", $article->bodyText);
        self::assertStringEndsWith('決意を新たにしていた。', $article->bodyText);
    }

    /**
     * @return void
     */
    #[Test]
    public function skipsArticlePagesFilteredBySince(): void
    {
        $client = $this->client(Fixtures::read('nikkansports/atom.xml'));

        $result = $this->source($client)->fetch(new DateTimeImmutable('2026-10-12 22:00:00 +09:00'));

        self::assertCount(3, $result->articles);
        self::assertSame(4, $client->getRequestsCount());
        self::assertCount(2, $result->failures);
    }

    /**
     * @return void
     */
    #[Test]
    public function returnsSummaryOnlyWhenBodyIsMissing(): void
    {
        $client = $this->client(Fixtures::read('nikkansports/atom.xml'), self::PAGE_WITHOUT_BODY);

        $result = $this->source($client)->fetch(new DateTimeImmutable('2026-10-12 22:30:00 +09:00'));

        self::assertCount(1, $result->articles);
        self::assertSame(2, $client->getRequestsCount());
        self::assertNull($result->articles[0]->bodyHtml);
        self::assertNull($result->articles[0]->bodyText);
        self::assertNotNull($result->articles[0]->summary);
        self::assertSame('https://www.nikkansports.com/public_race/news/img/202610120001800-w500_0.jpg', $result->articles[0]->thumbnailUrl);
        self::assertCount(1, $result->failures);
        self::assertSame(self::FIRST_ARTICLE_URL, $result->failures[0]->url);
        self::assertSame('Article body (#articleMain div#news) was not found.', $result->failures[0]->message);
    }

    /**
     * @return void
     */
    #[Test]
    public function skipsBrokenEntry(): void
    {
        $result = $this->source($this->client(self::BROKEN_FEED))->fetch(null);

        self::assertCount(1, $result->articles);
        self::assertSame('202610120001800', $result->articles[0]->id);
        self::assertSame('要約', $result->articles[0]->summary);
        self::assertNotNull($result->articles[0]->bodyText);
        self::assertCount(2, $result->failures);
        self::assertNull($result->failures[0]->url);
        self::assertSame('Feed entry is missing link, title, or published.', $result->failures[0]->message);
        self::assertSame('https://www.nikkansports.com/public_race/auto/index.html', $result->failures[1]->url);
        self::assertSame('Could not derive the article id from the link.', $result->failures[1]->message);
    }

    /**
     * フィードは FEED_URL、記事ページは先頭1件のフィクスチャ（または差し替え HTML）、それ以外は 404。
     *
     * @param string $feed
     * @param ?string $articlePage null なら先頭の記事 URL だけフィクスチャを返す
     * @return \Symfony\Component\HttpClient\MockHttpClient
     */
    private function client(string $feed, ?string $articlePage = null): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url) use ($feed, $articlePage): MockResponse {
            self::assertSame('GET', $method);

            if ($url === NikkanSportsSource::FEED_URL) {
                return new MockResponse($feed);
            }

            if ($articlePage !== null) {
                return new MockResponse($articlePage);
            }

            if ($url === self::FIRST_ARTICLE_URL) {
                return new MockResponse(Fixtures::read('nikkansports/article-202610120001800.html'));
            }

            return new MockResponse('', ['http_code' => 404]);
        });
    }

    /**
     * @param \Symfony\Component\HttpClient\MockHttpClient $client
     * @return \Shimomo\AutoraceNewsCollector\Sources\NikkanSportsSource
     */
    private function source(MockHttpClient $client): NikkanSportsSource
    {
        return new NikkanSportsSource(new HttpFetcher($client, new SpySleeper(), 0.0));
    }
}
