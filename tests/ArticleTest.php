<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Tests;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shimomo\AutoraceNewsCollector\Article;

/**
 * @author shimomo
 */
final class ArticleTest extends TestCase
{
    /**
     * @return void
     */
    #[Test]
    public function holdsGivenValues(): void
    {
        $publishedAt = new DateTimeImmutable('2026-10-12 23:20:03', new DateTimeZone('Asia/Tokyo'));

        $article = new Article(
            'autorace.jp',
            'AUTORACE.JP',
            '038810',
            'https://autorace.jp/news/2026/10/12/038810/',
            '【日刊提供】佐藤励は序盤展開悔やむ',
            ['ニュース', '飯塚'],
            $publishedAt,
            '要約',
            '<p>本文</p>',
            '本文',
            'https://autorace.jp/img.jpg',
        );

        self::assertSame('autorace.jp', $article->source);
        self::assertSame('AUTORACE.JP', $article->sourceName);
        self::assertSame('038810', $article->id);
        self::assertSame('https://autorace.jp/news/2026/10/12/038810/', $article->url);
        self::assertSame('【日刊提供】佐藤励は序盤展開悔やむ', $article->title);
        self::assertSame(['ニュース', '飯塚'], $article->categories);
        self::assertSame($publishedAt, $article->publishedAt);
        self::assertSame('要約', $article->summary);
        self::assertSame('<p>本文</p>', $article->bodyHtml);
        self::assertSame('本文', $article->bodyText);
        self::assertSame('https://autorace.jp/img.jpg', $article->thumbnailUrl);
    }

    /**
     * @return void
     */
    #[Test]
    public function optionalValuesDefaultToNull(): void
    {
        $article = new Article('a', 'b', 'c', 'd', 'e', [], new DateTimeImmutable());

        self::assertNull($article->summary);
        self::assertNull($article->bodyHtml);
        self::assertNull($article->bodyText);
        self::assertNull($article->thumbnailUrl);
    }

    /**
     * Eloquent の updateOrCreate() にそのまま渡せる形。id は主キーと衝突するので source_id にする。
     *
     * @return void
     */
    #[Test]
    public function toArrayUsesSnakeCaseKeysAndRenamesIdToSourceId(): void
    {
        $publishedAt = new DateTimeImmutable('2026-10-12 23:20:03', new DateTimeZone('Asia/Tokyo'));

        $article = new Article(
            'autorace.jp',
            'AUTORACE.JP',
            '038810',
            'https://autorace.jp/news/2026/10/12/038810/',
            '【日刊提供】佐藤励は序盤展開悔やむ',
            ['ニュース', '飯塚'],
            $publishedAt,
            '要約',
            '<p>本文</p>',
            '本文',
            'https://autorace.jp/img.jpg',
        );

        self::assertSame([
            'source' => 'autorace.jp',
            'source_name' => 'AUTORACE.JP',
            'source_id' => '038810',
            'url' => 'https://autorace.jp/news/2026/10/12/038810/',
            'title' => '【日刊提供】佐藤励は序盤展開悔やむ',
            'categories' => ['ニュース', '飯塚'],
            'published_at' => $publishedAt,
            'summary' => '要約',
            'body_html' => '<p>本文</p>',
            'body_text' => '本文',
            'thumbnail_url' => 'https://autorace.jp/img.jpg',
        ], $article->toArray());
    }

    /**
     * @return void
     */
    #[Test]
    public function toArrayKeepsOptionalValuesAsNull(): void
    {
        $array = (new Article('a', 'b', 'c', 'd', 'e', [], new DateTimeImmutable()))->toArray();

        self::assertArrayNotHasKey('id', $array);
        self::assertNull($array['summary']);
        self::assertNull($array['body_html']);
        self::assertNull($array['body_text']);
        self::assertNull($array['thumbnail_url']);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function requiredProperties(): iterable
    {
        yield 'source' => ['source'];
        yield 'sourceName' => ['sourceName'];
        yield 'id' => ['id'];
        yield 'url' => ['url'];
        yield 'title' => ['title'];
    }

    /**
     * @param string $property
     * @return void
     */
    #[Test]
    #[DataProvider('requiredProperties')]
    public function rejectsEmptyRequiredValue(string $property): void
    {
        $values = ['source' => 'a', 'sourceName' => 'b', 'id' => 'c', 'url' => 'd', 'title' => 'e'];
        $values[$property] = '';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs(sprintf('Article::$%s must not be empty.', $property));

        new Article($values['source'], $values['sourceName'], $values['id'], $values['url'], $values['title'], [], new DateTimeImmutable());
    }
}
