<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Feed;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * RSS / Atom を DOMXPath で読む薄い包み。名前空間を登録済みにしておく。
 *
 * @author shimomo
 */
final class XmlDocument
{
    private const array NAMESPACES = [
        'atom' => 'http://www.w3.org/2005/Atom',
        'yt' => 'http://www.youtube.com/xml/schemas/2015',
        'media' => 'http://search.yahoo.com/mrss/',
        'content' => 'http://purl.org/rss/1.0/modules/content/',
        'dc' => 'http://purl.org/dc/elements/1.1/',
    ];

    /**
     * @param \DOMXPath $xpath
     */
    private function __construct(
        private DOMXPath $xpath,
    ) {
    }

    /**
     * @param string $xml
     * @return self
     */
    public static function fromString(string $xml): self
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // NOCDATA で CDATA をテキストに畳む。autorace.jp の description は CDATA 包みの HTML
        $loaded = $xml !== '' && $document->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            $detail = isset($errors[0]) ? trim($errors[0]->message) : 'empty document';

            throw new FeedException('Failed to parse XML: ' . $detail);
        }

        $xpath = new DOMXPath($document);

        foreach (self::NAMESPACES as $prefix => $uri) {
            $xpath->registerNamespace($prefix, $uri);
        }

        return new self($xpath);
    }

    /**
     * @param string $xpath
     * @param ?\DOMNode $context
     * @return list<\DOMElement>
     */
    public function elements(string $xpath, ?DOMNode $context = null): array
    {
        // 不正な式で DOMXPath が出す PHP 警告は例外に置き換えるので黙らせる
        set_error_handler(static fn(): bool => true);

        try {
            $list = $this->xpath->query($xpath, $context);
        } finally {
            restore_error_handler();
        }

        if ($list === false) {
            throw new FeedException(sprintf('Invalid XPath expression: %s', $xpath));
        }

        $elements = [];

        foreach ($list as $node) {
            if ($node instanceof DOMElement) {
                $elements[] = $node;
            }
        }

        return $elements;
    }

    /**
     * @param string $xpath
     * @param ?\DOMNode $context
     * @return ?string 先頭要素の trim 済みテキスト。要素が無ければ null
     */
    public function text(string $xpath, ?DOMNode $context = null): ?string
    {
        $element = $this->elements($xpath, $context)[0] ?? null;

        return $element === null ? null : trim($element->textContent);
    }

    /**
     * @param string $xpath
     * @param ?\DOMNode $context
     * @return list<string>
     */
    public function texts(string $xpath, ?DOMNode $context = null): array
    {
        $texts = [];

        foreach ($this->elements($xpath, $context) as $element) {
            $texts[] = trim($element->textContent);
        }

        return $texts;
    }

    /**
     * @param string $xpath
     * @param string $name
     * @param ?\DOMNode $context
     * @return ?string 先頭要素の属性値。要素も属性も無ければ null
     */
    public function attr(string $xpath, string $name, ?DOMNode $context = null): ?string
    {
        $element = $this->elements($xpath, $context)[0] ?? null;

        if ($element === null || !$element->hasAttribute($name)) {
            return null;
        }

        return $element->getAttribute($name);
    }
}
