<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Tests\Support;

use RuntimeException;

/**
 * tests/fixtures/ の実レスポンスを読む。
 *
 * @author shimomo
 */
final class Fixtures
{
    /**
     * @param string $path tests/fixtures/ からの相対パス
     * @return string
     */
    public static function read(string $path): string
    {
        $content = file_get_contents(__DIR__ . '/../fixtures/' . $path);

        if ($content === false) {
            throw new RuntimeException(sprintf('Fixture "%s" could not be read.', $path));
        }

        return $content;
    }
}
