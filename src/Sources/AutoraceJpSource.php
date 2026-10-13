<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Sources;

use DateTimeImmutable;
use DateTimeZone;
use Shimomo\AutoraceNewsCollector\Article;
use Shimomo\AutoraceNewsCollector\Contracts\Source as SourceContract;
use Shimomo\AutoraceNewsCollector\Feed\XmlDocument;
use Shimomo\AutoraceNewsCollector\FetchResult;
use Shimomo\AutoraceNewsCollector\Html\BodyNormalizer;
use Shimomo\AutoraceNewsCollector\Http\HttpFetcher;
use Shimomo\AutoraceNewsCollector\SourceFailure;

/**
 * 公式サイト AUTORACE.JP の RSS 2.0。description に本文 HTML が丸ごと入っているので記事ページは取りに行かない。
 *
 * @author shimomo
 */
final class AutoraceJpSource implements SourceContract
{
    public const string FEED_URL = 'https://autorace.jp/rss.xml';

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
        return 'autorace.jp';
    }

    /**
     * @return string
     */
    #[\Override]
    public function name(): string
    {
        return 'AUTORACE.JP';
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

        foreach ($document->elements('/rss/channel/item') as $item) {
            $link = $document->text('link', $item);
            $title = $document->text('title', $item);
            $pubDate = $document->text('pubDate', $item);

            if ($link === null || $link === '' || $title === null || $title === '' || $pubDate === null) {
                $failures[] = new SourceFailure($this->key(), $link, 'Feed item is missing link, title, or pubDate.');

                continue;
            }

            // 記事 URL は /news/YYYY/MM/DD/<番号>/ の形。末尾の番号がサイト内で一意
            if (preg_match('~/(\d+)/?$~', $link, $matches) !== 1) {
                $failures[] = new SourceFailure($this->key(), $link, 'Could not derive the article id from the link.');

                continue;
            }

            $publishedAt = DateTimeImmutable::createFromFormat('!' . DATE_RSS, $pubDate);

            if ($publishedAt === false) {
                $failures[] = new SourceFailure($this->key(), $link, sprintf('Unparseable pubDate "%s".', $pubDate));

                continue;
            }

            $publishedAt = $publishedAt->setTimezone($timezone);

            if ($since !== null && $publishedAt < $since) {
                continue;
            }

            $body = $this->normalizer->normalize($document->text('description', $item) ?? '', $link);

            $articles[] = new Article(
                $this->key(),
                $this->name(),
                $matches[1],
                $link,
                $title,
                $document->texts('category', $item),
                $publishedAt,
                null,
                $body->html,
                $body->text,
                $body->thumbnailUrl,
            );
        }

        return new FetchResult($articles, $failures);
    }
}
