<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Tests\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shimomo\AutoraceNewsCollector\Http\NativeSleeper;

/**
 * @author shimomo
 */
final class NativeSleeperTest extends TestCase
{
    /**
     * @return void
     */
    #[Test]
    public function sleepsRoughlyForTheGivenSeconds(): void
    {
        $started = hrtime(true);

        (new NativeSleeper())->sleep(0.02);

        self::assertGreaterThanOrEqual(20_000_000, hrtime(true) - $started);
    }

    /**
     * @return void
     */
    #[Test]
    public function returnsImmediatelyForZeroOrNegative(): void
    {
        $started = hrtime(true);

        (new NativeSleeper())->sleep(0.0);
        (new NativeSleeper())->sleep(-1.0);

        self::assertLessThan(10_000_000, hrtime(true) - $started);
    }
}
