<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Sources;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Shimomo\AutoraceNewsCollector\Article;
use Shimomo\AutoraceNewsCollector\Contracts\Source as SourceContract;
use Shimomo\AutoraceNewsCollector\FetchResult;
use Shimomo\AutoraceNewsCollector\Html\BodyNormalizer;
use Shimomo\AutoraceNewsCollector\Http\HttpFetcher;
use Shimomo\AutoraceNewsCollector\SourceFailure;

/**
 * WordPress REST API（wp/v2）で記事を取る。4場を WordPressSite の差し替えで賄う。
 *
 * _embed でアイキャッチと分類名を同じレスポンスに含めるので、投稿1件につき追加リクエストは要らない。
 * JSON を歩く都合で mixed の代入が避けられないため、クラス全体で MixedAssignment を抑える。
 *
 * @psalm-suppress MixedAssignment
 *
 * @author shimomo
 */
final class WordPressSource implements SourceContract
{
    public const int PER_PAGE = 100;

    private const string EMBED = 'wp:featuredmedia,wp:term';

    private const string FIELDS = 'id,date_gmt,link,title,content,excerpt,_links,_embedded';

    /**
     * @param \Shimomo\AutoraceNewsCollector\Sources\WordPressSite $site
     * @param \Shimomo\AutoraceNewsCollector\Http\HttpFetcher $fetcher
     * @param int $maxPages since 指定時に辿るページ数の上限
     * @param \Shimomo\AutoraceNewsCollector\Html\BodyNormalizer $normalizer
     */
    public function __construct(
        private WordPressSite $site,
        private HttpFetcher $fetcher,
        private int $maxPages = 3,
        private BodyNormalizer $normalizer = new BodyNormalizer(),
    ) {
    }

    /**
     * @return string
     */
    #[\Override]
    public function key(): string
    {
        return $this->site->key;
    }

    /**
     * @return string
     */
    #[\Override]
    public function name(): string
    {
        return $this->site->name;
    }

    /**
     * @param int $page
     * @param ?\DateTimeImmutable $since
     * @return string
     */
    public function url(int $page, ?DateTimeImmutable $since): string
    {
        $query = [
            'per_page' => self::PER_PAGE,
            'page' => $page,
            'orderby' => 'date',
            'order' => 'desc',
            '_embed' => self::EMBED,
            '_fields' => self::FIELDS,
        ];

        if ($since !== null) {
            // WP の after は「より後」なので、>= に揃えるため1秒引く。
            // 時差は付けて渡す。WP は時差付きの値を自分のタイムゾーンへ換算してから post_date と比べる
            $query['after'] = $since->sub(new DateInterval('PT1S'))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:sP');
        }

        return $this->site->endpoint() . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param ?\DateTimeImmutable $since
     * @return \Shimomo\AutoraceNewsCollector\FetchResult
     */
    #[\Override]
    public function fetch(?DateTimeImmutable $since): FetchResult
    {
        $articles = [];
        $failures = [];
        $page = 1;

        do {
            $response = $this->fetcher->get($this->url($page, $since));

            foreach ($response->json() as $item) {
                if (!is_array($item)) {
                    $failures[] = new SourceFailure($this->key(), null, 'Post entry is not an object.');

                    continue;
                }

                $article = $this->toArticle($item);

                if ($article instanceof SourceFailure) {
                    $failures[] = $article;

                    continue;
                }

                // after で絞っていても自前で比べる。サイト側の時差解釈に依存しないため
                if ($since !== null && $article->publishedAt < $since) {
                    continue;
                }

                $articles[] = $article;
            }

            $totalPages = (int) ($response->header('X-WP-TotalPages') ?? '1');
            $page++;
        } while ($since !== null && $page <= $totalPages && $page <= $this->maxPages);

        return new FetchResult($articles, $failures);
    }

    /**
     * @param array<array-key, mixed> $item
     * @return \Shimomo\AutoraceNewsCollector\Article|\Shimomo\AutoraceNewsCollector\SourceFailure
     */
    private function toArticle(array $item): Article|SourceFailure
    {
        $id = self::intAt($item, 'id');
        $link = self::stringAt($item, 'link');
        $dateGmt = self::stringAt($item, 'date_gmt');
        $rawTitle = self::stringAt($item, 'title', 'rendered');

        if ($id === null || $link === null || $link === '' || $dateGmt === null || $rawTitle === null) {
            return new SourceFailure($this->key(), $link, 'Post is missing id, link, date_gmt, or title.');
        }

        // title.rendered は実体参照（&#8211; など）とタグを含み得る
        $title = trim(html_entity_decode(strip_tags($rawTitle), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($title === '') {
            return new SourceFailure($this->key(), $link, 'Post title is empty.');
        }

        $publishedAt = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $dateGmt, new DateTimeZone('UTC'));

        if ($publishedAt === false) {
            return new SourceFailure($this->key(), $link, sprintf('Unparseable date_gmt "%s".', $dateGmt));
        }

        $body = $this->normalizer->normalize(self::stringAt($item, 'content', 'rendered') ?? '', $link);
        $summary = BodyNormalizer::toText(self::stringAt($item, 'excerpt', 'rendered') ?? '');
        $featured = self::stringAt($item, '_embedded', 'wp:featuredmedia', 0, 'source_url');

        return new Article(
            $this->key(),
            $this->name(),
            (string) $id,
            $link,
            $title,
            $this->categories($item),
            $publishedAt->setTimezone(new DateTimeZone('Asia/Tokyo')),
            $summary === '' ? null : $summary,
            $body->html,
            $body->text,
            $featured === null || $featured === '' ? $body->thumbnailUrl : $featured,
        );
    }

    /**
     * 固定ラベル ＋ _embedded['wp:term'] の全グループの name。
     *
     * @param array<array-key, mixed> $item
     * @return list<string>
     */
    private function categories(array $item): array
    {
        $categories = $this->site->fixedCategories;
        $groups = self::valueAt($item, '_embedded', 'wp:term');

        if (!is_array($groups)) {
            return $categories;
        }

        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }

            foreach ($group as $term) {
                $name = is_array($term) ? self::stringAt($term, 'name') : null;

                if ($name !== null && $name !== '') {
                    $categories[] = $name;
                }
            }
        }

        return $categories;
    }

    /**
     * @param array<array-key, mixed> $data
     * @param int|string ...$path
     * @return mixed 辿れなければ null
     */
    private static function valueAt(array $data, int|string ...$path): mixed
    {
        $value = $data;

        foreach ($path as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }

            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     * @param int|string ...$path
     * @return ?string
     */
    private static function stringAt(array $data, int|string ...$path): ?string
    {
        $value = self::valueAt($data, ...$path);

        return is_string($value) ? $value : null;
    }

    /**
     * @param array<array-key, mixed> $data
     * @param int|string ...$path
     * @return ?int
     */
    private static function intAt(array $data, int|string ...$path): ?int
    {
        $value = self::valueAt($data, ...$path);

        return is_int($value) ? $value : null;
    }
}
