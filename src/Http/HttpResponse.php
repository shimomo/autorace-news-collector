<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Http;

use JsonException;

/**
 * 取得結果。ヘッダ名は小文字に揃えてある（symfony の getHeaders() と同じ形）。
 *
 * @author shimomo
 */
final readonly class HttpResponse
{
    /**
     * @param string $body
     * @param array<string, list<string>> $headers 小文字のヘッダ名 → 値の一覧
     */
    public function __construct(
        public string $body,
        public array $headers = [],
    ) {
    }

    /**
     * @param string $name 大文字小文字は問わない
     * @return ?string 先頭の値。無ければ null
     */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function json(): array
    {
        try {
            $decoded = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new HttpException('Response body is not valid JSON: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($decoded)) {
            throw new HttpException('Response body is not a JSON array or object.');
        }

        return $decoded;
    }
}
