<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Tests\Sources;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shimomo\AutoraceNewsCollector\Article;
use Shimomo\AutoraceNewsCollector\Http\HttpFetcher;
use Shimomo\AutoraceNewsCollector\Sources\WordPressSite;
use Shimomo\AutoraceNewsCollector\Sources\WordPressSource;
use Shimomo\AutoraceNewsCollector\Tests\Support\Fixtures;
use Shimomo\AutoraceNewsCollector\Tests\Support\SpySleeper;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @author shimomo
 */
final class WordPressSourceTest extends TestCase
{
    /**
     * @return void
     */
    #[Test]
    public function buildsTheQueryWithoutSince(): void
    {
        $url = $this->source(WordPressSite::kawaguchiNews(), [])->url(1, null);
        $query = self::query($url);

        self::assertStringStartsWith('https://www.kawaguchiauto.jp/wp-json/wp/v2/news?', $url);
        self::assertSame('100', $query['per_page']);
        self::assertSame('1', $query['page']);
        self::assertSame('date', $query['orderby']);
        self::assertSame('desc', $query['order']);
        self::assertSame('wp:featuredmedia,wp:term', $query['_embed']);
        self::assertSame('id,date_gmt,link,title,content,excerpt,_links,_embedded', $query['_fields']);
        self::assertArrayNotHasKey('after', $query);
    }

    /**
     * @return void
     */
    #[Test]
    public function buildsAfterFromSinceMinusOneSecondInUtc(): void
    {
        $query = self::query($this->source(WordPressSite::sanyo(), [])->url(2, new DateTimeImmutable('2026-10-12 00:00:00 +09:00')));

        self::assertSame('2', $query['page']);
        self::assertSame('2026-10-11T14:59:59+00:00', $query['after']);
    }

    /**
     * @return void
     */
    #[Test]
    public function mapsKawaguchiNews(): void
    {
        $article = $this->fixtureArticles(WordPressSite::kawaguchiNews(), 'wordpress/kawaguchi-news.json')[0];

        self::assertSame('kawaguchi', $article->source);
        self::assertSame('川口オートレース', $article->sourceName);
        self::assertSame('13509', $article->id);
        self::assertSame('https://www.kawaguchiauto.jp/news/20261004/', $article->url);
        self::assertSame('【10月16日～20日】非開催払戻休止のお知らせ', $article->title);
        self::assertSame(['重要なニュース'], $article->categories);
        self::assertSame('2026-10-04T08:00:00+09:00', $article->publishedAt->format(DATE_ATOM));
        self::assertSame('Asia/Tokyo', $article->publishedAt->getTimezone()->getName());
        self::assertNotNull($article->summary);
        self::assertStringStartsWith('10月16日(金)～20日(火)は、設備点検のため', $article->summary);
        self::assertNotNull($article->bodyHtml);
        self::assertNotNull($article->bodyText);
        self::assertStringContainsString('設備点検', $article->bodyText);
        self::assertSame('https://www.kawaguchiauto.jp/wp-content/uploads/2026/10/1016.jpg', $article->thumbnailUrl);
    }

    /**
     * @return void
     */
    #[Test]
    public function mapsKawaguchiInterview(): void
    {
        $article = $this->fixtureArticles(WordPressSite::kawaguchiInterview(), 'wordpress/kawaguchi-interview.json')[0];

        self::assertSame('kawaguchi', $article->source);
        self::assertSame('13596', $article->id);
        self::assertSame('https://www.kawaguchiauto.jp/interview/20261007/', $article->url);
        self::assertSame('デイリースポーツ杯 黒川 京介選手(2026/10/7)', $article->title);
        self::assertSame(['選手インタビュー'], $article->categories);
        self::assertSame('2026-10-07T18:42:39+09:00', $article->publishedAt->format(DATE_ATOM));
        self::assertNotNull($article->summary);
        self::assertStringStartsWith('ありがとうございます。', $article->summary);
        self::assertNotNull($article->bodyText);
        self::assertStringContainsString('今年9回目の優勝', $article->bodyText);
        self::assertSame('https://www.kawaguchiauto.jp/wp-content/uploads/2026/10/winner_20261007-1.jpg', $article->thumbnailUrl);
    }

