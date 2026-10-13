<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Http;

use Shimomo\AutoraceNewsCollector\Contracts\Sleeper as SleeperContract;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * symfony/http-client の薄い包み。UA と待機を一箇所に集め、失敗を HttpException に揃える。
 *
 * 再試行は持たない。必要なら呼び出し側が RetryableHttpClient で包んだクライアントを渡す。
 *
 * @author shimomo
 */
final class HttpFetcher
{
    public const string DEFAULT_USER_AGENT = 'autorace-news-collector (+https://github.com/shimomo/autorace-news-collector)';

    private bool $requested = false;

    /**
     * @param \Symfony\Contracts\HttpClient\HttpClientInterface $client
     * @param \Shimomo\AutoraceNewsCollector\Contracts\Sleeper $sleeper
     * @param float $interval リクエスト間の待機秒数
     * @param float $timeout
     * @param string $userAgent
     */
    public function __construct(
        private HttpClientInterface $client,
        private SleeperContract $sleeper,
        private float $interval = 1.0,
        private float $timeout = 15.0,
        private string $userAgent = self::DEFAULT_USER_AGENT,
    ) {
    }

    /**
     * @param string $url
     * @return \Shimomo\AutoraceNewsCollector\Http\HttpResponse
     */
    public function get(string $url): HttpResponse
    {
        // 2回目以降だけ待つ。相手サイトへの礼儀であって、最初の1回を遅らせる理由は無い
        if ($this->requested && $this->interval > 0) {
            $this->sleeper->sleep($this->interval);
        }

        $this->requested = true;

        try {
            $response = $this->client->request('GET', $url, [
                'headers' => [
                    'User-Agent' => $this->userAgent,
                    'Accept-Language' => 'ja',
                ],
                'timeout' => $this->timeout,
            ]);

            $status = $response->getStatusCode();
            $body = $response->getContent(false);
            $rawHeaders = $response->getHeaders(false);
        } catch (ExceptionInterface $e) {
            throw new HttpException(sprintf('GET %s failed: %s', $url, $e->getMessage()), 0, $e);
        }

        if ($status < 200 || $status >= 300) {
            throw new HttpException(sprintf('GET %s returned HTTP %d.', $url, $status));
        }

        $headers = [];

        foreach ($rawHeaders as $name => $values) {
            $headers[strtolower($name)] = $values;
        }

        return new HttpResponse($body, $headers);
    }

    /**
     * @param string $url
     * @return array<array-key, mixed>
     */
    public function getJson(string $url): array
    {
        return $this->get($url)->json();
    }
}
