<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Sources;

/**
 * WordPress 製のレース場公式サイト1つ分の設定。
 *
 * @author shimomo
 */
final readonly class WordPressSite
{
    /**
     * @param string $key ソースキー
     * @param string $name 表示名
     * @param string $baseUrl 末尾スラッシュなし
     * @param string $restType wp/v2 直下のルート名（posts / news / interview / newsinfo）
     * @param list<string> $fixedCategories 分類の先頭に固定で足すラベル
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $baseUrl,
        public string $restType,
        public array $fixedCategories = [],
    ) {
    }

    /**
     * @return self
     */
    public static function kawaguchiNews(): self
    {
        return new self('kawaguchi', '川口オートレース', 'https://www.kawaguchiauto.jp', 'news');
    }

    /**
     * interview は分類を持たないので固定ラベルで区別する。
     *
     * @return self
     */
    public static function kawaguchiInterview(): self
    {
        return new self('kawaguchi', '川口オートレース', 'https://www.kawaguchiauto.jp', 'interview', ['選手インタビュー']);
    }

    /**
     * @return self
     */
    public static function isesaki(): self
    {
        return new self('isesaki', '伊勢崎オートレース', 'https://www.isesaki-auto.jp', 'posts');
    }

    /**
     * newsinfo の区分（news / race / event）は REST に露出していないので分類は取れない。
     *
     * @return self
     */
    public static function hamamatsu(): self
    {
        return new self('hamamatsu', '浜松オート', 'https://www.hamamatsuauto.jp', 'newsinfo');
    }

    /**
     * @return self
     */
    public static function sanyo(): self
    {
        return new self('sanyo', '山陽オート', 'https://sanyoauto.jp', 'posts');
    }

    /**
     * @return string
     */
    public function endpoint(): string
    {
        return $this->baseUrl . '/wp-json/wp/v2/' . $this->restType;
    }
}