    /**
     * @return void
     */
    #[Test]
    public function mapsIsesakiIncludingEmptyBodies(): void
    {
        $articles = $this->fixtureArticles(WordPressSite::isesaki(), 'wordpress/isesaki-posts.json');

        self::assertSame('isesaki', $articles[0]->source);
        self::assertSame('伊勢崎オートレース', $articles[0]->sourceName);
        self::assertSame('7161', $articles[0]->id);
        self::assertSame('https://www.isesaki-auto.jp/7161/', $articles[0]->url);
        self::assertSame('おトクにPLAY!オッズパーク杯伊勢崎オートアフター5ナイター　優勝者は櫻井晴光選手', $articles[0]->title);
        self::assertSame(['お知らせ'], $articles[0]->categories);
        self::assertSame('2026-10-07T21:14:40+09:00', $articles[0]->publishedAt->format(DATE_ATOM));
        self::assertNull($articles[0]->summary);
        self::assertNull($articles[0]->bodyHtml);
        self::assertNull($articles[0]->bodyText);
        self::assertNull($articles[0]->thumbnailUrl);

        self::assertSame('7137', $articles[1]->id);
        self::assertNotNull($articles[1]->bodyText);
        self::assertStringContainsString('横断幕掲出の注意事項', $articles[1]->bodyText);
        self::assertSame('https://www.isesaki-auto.jp/wp-content/uploads/2026/07/8f6a3d5697272cb51d6bd3c5a48c3424.jpg', $articles[1]->thumbnailUrl);
    }

    /**
     * @return void
     */
    #[Test]
    public function mapsHamamatsuWithoutCategories(): void
    {
        $article = $this->fixtureArticles(WordPressSite::hamamatsu(), 'wordpress/hamamatsu-newsinfo.json')[0];

        self::assertSame('hamamatsu', $article->source);
        self::assertSame('浜松オート', $article->sourceName);
        self::assertSame('4969', $article->id);
        self::assertSame('https://www.hamamatsuauto.jp/newsinfo/4969', $article->url);
        self::assertSame('10月18日（日）オートレース一宮　北市唯選手トークショー実施のお知らせ', $article->title);
        self::assertSame([], $article->categories);
        self::assertSame('2026-09-22T10:39:30+09:00', $article->publishedAt->format(DATE_ATOM));
        self::assertNull($article->summary);
        self::assertNotNull($article->bodyText);
        self::assertStringContainsString('北市唯選手トークショー', $article->bodyText);
    }

    /**
     * @return void
     */
    #[Test]
    public function mapsSanyo(): void
    {
        $article = $this->fixtureArticles(WordPressSite::sanyo(), 'wordpress/sanyo-posts.json')[0];

        self::assertSame('sanyo', $article->source);
        self::assertSame('山陽オート', $article->sourceName);
        self::assertSame('3974', $article->id);
        self::assertSame('https://sanyoauto.jp/topics/banner-guide/202610123974.html', $article->url);
        self::assertSame('【10/28(水)必着】日刊スポーツGⅡ第37回若獅子杯争奪戦　横断幕の掲出について', $article->title);
        self::assertSame(['横断幕の掲出'], $article->categories);
        self::assertSame('2026-10-12T22:29:22+09:00', $article->publishedAt->format(DATE_ATOM));
        self::assertNotNull($article->bodyText);
        self::assertStringStartsWith('２０２６年１１月１１日(水)～１１月１５日(日)開催', $article->bodyText);
    }

