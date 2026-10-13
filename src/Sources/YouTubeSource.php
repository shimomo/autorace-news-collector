<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Sources;

use DateTimeImmutable;
use DateTimeZone;
use Shimomo\AutoraceNewsCollector\Article;
use Shimomo\AutoraceNewsCollector\Contracts\Source as SourceContract;
use Shimomo\AutoraceNewsCollector\Feed\XmlDocument;
use Shimomo\AutoraceNewsCollector\FetchResult;
use Shimomo\AutoraceNewsCollector\Http\HttpFetcher;
use Shimomo\AutoraceNewsCollector\SourceFailure;

/**
 * オートレース公式 YouTube チャンネルの Atom フィード。動画説明文を bodyText に入れる。
 *
 * @author shimomo
 */
final class YouTubeSource implements SourceContract
{
    public const string CHANNEL_ID = 'UCJAPqcMNYk3HMknRuPMNvKA';

    /**
     * @param \Shimomo\AutoraceNewsCollector\Http\HttpFetcher $fetcher
     * @param string $channelId
     */
    public function __construct(
        private HttpFetcher $fetcher,
        private string $channelId = self::CHANNEL_ID,
    ) {
    }

    /**
     * @return string
     */
    #[\Override]
    public function key(): string
    {
        return 'youtube';
    }

    /**
     * @return string
     */
    #[\Override]
    public function name(): string
    {
        return 'オートレース公式 YouTube';
    }

    /**
     * @return string
     */
    public function feedUrl(): string
    {
        return 'https://www.youtube.com/feeds/videos.xml?channel_id=' . $this->channelId;
    }

    /**
     * @param ?\DateTimeImmutable $since
     * @return \Shimomo\AutoraceNewsCollector\FetchResult
     */
    #[\Override]
    public function fetch(?DateTimeImmutable $since): FetchResult
    {
        $document = XmlDocument::fromString($this->fetcher->get($this->feedUrl())->body);
        $timezone = new DateTimeZone('Asia/Tokyo');
        $articles = [];
        $failures = [];

        foreach ($document->elements('/atom:feed/atom:entry') as $entry) {
            $videoId = $document->text('yt:videoId', $entry);
            $title = $document->text('atom:title', $entry);
            $published = $document->text('atom:published', $entry);
            $url = $videoId === null || $videoId === '' ? null : 'https://www.youtube.com/watch?v=' . $videoId;

            if ($url === null || $videoId === null || $title === null || $title === '' || $published === null) {
                $failures[] = new SourceFailure($this->key(), $url, 'Feed entry is missing videoId, title, or published.');

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

            $description = $document->text('media:group/media:description', $entry);
            $thumbnail = $document->attr('media:group/media:thumbnail', 'url', $entry);

            $articles[] = new Article(
                $this->key(),
                $this->name(),
                $videoId,
                $url,
                $title,
                [],
                $publishedAt,
                null,
                null,
                $description === null || $description === '' ? null : $description,
                $thumbnail === null || $thumbnail === '' ? null : $thumbnail,
            );
        }

        return new FetchResult($articles, $failures);
    }
}
