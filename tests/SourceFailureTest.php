<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Shimomo\AutoraceNewsCollector\SourceFailure;

/**
 * @author shimomo
 */
final class SourceFailureTest extends TestCase
{
    /**
     * @return void
     */
    #[Test]
    public function holdsGivenValues(): void
    {
        $previous = new RuntimeException('boom');

        $failure = new SourceFailure('nikkansports', 'https://example.test/a.html', 'GET failed', $previous);

        self::assertSame('nikkansports', $failure->source);
        self::assertSame('https://example.test/a.html', $failure->url);
        self::assertSame('GET failed', $failure->message);
        self::assertSame($previous, $failure->previous);
    }

    /**
     * @return void
     */
    #[Test]
    public function previousDefaultsToNull(): void
    {
        $failure = new SourceFailure('youtube', null, 'parse error');

        self::assertNull($failure->url);
        self::assertNull($failure->previous);
    }
}