    /**
     * @return void
     */
    #[Test]
    public function followsPagesWhenSinceIsGiven(): void
    {
        /** @var list<string> $urls */
        $urls = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$urls): MockResponse {
            $urls[] = $url;

            return self::wpResponse([self::post(count($urls), '2026-10-05T00:00:00')], 3);
        });
        $source = new WordPressSource(WordPressSite::sanyo(), new HttpFetcher($client, new SpySleeper(), 0.0), 2);

        $result = $source->fetch(new DateTimeImmutable('2026-10-01 00:00:00 +09:00'));

        self::assertCount(2, $urls);
        self::assertStringContainsString('page=1', $urls[0]);
        self::assertStringContainsString('page=2', $urls[1]);
        self::assertCount(2, $result->articles);
        self::assertSame(['1', '2'], [$result->articles[0]->id, $result->articles[1]->id]);
    }

    /**
     * @return void
     */
    #[Test]
    public function fetchesOnlyTheFirstPageWithoutSince(): void
    {
        $client = new MockHttpClient([self::wpResponse([self::post(1)], 3)]);
        $source = new WordPressSource(WordPressSite::sanyo(), new HttpFetcher($client, new SpySleeper(), 0.0));

        $result = $source->fetch(null);

        self::assertSame(1, $client->getRequestsCount());
        self::assertCount(1, $result->articles);
    }

    /**
     * @return void
     */
    #[Test]
    public function stopsAtTotalPages(): void
    {
        $client = new MockHttpClient([self::wpResponse([self::post(1, '2026-10-05T00:00:00')], 1)]);
        $source = new WordPressSource(WordPressSite::sanyo(), new HttpFetcher($client, new SpySleeper(), 0.0));

        $source->fetch(new DateTimeImmutable('2026-10-01 00:00:00 +09:00'));

        self::assertSame(1, $client->getRequestsCount());
    }

    /**
     * @return void
     */
    #[Test]
    public function treatsMissingTotalPagesHeaderAsOnePage(): void
    {
        $client = new MockHttpClient([new MockResponse((string) json_encode([self::post(1, '2026-10-05T00:00:00')]))]);
        $source = new WordPressSource(WordPressSite::sanyo(), new HttpFetcher($client, new SpySleeper(), 0.0));

        $source->fetch(new DateTimeImmutable('2026-10-01 00:00:00 +09:00'));

        self::assertSame(1, $client->getRequestsCount());
    }

    /**
     * @return void
     */
    #[Test]
    public function filtersBySinceInclusive(): void
    {
        // 2026-10-11T15:00:00Z は JST の 2026-10-12 00:00:00
        $responses = [self::wpResponse([self::post(1, '2026-10-11T15:00:00'), self::post(2, '2026-10-11T14:59:59')])];

        $result = $this->source(WordPressSite::sanyo(), $responses)->fetch(new DateTimeImmutable('2026-10-12 00:00:00 +09:00'));

        self::assertCount(1, $result->articles);
        self::assertSame('1', $result->articles[0]->id);
    }

    /**
     * @return void
     */
    #[Test]
    public function skipsPostsWithoutRequiredFields(): void
    {
        $responses = [new MockResponse((string) json_encode([
            ['link' => 'https://example.test/no-id/'],
            self::post(2, 'soon'),
            self::post(3, '2026-10-01T00:00:00', '<p></p>'),
            7,
            self::post(5),
        ]))];

        $result = $this->source(WordPressSite::sanyo(), $responses)->fetch(null);

        self::assertCount(1, $result->articles);
        self::assertSame('5', $result->articles[0]->id);
        self::assertCount(4, $result->failures);
        self::assertSame('https://example.test/no-id/', $result->failures[0]->url);
        self::assertSame('Post is missing id, link, date_gmt, or title.', $result->failures[0]->message);
        self::assertSame('Unparseable date_gmt "soon".', $result->failures[1]->message);
        self::assertSame('Post title is empty.', $result->failures[2]->message);
        self::assertSame('Post entry is not an object.', $result->failures[3]->message);
        self::assertSame('sanyo', $result->failures[3]->source);
    }

    /**
     * @return void
     */
    #[Test]
    public function toleratesMissingEmbedded(): void
    {
        $responses = [self::wpResponse([self::post(1, '2026-10-01T00:00:00', 'T', '<p><img src="/a.jpg">本文</p>')])];

        $article = $this->source(WordPressSite::kawaguchiInterview(), $responses)->fetch(null)->articles[0];

        self::assertSame(['選手インタビュー'], $article->categories);
        self::assertSame('https://example.test/a.jpg', $article->thumbnailUrl);
    }

    /**
     * @return void
     */
    #[Test]
    public function ignoresFeaturedMediaErrorObject(): void
    {
        $embedded = ['wp:featuredmedia' => [['code' => 'rest_forbidden', 'data' => ['status' => 401]]], 'wp:term' => [[['name' => 'お知らせ']], []]];
        $responses = [self::wpResponse([self::post(1, '2026-10-01T00:00:00', 'T', '<p>本文</p>', $embedded)])];

        $article = $this->source(WordPressSite::isesaki(), $responses)->fetch(null)->articles[0];

        self::assertNull($article->thumbnailUrl);
        self::assertSame(['お知らせ'], $article->categories);
    }

    /**
     * @return void
     */
    #[Test]
    public function decodesTitleEntitiesAndStripsTags(): void
    {
        $responses = [self::wpResponse([self::post(1, '2026-10-01T00:00:00', 'A &amp; B &#8211; <em>C</em>')])];

        $article = $this->source(WordPressSite::sanyo(), $responses)->fetch(null)->articles[0];

        self::assertSame('A & B – C', $article->title);
    }

    /**
     * @return void
     */
    #[Test]
    public function prependsFixedCategoriesToTermNames(): void
    {
        $embedded = ['wp:term' => [[['name' => 'イベント'], ['name' => '']]]];
        $responses = [self::wpResponse([self::post(1, '2026-10-01T00:00:00', 'T', '<p>本文</p>', $embedded)])];

        $article = $this->source(WordPressSite::kawaguchiInterview(), $responses)->fetch(null)->articles[0];

        self::assertSame(['選手インタビュー', 'イベント'], $article->categories);
    }

    /**
     * @return void
     */
    #[Test]
    public function presetsPointAtTheRightEndpoints(): void
    {
        self::assertSame('https://www.kawaguchiauto.jp/wp-json/wp/v2/news', WordPressSite::kawaguchiNews()->endpoint());
        self::assertSame('https://www.kawaguchiauto.jp/wp-json/wp/v2/interview', WordPressSite::kawaguchiInterview()->endpoint());
        self::assertSame('https://www.isesaki-auto.jp/wp-json/wp/v2/posts', WordPressSite::isesaki()->endpoint());
        self::assertSame('https://www.hamamatsuauto.jp/wp-json/wp/v2/newsinfo', WordPressSite::hamamatsu()->endpoint());
        self::assertSame('https://sanyoauto.jp/wp-json/wp/v2/posts', WordPressSite::sanyo()->endpoint());
    }

    /**
     * @param \Shimomo\AutoraceNewsCollector\Sources\WordPressSite $site
     * @param string $fixture
     * @return list<\Shimomo\AutoraceNewsCollector\Article>
     */
    private function fixtureArticles(WordPressSite $site, string $fixture): array
    {
        $client = new MockHttpClient(function (string $method, string $url) use ($site, $fixture): MockResponse {
            self::assertSame('GET', $method);
            self::assertStringStartsWith($site->endpoint() . '?', $url);

            return new MockResponse(Fixtures::read($fixture), ['response_headers' => ['x-wp-totalpages' => '1']]);
        });

        $result = $this->source($site, $client)->fetch(null);

        self::assertCount(10, $result->articles);
        self::assertSame([], $result->failures);

        foreach ($result->articles as $article) {
            self::assertInstanceOf(Article::class, $article);
        }

        return $result->articles;
    }

    /**
     * @param \Shimomo\AutoraceNewsCollector\Sources\WordPressSite $site
     * @param list<\Symfony\Component\HttpClient\Response\MockResponse>|\Symfony\Component\HttpClient\MockHttpClient $responses
     * @return \Shimomo\AutoraceNewsCollector\Sources\WordPressSource
     */
    private function source(WordPressSite $site, array|MockHttpClient $responses): WordPressSource
    {
        $client = $responses instanceof MockHttpClient ? $responses : new MockHttpClient($responses);

        return new WordPressSource($site, new HttpFetcher($client, new SpySleeper(), 0.0));
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param int $totalPages
     * @return \Symfony\Component\HttpClient\Response\MockResponse
     */
    private static function wpResponse(array $items, int $totalPages = 1): MockResponse
    {
        return new MockResponse((string) json_encode($items), ['response_headers' => ['x-wp-totalpages' => (string) $totalPages]]);
    }

    /**
     * @param int $id
     * @param string $dateGmt
     * @param string $title
     * @param string $content
     * @param ?array<string, mixed> $embedded
     * @return array<string, mixed>
     */
    private static function post(int $id, string $dateGmt = '2026-10-01T00:00:00', string $title = 'タイトル', string $content = '<p>本文</p>', ?array $embedded = null): array
    {
        $post = [
            'id' => $id,
            'date_gmt' => $dateGmt,
            'link' => 'https://example.test/' . $id . '/',
            'title' => ['rendered' => $title],
            'content' => ['rendered' => $content],
            'excerpt' => ['rendered' => ''],
        ];

        if ($embedded !== null) {
            $post['_embedded'] = $embedded;
        }

        return $post;
    }

    /**
     * @param string $url
     * @return array<array-key, mixed>
     */
    private static function query(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }
}
