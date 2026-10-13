<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Html;

/**
 * 整形後の本文。
 *
 * @author shimomo
 */
final readonly class NormalizedBody
{
    /**
     * @param ?string $html 整形済み HTML。テキストが空なら null
     * @param ?string $text プレーンテキスト。空なら null
     * @param ?string $thumbnailUrl 先頭画像の絶対 URL。無ければ null
     */
    public function __construct(
        public ?string $html,
        public ?string $text,
        public ?string $thumbnailUrl,
    ) {
    }
}
