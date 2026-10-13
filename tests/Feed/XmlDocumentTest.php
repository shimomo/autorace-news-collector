<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Tests\Feed;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shimomo\AutoraceNewsCollector\Feed\FeedException;
use Shimomo\AutoraceNewsCollector\Feed\XmlDocument;

/**
 * @author shimomo
 */
final class XmlDocumentTest extends TestCase
{
    private const string RSS = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0">
          <channel>
            <item>
              <title>一件目</title>
              <link>https://example.test/1/</link>
              <description><![CDATA[<p>本文 &amp; 画像</p>]]></description>
              <category>ニュース</category>
              <category>飯塚</category>
            </item>
            <item>
              <title>二件目</title>
            </item>
          </channel>
        </rss>
        XML;

    private const string ATOM = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <feed xmlns="http://www.w3.org/2005/Atom" xmlns:yt="http://www.youtube.com/xml/schemas/2015" xmlns:media="http://search.yahoo.com/mrss/">
          <entry>
            <yt:videoId>abc123</yt:videoId>
            <link rel="alternate" href="https://www.youtube.com/watch?v=abc123"/>
            <media:group>
              <media:thumbnail url="https://i.test/abc123.jpg" width="480"/>
            </media:group>
          </entry>
        </feed>
        XML;

    /**
     * @return void
     */
    #[Test]
    public function readsRssItemsWithCdataFlattened(): void
    {
        $document = XmlDocument::fromString(self::RSS);
        $items = $document->elements('/rss/channel/item');

        self::assertCount(2, $items);
        self::assertSame('一件目', $document->text('title', $items[0]));
        self::assertSame('https://example.test/1/', $document->text('link', $items[0]));
        self::assertSame('<p>本文 &amp; 画像</p>', $document->text('description', $items[0]));
        self::assertSame(['ニュース', '飯塚'], $document->texts('category', $items[0]));
        self::assertNull($document->text('link', $items[1]));
        self::assertSame([], $document->texts('category', $items[1]));
    }

    /**
     * @return void
     */
    #[Test]
    public function readsNamespacedAtomFeeds(): void
    {
        $document = XmlDocument::fromString(self::ATOM);
        $entry = $document->elements('/atom:feed/atom:entry')[0];

        self::assertSame('abc123', $document->text('yt:videoId', $entry));
        self::assertSame('https://www.youtube.com/watch?v=abc123', $document->attr('atom:link[@rel="alternate"]', 'href', $entry));
        self::assertSame('https://i.test/abc123.jpg', $document->attr('media:group/media:thumbnail', 'url', $entry));
        self::assertNull($document->attr('media:group/media:thumbnail', 'missing', $entry));
        self::assertNull($document->attr('atom:link[@rel="enclosure"]', 'href', $entry));
    }

    /**
     * @return void
     */
    #[Test]
    public function rejectsMalformedXml(): void
    {
        $this->expectException(FeedException::class);
        $this->expectExceptionMessageIsOrContains('Failed to parse XML');

        XmlDocument::fromString('<rss><channel></rss>');
    }

    /**
     * @return void
     */
    #[Test]
    public function rejectsEmptyInput(): void
    {
        $this->expectException(FeedException::class);

        XmlDocument::fromString('');
    }

    /**
     * @return void
     */
    #[Test]
    public function rejectsInvalidXPath(): void
    {
        $this->expectException(FeedException::class);
        $this->expectExceptionMessageIs('Invalid XPath expression: //[');

        XmlDocument::fromString(self::RSS)->elements('//[');
    }
}
