<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Html;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use InvalidArgumentException;

/**
 * DOMDocument ＋ DOMXPath の薄い包み。HTML 文書も断片も同じ経路で読む。
 *
 * @author shimomo
 */
final class HtmlDocument
{
    private const string ROOT_ID = '__root';

    /**
     * @param \DOMDocument $document
     * @param \DOMXPath $xpath
     */
    private function __construct(
        private DOMDocument $document,
        private DOMXPath $xpath,
    ) {
    }

    /**
     * libxml の HTML パーサは文字コードの既定が ISO-8859-1 なので UTF-8 を明示する。二段構えにしている:
     * XML 宣言は古い libxml（2.9 系）が既定として読むが、文書内の <meta charset> があればそちらが勝ち、
     * 新しい libxml（2.12 以降）は XML 宣言を見ない。先頭に置いた <meta charset="UTF-8"> はどちらでも
     * 最初に見つかる宣言として効く。こうすると saveHTML の出力も数値実体参照にならず UTF-8 のまま出る。
     * HTML5 の要素（section, nav など）は libxml にとって未知だが、警告を抑えれば通常の要素として読める。
     *
     * @param string $html
     * @return self
     */
    public static function fromString(string $html): self
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"?><meta charset="UTF-8">' . $html, LIBXML_NONET | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new self($document, new DOMXPath($document));
    }

    /**
     * 断片を <div id="__root"> で包んで読む。root() で取り出す。
     *
     * @param string $html
     * @return self
     */
    public static function fromFragment(string $html): self
    {
        return self::fromString('<div id="' . self::ROOT_ID . '">' . $html . '</div>');
    }

    /**
     * @return ?\DOMElement fromFragment() で包んだ要素。fromString() で読んだ文書では null
     */
    public function root(): ?DOMElement
    {
        return $this->first('//div[@id="' . self::ROOT_ID . '"]');
    }

    /**
     * @param string $xpath
     * @param ?\DOMNode $context
     * @return list<\DOMNode>
     */
    public function nodes(string $xpath, ?DOMNode $context = null): array
    {
        // 不正な式で DOMXPath が出す PHP 警告は例外に置き換えるので黙らせる
        set_error_handler(static fn(): bool => true);

        try {
            $list = $this->xpath->query($xpath, $context);
        } finally {
            restore_error_handler();
        }

        if ($list === false) {
            throw new InvalidArgumentException(sprintf('Invalid XPath expression: %s', $xpath));
        }

        $nodes = [];

        foreach ($list as $node) {
            // 名前空間ノード（DOMNameSpaceNode）は DOMNode ではないので除く
            if ($node instanceof DOMNode) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /**
     * @param string $xpath
     * @param ?\DOMNode $context
     * @return list<\DOMElement>
     */
    public function elements(string $xpath, ?DOMNode $context = null): array
    {
        $elements = [];

        foreach ($this->nodes($xpath, $context) as $node) {
            if ($node instanceof DOMElement) {
                $elements[] = $node;
            }
        }

        return $elements;
    }

    /**
     * @param string $xpath
     * @param ?\DOMNode $context
     * @return ?\DOMElement
     */
    public function first(string $xpath, ?DOMNode $context = null): ?DOMElement
    {
        return $this->elements($xpath, $context)[0] ?? null;
    }

    /**
     * @param \DOMNode $node
     * @return string
     */
    public function innerHtml(DOMNode $node): string
    {
        $html = '';

        foreach ($node->childNodes as $child) {
            $html .= $this->outerHtml($child);
        }

        return $html;
    }

    /**
     * @param \DOMNode $node
     * @return string
     */
    public function outerHtml(DOMNode $node): string
    {
        $html = $this->document->saveHTML($node);

        return $html === false ? '' : $html;
    }
}
