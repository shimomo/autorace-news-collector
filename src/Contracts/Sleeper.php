<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Contracts;

/**
 * リクエスト間の待機。テストでは待たない実装に差し替える。
 *
 * @author shimomo
 */
interface Sleeper
{
    /**
     * @param float $seconds
     * @return void
     */
    public function sleep(float $seconds): void;
}
