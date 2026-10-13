<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Tests\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shimomo\AutoraceNewsCollector\Http\HttpException;
use Shimomo\AutoraceNewsCollector\Http\HttpFetcher;
use Shimomo\AutoraceNewsCollector\Tests\Support\SpySleeper;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * @author shimomo
 */
final class HttpFetcherTest extends TestCase
{
    /**
     * @return void
     */
    #[Test]
    public function sendsUserAgentAcceptLanguageAndTimeout(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            /** @var array{normalized_headers: array<string, list<string>>, timeout: float} $options */
            self::assertSame('GET', $method);
            self::assertSame('https://example.test/feed.xml', $url);
            self::assertSame(['User-Agent: ' . HttpFetcher::DEFAULT_USER_AGENT], $options['normalized_headers']['user-agent']);
            self::assertSame(['Accept-Language: ja'], $options['normalized_headers']['accept-language']);
            self::assertSame(15.0, $options['timeout']);

            return new MockResponse('<rss/>');
        });

        $response = (new HttpFetcher($client, new SpySleeper()))->get('https://example.test/feed.xml');

        self::assertSame('<rss/>', $response->body);
        self::assertSame(1, $client->getRequestsCount());
    }

    /**
     * @return void
     */
    #[Test]
    public function allowsOverridingUserAgentAndTimeout(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            /** @var array{normalized_headers: array<string, list<string>>, timeout: float} $options */
            self::assertSame(['User-Agent: my-agent/1.0'], $options['normalized_headers']['user-agent']);
            self::assertSame(3.0, $options['timeout']);

            return new MockResponse('ok');
        });

        (new HttpFetcher($client, new SpySleeper(), 1.0, 3.0, 'my-agent/1.0'))->get('https://example.test/');
    }

    /**
     * @return void
     */
    #[Test]
    public function sleepsForTheIntervalBeforeEveryRequestButTheFirst(): void
    {
        $sleeper = new SpySleeper();
        $client = new MockHttpClient([new MockResponse('a'), new MockResponse('b'), new MockResponse('c')]);
        $fetcher = new HttpFetcher($client, $sleeper, 1.5);

        $fetcher->get('https://example.test/1');
        $fetcher->get('https://example.test/2');
        $fetcher->get('https://example.test/3');

        self::assertSame([1.5, 1.5], $sleeper->calls);
    }

    /**
     * @return void
     */
    #[Test]
    public function doesNotSleepWhenIntervalIsZero(): void
    {
        $sleeper = new SpySleeper();
        $fetcher = new HttpFetcher(new MockHttpClient([new MockResponse('a'), new MockResponse('b')]), $sleeper, 0.0);

        $fetcher->get('https://example.test/1');
        $fetcher->get('https://example.test/2');

        self::assertSame([], $sleeper->calls);
    }

    /**
     * @return void
     */
    #[Test]
    public function throwsOnNon2xxStatus(): void
    {
        $fetcher = new HttpFetcher(new MockHttpClient([new MockResponse('gone', ['http_code' => 404])]), new SpySleeper(), 0.0);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessageIs('GET https://example.test/missing returned HTTP 404.');

        $fetcher->get('https://example.test/missing');
    }

    /**
     * @return void
     */
    #[Test]
    public function wrapsTransportErrors(): void
    {
        $fetcher = new HttpFetcher(new MockHttpClient([new MockResponse('', ['error' => 'Connection refused'])]), new SpySleeper(), 0.0);

        try {
            $fetcher->get('https://example.test/');
            self::fail('HttpException was not thrown.');
        } catch (HttpException $e) {
            self::assertStringContainsString('GET https://example.test/ failed: Connection refused', $e->getMessage());
            self::assertInstanceOf(TransportExceptionInterface::class, $e->getPrevious());
        }
    }

    /**
     * @return void
     */
    #[Test]
    public function lowercasesHeaderNames(): void
    {
        $client = new MockHttpClient([new MockResponse('[]', ['response_headers' => ['X-WP-TotalPages' => '3']])]);

        $response = (new HttpFetcher($client, new SpySleeper(), 0.0))->get('https://example.test/');

        self::assertSame(['3'], $response->headers['x-wp-totalpages']);
        self::assertSame('3', $response->header('X-WP-TotalPages'));
    }

    /**
     * @return void
     */
    #[Test]
    public function getJsonDecodesTheBody(): void
    {
        $client = new MockHttpClient([new MockResponse('{"id":1}')]);

        self::assertSame(['id' => 1], (new HttpFetcher($client, new SpySleeper(), 0.0))->getJson('https://example.test/'));
    }
}
