<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Tests\Html;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shimomo\AutoraceNewsCollector\Html\BodyNormalizer;

/**
 * @author shimomo
 */
final class BodyNormalizerTest extends TestCase
{
    private const string BASE = 'https://autorace.jp/news/2026/10/12/038810/';

    /**
     * @return void
     */
    #[Test]
    public function removesScriptsStylesFramesAndComments(): void
    {
        $body = (new BodyNormalizer())->normalize('<p>A</p><script>alert(1)</script><style>p{}</style><noscript>n</noscript><iframe src="https://x.test/"></iframe><!-- c --><p>B</p>', self::BASE);

        self::assertSame('<p>A</p><p>B</p>', $body->html);
        self::assertSame("A\nB", $body->text);
        self::assertNull($body->thumbnailUrl);
    }

    /**
     * @return void
     */
    #[Test]
    public function absolutizesImageAndLinkUrls(): void
    {
        $body = (new BodyNormalizer())->normalize('<img src="/g2/img-05.jpg" class="mb-2" /><p><a href="/g2/20261008/">特設サイトへ</a></p>', self::BASE);

        self::assertSame('<img src="https://autorace.jp/g2/img-05.jpg" class="mb-2"><p><a href="https://autorace.jp/g2/20261008/">特設サイトへ</a></p>', $body->html);
        self::assertSame('特設サイトへ', $body->text);
        self::assertSame('https://autorace.jp/g2/img-05.jpg', $body->thumbnailUrl);
    }

    /**
     * @return void
     */
    #[Test]
    public function keepsJapaneseTextAndEntitiesIntact(): void
    {
        $body = (new BodyNormalizer())->normalize('<p>佐藤励「テスト」&amp;</p>', self::BASE);

        self::assertSame('<p>佐藤励「テスト」&amp;</p>', $body->html);
        self::assertSame('佐藤励「テスト」&', $body->text);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function emptyBodies(): iterable
    {
        yield 'empty string' => [''];
        yield 'whitespace' => ["  \n\t"];
        yield 'empty paragraph' => ['<p></p>'];
        yield 'script only' => ['<script>x</script>'];
    }

    /**
     * @param string $html
     * @return void
     */
    #[Test]
    #[DataProvider('emptyBodies')]
    public function returnsNullsForEmptyBodies(string $html): void
    {
        $body = (new BodyNormalizer())->normalize($html, self::BASE);

        self::assertNull($body->html);
        self::assertNull($body->text);
        self::assertNull($body->thumbnailUrl);
    }

    /**
     * @return void
     */
    #[Test]
    public function keepsThumbnailForImageOnlyBodies(): void
    {
        $body = (new BodyNormalizer())->normalize('<img src="a.jpg">', self::BASE);

        self::assertNull($body->html);
        self::assertNull($body->text);
        self::assertSame(self::BASE . 'a.jpg', $body->thumbnailUrl);
    }

    /**
     * @return void
     */
    #[Test]
    public function usesTheFirstImageAsThumbnail(): void
    {
        $body = (new BodyNormalizer())->normalize('<p>x</p><img src="/1.jpg"><img src="/2.jpg">', self::BASE);

        self::assertSame('https://autorace.jp/1.jpg', $body->thumbnailUrl);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function textConversions(): iterable
    {
        yield 'entities and nbsp' => ['<p>A &amp; B&nbsp;C</p>', 'A & B C'];
        yield 'br' => ['<div>D<br>E</div>', "D\nE"];
        yield 'full-width space is kept' => ['<p>全角　空白</p>', '全角　空白'];
        yield 'blank paragraphs collapse to one empty line' => ['<p>A</p><p></p><p></p><p>B</p>', "A\n\nB"];
        yield 'list items' => ['<ul><li>一</li><li>二</li></ul>', "一\n二"];
        yield 'spaces collapse' => ['<p>  many    spaces  </p>', 'many spaces'];
        yield 'crlf' => ["<p>line1\r\nline2</p>", "line1\nline2"];
        yield 'headings and divs' => ['<h2>見出し</h2><div>本文</div>', "見出し\n本文"];
        yield 'table cells are separated by a space' => ['<table><tr><th>部品交換</th><th>正式名称</th></tr><tr><td>A</td><td>B</td></tr></table>', "部品交換 正式名称\nA B"];
    }

    /**
     * @param string $html
     * @param string $expected
     * @return void
     */
    #[Test]
    #[DataProvider('textConversions')]
    public function convertsHtmlToText(string $html, string $expected): void
    {
        self::assertSame($expected, BodyNormalizer::toText($html));
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function urls(): iterable
    {
        yield 'absolute' => ['https://x.test/a.jpg', self::BASE, 'https://x.test/a.jpg'];
        yield 'root relative' => ['/g2/img.jpg', self::BASE, 'https://autorace.jp/g2/img.jpg'];
        yield 'relative' => ['img.jpg', self::BASE, 'https://autorace.jp/news/2026/10/12/038810/img.jpg'];
        yield 'parent' => ['../img.jpg', self::BASE, 'https://autorace.jp/news/2026/10/12/img.jpg'];
        yield 'dot' => ['./img.jpg', self::BASE, 'https://autorace.jp/news/2026/10/12/038810/img.jpg'];
        yield 'protocol relative' => ['//cdn.test/x.png', self::BASE, 'https://cdn.test/x.png'];
        yield 'query only' => ['?page=2', 'https://x.test/list/', 'https://x.test/list/?page=2'];
        yield 'fragment only' => ['#top', 'https://x.test/a/b.html', 'https://x.test/a/b.html#top'];
        yield 'data uri' => ['data:image/png;base64,AAAA', self::BASE, 'data:image/png;base64,AAAA'];
        yield 'mailto' => ['mailto:a@b.test', self::BASE, 'mailto:a@b.test'];
        yield 'base without path' => ['img.jpg', 'https://x.test', 'https://x.test/img.jpg'];
        yield 'base with port' => ['/a', 'http://x.test:8080/b/', 'http://x.test:8080/a'];
        yield 'relative file from file' => ['c.html', 'https://x.test/a/b.html', 'https://x.test/a/c.html'];
    }

    /**
     * @param string $url
     * @param string $base
     * @param string $expected
     * @return void
     */
    #[Test]
    #[DataProvider('urls')]
    public function absolutizesUrls(string $url, string $base, string $expected): void
    {
        self::assertSame($expected, BodyNormalizer::absolutize($url, $base));
    }
}
