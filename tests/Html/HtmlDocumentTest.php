<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Tests\Html;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shimomo\AutoraceNewsCollector\Html\HtmlDocument;

/**
 * @author shimomo
 */
final class HtmlDocumentTest extends TestCase
{
    /**
     * @return void
     */
    #[Test]
    public function readsFullDocumentsAsUtf8(): void
    {
        $document = HtmlDocument::fromString('<!DOCTYPE html><html><head><title>見出し</title></head><body><section id="main"><p class="lead x">本文 &amp; 続き</p></section></body></html>');

        $lead = $document->first('//section[@id="main"]/p');

        self::assertNotNull($lead);
        self::assertSame('本文 & 続き', $lead->textContent);
        self::assertSame('<p class="lead x">本文 &amp; 続き</p>', $document->outerHtml($lead));
    }

    /**
     * libxml は文書内の <meta charset> を XML 宣言より優先するので、食い違う宣言があっても UTF-8 で読めることを固定する。
     *
     * @return void
     */
    #[Test]
    public function readsUtf8EvenWhenTheDocumentDeclaresAnotherCharset(): void
    {
        $document = HtmlDocument::fromString('<!DOCTYPE html><html><head><meta charset="Shift_JIS"><title>t</title></head><body><section id="main"><p>本文</p></section></body></html>');

        self::assertSame('本文', $document->first('//section[@id="main"]/p')?->textContent);
    }

    /**
     * @return void
     */
    #[Test]
    public function fragmentRootKeepsMarkupAndMultipleTopLevelNodes(): void
    {
        $document = HtmlDocument::fromFragment("<img src=\"/a.jpg\" class=\"mb-2\" />\n<p>一</p><p>二</p>");

        $root = $document->root();

        self::assertNotNull($root);
        self::assertSame("<img src=\"/a.jpg\" class=\"mb-2\">\n<p>一</p><p>二</p>", $document->innerHtml($root));
    }

    /**
     * @return void
     */
    #[Test]
    public function nodesIncludeCommentsWhileElementsFilterThem(): void
    {
        $document = HtmlDocument::fromFragment('<p>a</p><!-- c --><p>b</p>');
        $root = $document->root();

        self::assertNotNull($root);
        self::assertCount(3, $document->nodes('./node()', $root));
        self::assertCount(2, $document->elements('./node()', $root));
        self::assertSame('a', $document->first('./p', $root)?->textContent);
    }

    /**
     * @return void
     */
    #[Test]
    public function firstReturnsNullWhenNothingMatches(): void
    {
        $document = HtmlDocument::fromFragment('<p>a</p>');

        self::assertNull($document->first('//h1'));
        self::assertNull(HtmlDocument::fromString('<p>a</p>')->root());
    }

    /**
     * @return void
     */
    #[Test]
    public function rejectsInvalidXPath(): void
    {
        $this->expectException(InvalidArgumentException::class);

        HtmlDocument::fromFragment('<p>a</p>')->nodes('//[');
    }
}
