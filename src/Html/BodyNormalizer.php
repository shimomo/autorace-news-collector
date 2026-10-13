<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Html;

/**
 * 本文 HTML の「保存用の掃除」。公開向けのサニタイズではないので、属性の白リスト化はしない。
 *
 * @author shimomo
 */
final class BodyNormalizer
{
    private const string REMOVE_XPATH = './/script|.//style|.//noscript|.//iframe|.//comment()';

    /**
     * @param string $html 本文 HTML 断片
     * @param string $baseUrl 相対 URL の解決に使う記事 URL
     * @return \Shimomo\AutoraceNewsCollector\Html\NormalizedBody
     */
    public function normalize(string $html, string $baseUrl): NormalizedBody
    {
        if (trim($html) === '') {
            return new NormalizedBody(null, null, null);
        }

        $document = HtmlDocument::fromFragment($html);
        $root = $document->root();

        if ($root === null) {
            return new NormalizedBody(null, null, null);
        }

        // XPath の結果は静的なスナップショットなので、回しながら消して問題ない
        foreach ($document->nodes(self::REMOVE_XPATH, $root) as $node) {
            $node->parentNode?->removeChild($node);
        }

        foreach ($document->elements('.//img[@src]', $root) as $image) {
            $image->setAttribute('src', self::absolutize($image->getAttribute('src'), $baseUrl));
        }

        foreach ($document->elements('.//a[@href]', $root) as $anchor) {
            $anchor->setAttribute('href', self::absolutize($anchor->getAttribute('href'), $baseUrl));
        }

        $inner = trim($document->innerHtml($root));
        $text = self::toText($inner);

        $image = $document->first('.//img[@src]', $root);
        $thumbnail = $image?->getAttribute('src');
        $thumbnail = $thumbnail === null || $thumbnail === '' ? null : $thumbnail;

        // 画像だけの投稿でもサムネイルは残す。本文としては空なので html / text は null
        if ($text === '') {
            return new NormalizedBody(null, null, $thumbnail);
        }

        return new NormalizedBody($inner, $text, $thumbnail);
    }

    /**
     * ブロック要素と br の境目を改行に、表のセルの境目を空白にしてタグを落とす。全角空白は意図して使われるので畳まない。
     *
     * @param string $html
     * @return string
     */
    public static function toText(string $html): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $html);
        $text = (string) preg_replace('~<br\s*/?>~i', "\n", $text);
        // 隣り合うセルがくっつかないよう、セルの終わりはタブにしておく（後で1スペースに畳まれる）
        $text = (string) preg_replace('~</(?:td|th)>~i', "\t", $text);
        $text = (string) preg_replace('~</(?:p|div|li|h[1-6]|tr|blockquote|section|article|ul|ol|table|dl|dt|dd|figure|figcaption|pre)>~i', "\n", $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = (string) preg_replace('~[ \t]+~', ' ', $text);
        $text = implode("\n", array_map(trim(...), explode("\n", $text)));
        $text = (string) preg_replace("~\n{3,}~", "\n\n", $text);

        return trim($text);
    }

    /**
     * RFC 3986 の参照解決の簡易版。スキーム付き（data:, mailto: 含む）はそのまま返す。
     *
     * @param string $url
     * @param string $baseUrl
     * @return string
     */
    public static function absolutize(string $url, string $baseUrl): string
    {
        $url = trim($url);

        if ($url === '' || preg_match('~^[a-z][a-z0-9+.-]*:~i', $url) === 1) {
            return $url;
        }

        $base = parse_url($baseUrl);

        if (!is_array($base) || !isset($base['scheme'], $base['host'])) {
            return $url;
        }

        if (str_starts_with($url, '//')) {
            return $base['scheme'] . ':' . $url;
        }

        $origin = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');
        $path = $base['path'] ?? '/';

        if ($path === '') {
            $path = '/';
        }

        if (str_starts_with($url, '#')) {
            return $origin . $path . (isset($base['query']) ? '?' . $base['query'] : '') . $url;
        }

        if (str_starts_with($url, '?')) {
            return $origin . $path . $url;
        }

        if (!str_starts_with($url, '/')) {
            $url = substr($path, 0, (int) strrpos($path, '/') + 1) . $url;
        }

        return $origin . self::removeDotSegments($url);
    }

    /**
     * @param string $path クエリ・フラグメント付きでもよい
     * @return string
     */
    private static function removeDotSegments(string $path): string
    {
        $suffix = '';

        if (preg_match('~^([^?#]*)([?#].*)$~s', $path, $matches) === 1) {
            $path = $matches[1];
            $suffix = $matches[2];
        }

        $segments = explode('/', $path);
        $last = end($segments);
        $output = [];

        foreach ($segments as $segment) {
            if ($segment === '.') {
                continue;
            }

            if ($segment === '..') {
                // 先頭の空要素（ルート）より上へは行かせない
                if (count($output) > 1) {
                    array_pop($output);
                }

                continue;
            }

            $output[] = $segment;
        }

        // 末尾が . や .. のときはディレクトリを指すので末尾のスラッシュを残す
        if ($last === '.' || $last === '..') {
            $output[] = '';
        }

        return implode('/', $output) . $suffix;
    }
}
