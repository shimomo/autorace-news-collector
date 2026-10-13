<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Tests\Sources;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shimomo\AutoraceNewsCollector\Article;
use Shimomo\AutoraceNewsCollector\SourceFailure;
use Shimomo\AutoraceNewsCollector\Sources\ArraySource;

/**
 * @author shimomo
 */
final class ArraySourceTest extends TestCase
{
    /**
     * @return void
     */
    #[Test]
    public function exposesKeyAndName(): void
    {
        $source = new ArraySource('fake', 'Fake source');

        self::assertSame('fake', $source->key());
        self::assertSame('Fake source', $source->name());
    }

    /**
     * @return void
     */
    #[Test]
    public function returnsEverythingWhenSinceIsNull(): void
    {
        $articles = [self::article('1', '2026-10-12 10:00:00'), self::article('2', '2026-10-11 10:00:00')];
        $failures = [new SourceFailure('fake', null, 'broken item')];

        $result = (new ArraySource('fake', 'Fake source', $articles, $failures))->fetch(null);

        self::assertSame($articles, $result->articles);
        self::assertSame($failures, $result->failures);
    }

    /**
     * 実ソースと同じく境界は含む（publishedAt >= since）。順序は渡した通り。
     *
     * @return void
     */
    #[Test]
    public function keepsArticlesPublishedAtOrAfterSince(): void
    {
        $before = self::article('1', '2026-10-12 09:59:59');
        $equal = self::article('2', '2026-10-12 10:00:00');
        $after = self::article('3', '2026-10-12 10:00:01');
        $since = new DateTimeImmutable('2026-10-12 10:00:00', new DateTimeZone('Asia/Tokyo'));

        $result = (new ArraySource('fake', 'Fake source', [$after, $before, $equal]))->fetch($since);

        self::assertSame([$after, $equal], $result->articles);
    }

    /**
     * @return void
     */
    #[Test]
    public function rejectsAnEmptyKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('ArraySource::$key must not be empty.');

        new ArraySource('', 'Fake source');
    }

    /**
     * @return void
     */
    #[Test]
    public function rejectsAnEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('ArraySource::$name must not be empty.');

        new ArraySource('fake', '');
    }

    /**
     * @param string $id
     * @param string $time Asia/Tokyo
     * @return \Shimomo\AutoraceNewsCollector\Article
     */
    private static function article(string $id, string $time): Article
    {
        return new Article(
            'fake',
            'Fake source',
            $id,
            'https://example.test/' . $id,
            'title ' . $id,
            [],
            new DateTimeImmutable($time, new DateTimeZone('Asia/Tokyo')),
        );
    }
}
