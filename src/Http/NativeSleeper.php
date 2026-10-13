<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Http;

use Shimomo\AutoraceNewsCollector\Contracts\Sleeper as SleeperContract;

/**
 * usleep で実際に待つ Sleeper。
 *
 * @author shimomo
 */
final class NativeSleeper implements SleeperContract
{
    /**
     * @param float $seconds
     * @return void
     */
    #[\Override]
    public function sleep(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        usleep((int) round($seconds * 1_000_000.0));
    }
}
