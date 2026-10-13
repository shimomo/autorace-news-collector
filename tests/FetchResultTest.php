<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shimomo\AutoraceNewsCollector\Article;
use Shimomo\AutoraceNewsCollector\CollectResult;
use Shimomo\AutoraceNewsCollector\FetchResult;
use Shimomo\AutoraceNewsCollector\SourceFailure;

/**
 * @author shimomo
 */
final class FetchResultTest extends TestCase
{
    /**
     * @return void
     */
    #[Test]
    public function defaultsToEmptyLists(): void
    {
        $result = new FetchResult();

        self::assertSame([], $result->articles);
        self::assertSame([], $result->failures);
    }

    /**
     * @return void
     */
    #[Test]
    public function holdsArticlesAndFailures(): void
    {
        $article = new Article('a', 'b', 'c', 'd', 'e', [], new DateTimeImmutable());
        $failure = new SourceFailure('a', null, 'x');

        $result = new FetchResult([$article], [$failure]);
        $collected = new CollectResult([$article], [$failure]);

        self::assertSame([$article], $result->articles);
        self::assertSame([$failure], $result->failures);
        self::assertSame([$article], $collected->articles);
        self::assertSame([$failure], $collected->failures);
    }
}
