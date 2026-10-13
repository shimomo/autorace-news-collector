<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Tests\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shimomo\AutoraceNewsCollector\Http\HttpException;
use Shimomo\AutoraceNewsCollector\Http\HttpResponse;

/**
 * @author shimomo
 */
final class HttpResponseTest extends TestCase
{
    /**
     * @return void
     */
    #[Test]
    public function headerIsCaseInsensitiveAndReturnsFirstValue(): void
    {
        $response = new HttpResponse('', ['x-wp-totalpages' => ['3', '9']]);

        self::assertSame('3', $response->header('X-WP-TotalPages'));
        self::assertSame('3', $response->header('x-wp-totalpages'));
        self::assertNull($response->header('Link'));
    }

    /**
     * @return void
     */
    #[Test]
    public function jsonDecodesObjectsAndArrays(): void
    {
        self::assertSame(['a' => 1], (new HttpResponse('{"a":1}'))->json());
        self::assertSame([1, 2], (new HttpResponse('[1,2]'))->json());
    }

    /**
     * @return void
     */
    #[Test]
    public function jsonRejectsInvalidJson(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessageIsOrContains('Response body is not valid JSON');

        (new HttpResponse('nope'))->json();
    }

    /**
     * @return void
     */
    #[Test]
    public function jsonRejectsScalars(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessageIs('Response body is not a JSON array or object.');

        (new HttpResponse('42'))->json();
    }
}
