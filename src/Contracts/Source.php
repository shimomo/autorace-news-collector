<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Contracts;

use DateTimeImmutable;
use Shimomo\AutoraceNewsCollector\FetchResult;

/**
 * 記事の収集元。
 *
 * @author shimomo
 */
interface Source
{
    /**
     * @return string ソースキー（空不可）
     */
    public function key(): string;

    /**
     * @return string 表示名（空不可）
     */
    public function name(): string;

    /**
     * since を渡すと publishedAt >= since の記事だけ返す。
     * フィード全体の取得・解析に失敗したときは例外を投げてよい（Collector が failures へ変換する）。
     * 1件単位の失敗は FetchResult::$failures に載せて続行する。
     *
     * @param ?\DateTimeImmutable $since
     * @return \Shimomo\AutoraceNewsCollector\FetchResult
     */
    public function fetch(?DateTimeImmutable $since): FetchResult;
}
