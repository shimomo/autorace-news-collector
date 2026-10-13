<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Sources;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Shimomo\AutoraceNewsCollector\Article;
use Shimomo\AutoraceNewsCollector\Contracts\Source as SourceContract;
use Shimomo\AutoraceNewsCollector\Feed\XmlDocument;
use Shimomo\AutoraceNewsCollector\FetchResult;
use Shimomo\AutoraceNewsCollector\Html\BodyNormalizer;
use Shimomo\AutoraceNewsCollector\Html\HtmlDocument;
use Shimomo\AutoraceNewsCollector\Html\NormalizedBody;
use Shimomo\AutoraceNewsCollector\Http\HttpFetcher;
use Shimomo\AutoraceNewsCollector\SourceFailure;

/**
 * 日刊スポーツのオート Atom。要約しか載らないので、since で残った記事だけ記事ページを取って本文を抜く。
 *
 * Atom は最新10件しか無い。SG 開催日は1日10本を超えるので、1〜2時間おきに回す前提。
 *
 * @author shimomo
 */
final class NikkanSportsSource implements SourceContract
{
    public const string FEED_URL = 'https://www.nikkansports.com/public_race/auto/atom.xml';

    private const string BODY_XPATH = '//section[@id="articleMain"]//div[@id="news"]';

    private const string PROLOGUE_XPATH = '//section[@id="articleMain"]//p[contains(concat(" ", normalize-space(@class), " "), " prologue ")]';

    /**
     * @param \Shimomo\AutoraceNewsCollector\Http\HttpFetcher $fetcher
     * @param \Shimomo\AutoraceNewsCollector\Html\BodyNormalizer $normalizer
     */
    public function __construct(
        private HttpFetcher $fetcher,
        private BodyNormalizer $normalizer = new BodyNormalizer(),
    ) {
    }

    /**
     * @return string
     */
    #[\Override]
    public function key(): string
    {
        return 'nikkansports';
    }

    /**
     * @return string
     */
    #[\Override]
    public function name(): string
    {
        return '日刊スポーツ';
    }

    /**
     * @param ?\DateTimeImmutable $since
     * @return \Shimomo\AutoraceNewsCollector\FetchResult
     */
    #[\Override]
    public function fetch(?DateTimeImmutable $since): FetchResult
    {
        $document = XmlDocument::fromString($this->fetcher->get(self::FEED_URL)->body);
        $timezone = new DateTimeZone('Asia/Tokyo');
        $articles = [];
        $failures = [];

        foreach ($document->elements('/atom:feed/atom:entry') as $entry) {
            $url = $document->attr('atom:link[@rel="alternate"]', 'href', $entry);
            $title = $document->text('atom:title', $entry);
            $published = $document->text('atom:published', $entry);

            if ($url === null || $url === '' || $title === null || $title === '' || $published === null) {
                $failures[] = new SourceFailure($this->key(), $url, 'Feed entry is missing link, title, or published.');

                continue;
            }

            // 記事 URL は /public_race/news/<記事番号>.html
            if (preg_match('~/(\d+)\.html$~', $url, $matches) !== 1) {
                $failures[] = new SourceFailure($this->key(), $url, 'Could not derive the article id from the link.');

                continue;
            }

            $publishedAt = DateTimeImmutable::createFromFormat('!' . DATE_ATOM, $published);

            if ($publishedAt === false) {
                $failures[] = new SourceFailure($this->key(), $url, sprintf('Unparseable published "%s".', $published));

                continue;
            }

            $publishedAt = $publishedAt->setTimezone($timezone);

            if ($since !== null && $publishedAt < $since) {
                continue;
            }

            $summary = $document->text('atom:summary', $entry);
            // 記事写真は enclosure に載る。記事ページの img は空画像で実体が style の背景なので使わない
            $thumbnail = $document->attr('atom:link[@rel="enclosure"]', 'href', $entry);
            $body = null;

            try {
                $body = $this->fetchBody($url);
            } catch (RuntimeException $e) {
                $failures[] = new SourceFailure($this->key(), $url, $e->getMessage(), $e);
            }

            $articles[] = new Article(
                $this->key(),
                $this->name(),
                $matches[1],
                $url,
                $title,
                [],
                $publishedAt,
                $summary === null || $summary === '' ? null : $summary,
                $body?->html,
                $body?->text,
                $thumbnail === null || $thumbnail === '' ? $body?->thumbnailUrl : $thumbnail,
            );
        }

        return new FetchResult($articles, $failures);
    }

    /**
     * 記事ページを取り、前書き（p.prologue）と本文（div#news）を連結して整形する。
     *
     * @param string $url
     * @return \Shimomo\AutoraceNewsCollector\Html\NormalizedBody
     */
    private function fetchBody(string $url): NormalizedBody
    {
        $document = HtmlDocument::fromString($this->fetcher->get($url)->body);
        $body = $document->first(self::BODY_XPATH);

        if ($body === null) {
            throw new RuntimeException('Article body (#articleMain div#news) was not found.');
        }

        $prologue = $document->first(self::PROLOGUE_XPATH);
        $html = ($prologue === null ? '' : $document->outerHtml($prologue)) . $document->innerHtml($body);

        return $this->normalizer->normalize($html, $url);
    }
}
