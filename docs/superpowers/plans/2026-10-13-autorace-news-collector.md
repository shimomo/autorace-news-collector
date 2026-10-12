# autorace-news-collector 実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** オートレースの記事を RSS / Atom / WordPress REST API から集め、`Article` の配列として返す PHP ライブラリを作る。

**Architecture:** `Source` 契約を4クラス（AUTORACE.JP RSS、日刊スポーツ Atom＋記事ページ、WordPress REST 汎用、YouTube Atom）で実装し、`Collector` が束ねて `CollectResult` を返す。HTTP は symfony/http-client を `HttpFetcher` で包み、XML も HTML も PHP 標準の DOMDocument で読む。失敗はソース単位・記事単位で隔離して `failures` に積み、`collect()` は例外を投げない。

**Tech Stack:** PHP 8.4、symfony/http-client ^8.0、PHPUnit ^13.2（MockHttpClient）、Laravel Pint、Psalm 6

**Spec:** `docs/superpowers/specs/2026-10-13-autorace-news-collector-design.md`

## Global Constraints

- PHP `^8.4`。パッケージ名 `shimomo/autorace-news-collector`。名前空間は `Shimomo\AutoraceNewsCollector\`（テストは `Shimomo\AutoraceNewsCollector\Tests\`）
- 実行時依存は `symfony/http-client ^8.0` のみ。開発依存は `phpunit/phpunit ^13.2`、`laravel/pint ^1.27`、`vimeo/psalm ^6.0`、`ergebnis/composer-normalize ^2.52`
- すべてのファイルに `declare(strict_types=1);`。クラスは `final`（値オブジェクトは `final readonly class`）。クラス docblock に `@author shimomo`
- すべてのメソッドに `@param` / `@return`（`void` も書く）。型は psalm 記法（`list<...>`、`array<string, list<string>>`）。インターフェース実装には `#[\Override]`。契約の import は `use ... as XxxContract`
- コメントは日本語で「なぜ」を書く。例外メッセージは英語
- 日時はすべて `Asia/Tokyo` の `DateTimeImmutable`。`createFromFormat` の書式は先頭に `!` を付けてマイクロ秒を 0 にする
- テストはネットワークへ出ない。`tests/fixtures/` は 2026-10-13 に採取した実レスポンスで**既にリポジトリに置いてある**。書き換えない
- テストメソッドは `#[Test]` 属性 ＋ 英語の lowerCamelCase。`test_` 接頭辞は使わない。名前を変えたら `vendor/bin/phpunit --list-tests` で件数を確かめる
- psalm は `errorLevel="1"`、`findUnusedCode="false"`。エラー 0 を維持する
- `composer.json` のインデントは4スペース。触ったら `composer normalize`
- コミットは Conventional Commits 形式の英語1行（`feat:` / `test:` / `docs:` / `chore:`）。GPG 署名のため実行は本人が行う。各タスクの末尾では `git add` まで済ませ、コミットコマンドを提示して止まる
- 各タスクの完了条件: `composer test`、`composer analyse`、`composer lint` がすべて通ること

## Review Focus

1. フィード項目の必須値欠落（`link` / `title` / `pubDate` が無い）→ その1件だけ飛ばして `failures` に載り、他の項目は返る（Task 6 `skipsBrokenItems`、Task 7 `skipsEntryWithoutVideoId`、Task 9 `skipsBrokenEntry`）
2. 日刊の記事ページに本文要素が無い（レイアウト変更）→ 要約だけの `Article` ＋ `failures` 1件で、他の記事は本文付きで返る（Task 9 `returnsSummaryOnlyWhenBodyIsMissing`）
3. WordPress の `_embedded` が無い、または `wp:featuredmedia` がエラー要素（`{"code":"rest_forbidden"}`）→ サムネイル null で落ちない（Task 8 `toleratesMissingEmbedded`、`ignoresFeaturedMediaErrorObject`）
4. `since` と同時刻の記事 → 含まれる（`>=`）（Task 6 `filtersBySinceInclusive`、Task 8 `filtersBySinceInclusive`）
5. 相対 URL（`../`、`?query`、`#fragment`、`//host`、`data:`）の解決 → 絶対 URL になるか、そのまま残る（Task 4 `absolutizesUrls`）

---

### Task 1: パッケージの骨組みと `Article`

**Files:**
- Create: `composer.json`, `phpunit.xml.dist`, `psalm.xml.dist`, `pint.json`, `.editorconfig`, `.gitattributes`, `.gitignore`, `LICENSE`, `CHANGELOG.md`
- Create: `.github/workflows/test.yml`, `.github/workflows/psalm.yml`, `.github/workflows/lint.yml`, `.github/dependabot.yml`
- Create: `src/Article.php`
- Test: `tests/ArticleTest.php`

**Interfaces:**
- Produces: `Article::__construct(string $source, string $sourceName, string $id, string $url, string $title, array $categories, DateTimeImmutable $publishedAt, ?string $summary = null, ?string $bodyHtml = null, ?string $bodyText = null, ?string $thumbnailUrl = null)`。先頭5つの文字列が空なら `InvalidArgumentException`

- [ ] **Step 1: 設定ファイルを置く**

`composer.json`:

```json
{
    "name": "shimomo/autorace-news-collector",
    "description": "Collects the latest AutoRace news from official feeds and APIs.",
    "license": "MIT",
    "type": "library",
    "authors": [
        {
            "name": "shimomo",
            "email": "yuichi@shimomo.net"
        }
    ],
    "require": {
        "php": "^8.4",
        "ext-dom": "*",
        "ext-json": "*",
        "ext-libxml": "*",
        "symfony/http-client": "^8.0"
    },
    "require-dev": {
        "ergebnis/composer-normalize": "^2.52",
        "laravel/pint": "^1.27",
        "phpunit/phpunit": "^13.2",
        "vimeo/psalm": "^6.0"
    },
    "autoload": {
        "psr-4": {
            "Shimomo\\AutoraceNewsCollector\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Shimomo\\AutoraceNewsCollector\\Tests\\": "tests/"
        }
    },
    "config": {
        "allow-plugins": {
            "ergebnis/composer-normalize": true
        },
        "sort-packages": true
    },
    "scripts": {
        "analyse": "psalm",
        "fix": "pint",
        "lint": "pint --test",
        "test": "phpunit"
    }
}
```

`phpunit.xml.dist`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit backupGlobals="false"
         cacheDirectory=".phpunit.cache"
         colors="true"
         processIsolation="false"
         stopOnFailure="false">

    <testsuites>
        <testsuite name="AutoraceNewsCollector">
            <directory suffix="Test.php">tests/</directory>
        </testsuite>
    </testsuites>

    <source>
        <include>
            <directory suffix=".php">src/</directory>
        </include>
    </source>
</phpunit>
```

`psalm.xml.dist`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<psalm
    errorLevel="1"
    resolveFromConfigFile="true"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xmlns="https://getpsalm.org/schema/config"
    xsi:schemaLocation="https://getpsalm.org/schema/config vendor/vimeo/psalm/config.xsd"
    findUnusedBaselineEntry="true"
    findUnusedCode="false"
>
    <projectFiles>
        <directory name="src"/>
        <directory name="tests"/>
    </projectFiles>
</psalm>
```

`pint.json`（boatrace の複製。preset は `empty`。docblock 系 fixer は入れない）:

```json
{
    "preset": "empty",
    "rules": {
        "encoding": true,
        "full_opening_tag": true,
        "no_closing_tag": true,
        "blank_line_after_opening_tag": true,
        "indentation_type": true,
        "line_ending": true,
        "no_trailing_whitespace": true,
        "no_whitespace_in_blank_line": true,
        "single_blank_line_at_eof": true,
        "no_multiple_statements_per_line": true,
        "array_syntax": { "syntax": "short" },
        "single_quote": true,
        "trailing_comma_in_multiline": true,
        "no_unused_imports": true,
        "ordered_imports": { "sort_algorithm": "alpha", "imports_order": ["class", "function", "const"] },
        "unary_operator_spaces": true,
        "binary_operator_spaces": true,
        "concat_space": { "spacing": "one" },
        "function_declaration": { "closure_fn_spacing": "none" },
        "new_with_parentheses": { "anonymous_class": true, "named_class": true },
        "class_definition": { "space_before_parenthesis": true },
        "braces_position": { "anonymous_classes_opening_brace": "same_line" },
        "class_attributes_separation": { "elements": { "method": "one", "property": "one", "const": "one" } },
        "visibility_required": true,
        "return_type_declaration": true,
        "no_empty_statement": true,
        "single_line_comment_spacing": true,
        "no_extra_blank_lines": true
    }
}
```

`.editorconfig`:

```ini
root = true

[*]
charset = utf-8
end_of_line = lf
indent_size = 4
indent_style = space
insert_final_newline = true
trim_trailing_whitespace = true

[*.md]
trim_trailing_whitespace = false

[*.{yml,yaml}]
indent_size = 2

# フィクスチャは実レスポンスの verbatim コピー。行末空白・末尾改行なしをそのまま保つ
[tests/fixtures/**]
end_of_line = unset
insert_final_newline = false
trim_trailing_whitespace = false
```

`.gitattributes`（`composer require` が落とす dist zip にテストと設定を含めない）:

```
/.editorconfig export-ignore
/.gitattributes export-ignore
/.github export-ignore
/docs export-ignore
/phpunit.xml.dist export-ignore
/pint.json export-ignore
/psalm.xml.dist export-ignore
/tests export-ignore
```

`.gitignore`:

```
# OS
.DS_Store
Thumbs.db
*~

# Editor / IDE
.vscode/
.idea/
*.swp
*.swo

# Composer
/vendor/
composer.phar
composer.lock

# PHPUnit
.phpunit.result.cache
.phpunit.cache
phpunit.xml

# Psalm
psalm.xml
psalm.xml.cache

# Misc
*.log
*.bak
*.tmp
*.orig

# Claude Code（ローカル専用）
CLAUDE.md
```

`LICENSE`（MIT の全文。著作権表記は `Copyright (c) 2026 shimomo`）:

```
The MIT License (MIT)

Copyright (c) 2026 shimomo

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```

`CHANGELOG.md`:

```markdown
# Changelog

## Unreleased
```

`.github/workflows/test.yml`:

```yaml
name: Test

on:
  push:
    branches:
      - main
  pull_request:
  schedule:
    - cron: '0 0 * * *'
  workflow_dispatch:

jobs:
  test:
    name: PHPUnit ${{ matrix.phpunit-version }} on PHP ${{ matrix.php-version }} (${{ matrix.dependency-version }}, Ubuntu 24.04)
    runs-on: ubuntu-24.04
    strategy:
      matrix:
        php-version:
          - '8.4'
          - '8.5'
        phpunit-version:
          - '13.2'
        dependency-version:
          - prefer-lowest
          - prefer-stable
    steps:
      - name: Setup PHP ${{ matrix.php-version }}
        uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php-version }}
          extensions: dom, json, libxml

      - name: Checkout repository
        uses: actions/checkout@v7
        with:
          fetch-depth: 0

      - name: Install dependencies
        run: composer update --${{ matrix.dependency-version }} --prefer-dist --no-interaction --no-progress --with="phpunit/phpunit:${{ matrix.phpunit-version }}"

      - name: Run PHPUnit ${{ matrix.phpunit-version }}
        run: vendor/bin/phpunit --configuration=phpunit.xml.dist --no-progress
```

`.github/workflows/psalm.yml`:

```yaml
name: Psalm

on:
  push:
    branches:
      - main
  pull_request:
  schedule:
    - cron: '0 0 * * *'
  workflow_dispatch:

jobs:
  psalm:
    name: Psalm on PHP ${{ matrix.php-version }} (Ubuntu 24.04)
    runs-on: ubuntu-24.04
    strategy:
      matrix:
        php-version:
          - '8.4'
          - '8.5'
    steps:
      - name: Setup PHP ${{ matrix.php-version }}
        uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php-version }}
          extensions: dom, json, libxml, mbstring

      - name: Checkout repository
        uses: actions/checkout@v7
        with:
          fetch-depth: 0

      - name: Install dependencies
        run: composer update --prefer-dist --no-interaction --no-progress

      - name: Run Psalm
        run: vendor/bin/psalm --config=psalm.xml.dist --output-format=github --show-info=true --no-progress
```

`.github/workflows/lint.yml`:

```yaml
name: Lint

on:
  push:
    branches:
      - main
  pull_request:
  schedule:
    - cron: '0 0 * * *'
  workflow_dispatch:

jobs:
  lint:
    name: Lint on PHP ${{ matrix.php-version }} (Ubuntu 24.04)
    runs-on: ubuntu-24.04
    strategy:
      matrix:
        php-version:
          - '8.4'
          - '8.5'
    steps:
      - name: Setup PHP ${{ matrix.php-version }}
        uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php-version }}

      - name: Checkout repository
        uses: actions/checkout@v7
        with:
          fetch-depth: 0

      - name: Install dependencies
        run: composer update --prefer-dist --no-interaction --no-progress

      - name: Run Pint
        run: vendor/bin/pint --test

      - name: Check composer.json is normalized
        run: composer normalize --dry-run --no-check-lock --no-interaction
```

`.github/dependabot.yml`:

```yaml
version: 2
updates:
  - package-ecosystem: "github-actions"
    directory: "/"
    schedule:
      interval: "weekly"
      day: "monday"
      time: "09:00"
      timezone: "Asia/Tokyo"
    commit-message:
      prefix: "chore"
      prefix-development: "chore"
      include: "scope"
    labels: ["dependencies", "github-actions"]
    open-pull-requests-limit: 10
```

- [ ] **Step 2: 依存を入れて整形する**

Run: `composer install --no-interaction && composer normalize --indent-size=4 --indent-style=space --no-interaction`
Expected: `vendor/` ができ、`composer.json` が4スペースのまま整形される。`git diff composer.json` でキー順以外が変わっていないことを見る

- [ ] **Step 3: `Article` の失敗するテストを書く**

`tests/ArticleTest.php`:

```php
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
        $this->expectExceptionMessage(sprintf('Article::$%s must not be empty.', $property));

        new Article($values['source'], $values['sourceName'], $values['id'], $values['url'], $values['title'], [], new DateTimeImmutable());
    }
}
```

- [ ] **Step 4: 落ちることを確かめる**

Run: `vendor/bin/phpunit tests/ArticleTest.php`
Expected: `Class "Shimomo\AutoraceNewsCollector\Article" not found` で Error

- [ ] **Step 5: `Article` を書く**

`src/Article.php`:

```php
<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * 1件の記事。
 *
 * 必須項目が空の Article を作らせないため、コンストラクタで検査する。
 * 重複判定は (source, id) で呼び出し側が行う。日刊スポーツの記事は autorace.jp にも
 * 「【日刊提供】」として載るが、URL も id も異なるのでここでは束ねない。
 *
 * @author shimomo
 */
final readonly class Article
{
    /**
     * @param string $source ソースキー（空不可）
     * @param string $sourceName 表示名（空不可）
     * @param string $id ソース内で安定した ID（空不可）
     * @param string $url 記事 URL（空不可）
     * @param string $title 見出し（空不可）
     * @param list<string> $categories ソース固有の分類
     * @param \DateTimeImmutable $publishedAt Asia/Tokyo に正規化済み
     * @param ?string $summary フィードの要約
     * @param ?string $bodyHtml 整形済み本文 HTML
     * @param ?string $bodyText 本文のプレーンテキスト
     * @param ?string $thumbnailUrl アイキャッチまたは本文先頭画像
     */
    public function __construct(
        public string $source,
        public string $sourceName,
        public string $id,
        public string $url,
        public string $title,
        public array $categories,
        public DateTimeImmutable $publishedAt,
        public ?string $summary = null,
        public ?string $bodyHtml = null,
        public ?string $bodyText = null,
        public ?string $thumbnailUrl = null,
    ) {
        $required = [
            'source' => $source,
            'sourceName' => $sourceName,
            'id' => $id,
            'url' => $url,
            'title' => $title,
        ];

        foreach ($required as $name => $value) {
            if ($value === '') {
                throw new InvalidArgumentException(sprintf('Article::$%s must not be empty.', $name));
            }
        }
    }
}
```

- [ ] **Step 6: 通ることと、規約を壊すと落ちることを確かめる**

Run: `vendor/bin/phpunit tests/ArticleTest.php`
Expected: `OK (7 tests, ...)`

Run: 一時的に `if ($value === '')` を `if (false)` に変えて `vendor/bin/phpunit tests/ArticleTest.php`
Expected: `rejectsEmptyRequiredValue` が5件落ちる。確かめたら戻す

- [ ] **Step 7: 静的解析と整形**

Run: `composer analyse && composer lint`
Expected: psalm `No errors found!`、pint `PASS`。pint が落ちたら `composer fix` で直して差分を見る

- [ ] **Step 8: ステージしてコミットコマンドを提示する**

```bash
git add composer.json phpunit.xml.dist psalm.xml.dist pint.json .editorconfig .gitattributes .gitignore LICENSE CHANGELOG.md .github
git commit -m "chore: scaffold the package"
git add src/Article.php tests/ArticleTest.php
git commit -m "feat: add the Article value object"
```

---

### Task 2: 結果の値オブジェクトと契約

**Files:**
- Create: `src/SourceFailure.php`, `src/FetchResult.php`, `src/CollectResult.php`, `src/Contracts/Source.php`, `src/Contracts/Sleeper.php`
- Test: `tests/SourceFailureTest.php`, `tests/FetchResultTest.php`

**Interfaces:**
- Produces: `SourceFailure::__construct(string $source, ?string $url, string $message, ?Throwable $previous = null)`
- Produces: `FetchResult::__construct(array $articles = [], array $failures = [])`、`CollectResult` も同じ形
- Produces: `interface Source { key(): string; name(): string; fetch(?DateTimeImmutable $since): FetchResult; }`
- Produces: `interface Sleeper { sleep(float $seconds): void; }`

- [ ] **Step 1: 失敗するテストを書く**

`tests/SourceFailureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Shimomo\AutoraceNewsCollector\SourceFailure;

/**
 * @author shimomo
 */
final class SourceFailureTest extends TestCase
{
    /**
     * @return void
     */
    #[Test]
    public function holdsGivenValues(): void
    {
        $previous = new RuntimeException('boom');

        $failure = new SourceFailure('nikkansports', 'https://example.test/a.html', 'GET failed', $previous);

        self::assertSame('nikkansports', $failure->source);
        self::assertSame('https://example.test/a.html', $failure->url);
        self::assertSame('GET failed', $failure->message);
        self::assertSame($previous, $failure->previous);
    }

    /**
     * @return void
     */
    #[Test]
    public function previousDefaultsToNull(): void
    {
        $failure = new SourceFailure('youtube', null, 'parse error');

        self::assertNull($failure->url);
        self::assertNull($failure->previous);
    }
}
```

`tests/FetchResultTest.php`:

```php
<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shimomo\AutoraceNewsCollector\Article;
use Shimomo\AutoraceNewsCollector\CollectResult;
use Shimomo\AutoraceNewsCollector\FetchResult;
use Shimomo\AutoraceNewsCollector\SourceFailure;

/**
 * @author shimomo
 */
final class FetchResultTest extends TestCase
{
    /**
     * @return void
     */
    #[Test]
    public function defaultsToEmptyLists(): void
    {
        $result = new FetchResult();

        self::assertSame([], $result->articles);
        self::assertSame([], $result->failures);
    }

    /**
     * @return void
     */
    #[Test]
    public function holdsArticlesAndFailures(): void
    {
        $article = new Article('a', 'b', 'c', 'd', 'e', [], new DateTimeImmutable());
        $failure = new SourceFailure('a', null, 'x');

        $result = new FetchResult([$article], [$failure]);
        $collected = new CollectResult([$article], [$failure]);

        self::assertSame([$article], $result->articles);
        self::assertSame([$failure], $result->failures);
        self::assertSame([$article], $collected->articles);
        self::assertSame([$failure], $collected->failures);
    }
}
```

- [ ] **Step 2: 落ちることを確かめる**

Run: `vendor/bin/phpunit tests/SourceFailureTest.php tests/FetchResultTest.php`
Expected: クラスが無く Error

- [ ] **Step 3: 実装する**

`src/SourceFailure.php`:

```php
<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector;

use Throwable;

/**
 * 取得・解析の失敗1件。ソース全体の失敗（url は null）にも記事1件の失敗にも使う。
 *
 * @author shimomo
 */
final readonly class SourceFailure
{
    /**
     * @param string $source ソースキー
     * @param ?string $url 失敗した URL。ソース全体の失敗なら null
     * @param string $message
     * @param ?\Throwable $previous
     */
    public function __construct(
        public string $source,
        public ?string $url,
        public string $message,
        public ?Throwable $previous = null,
    ) {
    }
}
```

`src/FetchResult.php`:

```php
<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector;

/**
 * 1ソース分の取得結果。
 *
 * @author shimomo
 */
final readonly class FetchResult
{
    /**
     * @param list<Article> $articles
     * @param list<SourceFailure> $failures
     */
    public function __construct(
        public array $articles = [],
        public array $failures = [],
    ) {
    }
}
```

`src/CollectResult.php`:

```php
<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector;

/**
 * 全ソースを束ねた結果。articles は publishedAt の新しい順。
 *
 * @author shimomo
 */
final readonly class CollectResult
{
    /**
     * @param list<Article> $articles
     * @param list<SourceFailure> $failures
     */
    public function __construct(
        public array $articles = [],
        public array $failures = [],
    ) {
    }
}
```

`src/Contracts/Source.php`:

```php
<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Contracts;

use DateTimeImmutable;
use Shimomo\AutoraceNewsCollector\FetchResult;

/**
 * 記事の収集元。
 *
 * @author shimomo
 */
interface Source
{
    /**
     * @return string ソースキー（空不可）
     */
    public function key(): string;

    /**
     * @return string 表示名（空不可）
     */
    public function name(): string;

    /**
     * since を渡すと publishedAt >= since の記事だけ返す。
     * フィード全体の取得・解析に失敗したときは例外を投げてよい（Collector が failures へ変換する）。
     * 1件単位の失敗は FetchResult::$failures に載せて続行する。
     *
     * @param ?\DateTimeImmutable $since
     * @return \Shimomo\AutoraceNewsCollector\FetchResult
     */
    public function fetch(?DateTimeImmutable $since): FetchResult;
}
```

`src/Contracts/Sleeper.php`:

```php
<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Contracts;

/**
 * リクエスト間の待機。テストでは待たない実装に差し替える。
 *
 * @author shimomo
 */
interface Sleeper
{
    /**
     * @param float $seconds
     * @return void
     */
    public function sleep(float $seconds): void;
}
```

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit tests/SourceFailureTest.php tests/FetchResultTest.php && composer analyse && composer lint`
Expected: `OK (4 tests, ...)`、psalm と pint が通る

- [ ] **Step 5: ステージしてコミットコマンドを提示する**

```bash
git add src/SourceFailure.php src/FetchResult.php src/CollectResult.php src/Contracts tests/SourceFailureTest.php tests/FetchResultTest.php
git commit -m "feat: add result value objects and the source and sleeper contracts"
```

---

### Task 3: HTTP 層

**Files:**
- Create: `src/Http/HttpException.php`, `src/Http/HttpResponse.php`, `src/Http/NativeSleeper.php`, `src/Http/HttpFetcher.php`
- Create: `tests/Support/SpySleeper.php`
- Test: `tests/Http/HttpResponseTest.php`, `tests/Http/HttpFetcherTest.php`, `tests/Http/NativeSleeperTest.php`

**Interfaces:**
- Consumes: `Contracts\Sleeper`（Task 2）
- Produces: `HttpFetcher::__construct(HttpClientInterface $client, Sleeper $sleeper, float $interval = 1.0, float $timeout = 15.0, string $userAgent = HttpFetcher::DEFAULT_USER_AGENT)`
- Produces: `HttpFetcher::get(string $url): HttpResponse`、`HttpFetcher::getJson(string $url): array`。失敗はすべて `HttpException`
- Produces: `HttpResponse { public string $body; public array $headers; header(string $name): ?string; json(): array }`
- Produces: `tests/Support/SpySleeper { public array $calls }`（待たずに秒数を記録）

- [ ] **Step 1: テスト補助の `SpySleeper` を書く**

`tests/Support/SpySleeper.php`:

```php
<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Tests\Support;

use Shimomo\AutoraceNewsCollector\Contracts\Sleeper as SleeperContract;

/**
 * 待たずに呼び出しだけ記録する Sleeper。
 *
 * @author shimomo
 */
final class SpySleeper implements SleeperContract
{
    /**
     * @var list<float>
     */
    public array $calls = [];

    /**
     * @param float $seconds
     * @return void
     */
    #[\Override]
    public function sleep(float $seconds): void
    {
        $this->calls[] = $seconds;
    }
}
```

- [ ] **Step 2: 失敗するテストを書く**

`tests/Http/HttpResponseTest.php`:

```php
<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Tests\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shimomo\AutoraceNewsCollector\Http\HttpException;
use Shimomo\AutoraceNewsCollector\Http\HttpResponse;

/**
 * @author shimomo
 */
final class HttpResponseTest extends TestCase
{
    /**
     * @return void
     */
    #[Test]
    public function headerIsCaseInsensitiveAndReturnsFirstValue(): void
    {
        $response = new HttpResponse('', ['x-wp-totalpages' => ['3', '9']]);

        self::assertSame('3', $response->header('X-WP-TotalPages'));
        self::assertSame('3', $response->header('x-wp-totalpages'));
        self::assertNull($response->header('Link'));
    }

    /**
     * @return void
     */
    #[Test]
    public function jsonDecodesObjectsAndArrays(): void
    {
        self::assertSame(['a' => 1], (new HttpResponse('{"a":1}'))->json());
        self::assertSame([1, 2], (new HttpResponse('[1,2]'))->json());
    }

    /**
     * @return void
     */
    #[Test]
    public function jsonRejectsInvalidJson(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Response body is not valid JSON');

        (new HttpResponse('nope'))->json();
    }

    /**
     * @return void
     */
    #[Test]
    public function jsonRejectsScalars(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Response body is not a JSON array or object.');

        (new HttpResponse('42'))->json();
    }
}
```

`tests/Http/HttpFetcherTest.php`:

```php
<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Tests\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shimomo\AutoraceNewsCollector\Http\HttpException;
use Shimomo\AutoraceNewsCollector\Http\HttpFetcher;
use Shimomo\AutoraceNewsCollector\Tests\Support\SpySleeper;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * @author shimomo
 */
final class HttpFetcherTest extends TestCase
{
    /**
     * @return void
     */
    #[Test]
    public function sendsUserAgentAcceptLanguageAndTimeout(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            /** @var array{normalized_headers: array<string, list<string>>, timeout: float} $options */
            self::assertSame('GET', $method);
            self::assertSame('https://example.test/feed.xml', $url);
            self::assertSame(['User-Agent: ' . HttpFetcher::DEFAULT_USER_AGENT], $options['normalized_headers']['user-agent']);
            self::assertSame(['Accept-Language: ja'], $options['normalized_headers']['accept-language']);
            self::assertSame(15.0, $options['timeout']);

            return new MockResponse('<rss/>');
        });

        $response = (new HttpFetcher($client, new SpySleeper()))->get('https://example.test/feed.xml');

        self::assertSame('<rss/>', $response->body);
        self::assertSame(1, $client->getRequestsCount());
    }

    /**
     * @return void
     */
    #[Test]
    public function allowsOverridingUserAgentAndTimeout(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            /** @var array{normalized_headers: array<string, list<string>>, timeout: float} $options */
            self::assertSame(['User-Agent: my-agent/1.0'], $options['normalized_headers']['user-agent']);
            self::assertSame(3.0, $options['timeout']);

            return new MockResponse('ok');
        });

        (new HttpFetcher($client, new SpySleeper(), 1.0, 3.0, 'my-agent/1.0'))->get('https://example.test/');
    }

    /**
     * @return void
     */
    #[Test]
    public function sleepsForTheIntervalBeforeEveryRequestButTheFirst(): void
    {
        $sleeper = new SpySleeper();
        $client = new MockHttpClient([new MockResponse('a'), new MockResponse('b'), new MockResponse('c')]);
        $fetcher = new HttpFetcher($client, $sleeper, 1.5);

        $fetcher->get('https://example.test/1');
        $fetcher->get('https://example.test/2');
        $fetcher->get('https://example.test/3');

        self::assertSame([1.5, 1.5], $sleeper->calls);
    }

    /**
     * @return void
     */
    #[Test]
    public function doesNotSleepWhenIntervalIsZero(): void
    {
        $sleeper = new SpySleeper();
        $fetcher = new HttpFetcher(new MockHttpClient([new MockResponse('a'), new MockResponse('b')]), $sleeper, 0.0);

        $fetcher->get('https://example.test/1');
        $fetcher->get('https://example.test/2');

        self::assertSame([], $sleeper->calls);
    }

    /**
     * @return void
     */
    #[Test]
    public function throwsOnNon2xxStatus(): void
    {
        $fetcher = new HttpFetcher(new MockHttpClient([new MockResponse('gone', ['http_code' => 404])]), new SpySleeper(), 0.0);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('GET https://example.test/missing returned HTTP 404.');

        $fetcher->get('https://example.test/missing');
    }

    /**
     * @return void
     */
    #[Test]
    public function wrapsTransportErrors(): void
    {
        $fetcher = new HttpFetcher(new MockHttpClient([new MockResponse('', ['error' => 'Connection refused'])]), new SpySleeper(), 0.0);

        try {
            $fetcher->get('https://example.test/');
            self::fail('HttpException was not thrown.');
        } catch (HttpException $e) {
            self::assertStringContainsString('GET https://example.test/ failed: Connection refused', $e->getMessage());
            self::assertInstanceOf(TransportExceptionInterface::class, $e->getPrevious());
        }
    }

    /**
     * @return void
     */
    #[Test]
    public function lowercasesHeaderNames(): void
    {
        $client = new MockHttpClient([new MockResponse('[]', ['response_headers' => ['X-WP-TotalPages' => '3']])]);

        $response = (new HttpFetcher($client, new SpySleeper(), 0.0))->get('https://example.test/');

        self::assertSame(['3'], $response->headers['x-wp-totalpages']);
        self::assertSame('3', $response->header('X-WP-TotalPages'));
    }

    /**
     * @return void
     */
    #[Test]
    public function getJsonDecodesTheBody(): void
    {
        $client = new MockHttpClient([new MockResponse('{"id":1}')]);

        self::assertSame(['id' => 1], (new HttpFetcher($client, new SpySleeper(), 0.0))->getJson('https://example.test/'));
    }
}
```

`tests/Http/NativeSleeperTest.php`:

```php
<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Tests\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shimomo\AutoraceNewsCollector\Http\NativeSleeper;

/**
 * @author shimomo
 */
final class NativeSleeperTest extends TestCase
{
    /**
     * @return void
     */
    #[Test]
    public function sleepsRoughlyForTheGivenSeconds(): void
    {
        $started = hrtime(true);

        (new NativeSleeper())->sleep(0.02);

        self::assertGreaterThanOrEqual(20_000_000, hrtime(true) - $started);
    }

    /**
     * @return void
     */
    #[Test]
    public function returnsImmediatelyForZeroOrNegative(): void
    {
        $started = hrtime(true);

        (new NativeSleeper())->sleep(0.0);
        (new NativeSleeper())->sleep(-1.0);

        self::assertLessThan(10_000_000, hrtime(true) - $started);
    }
}
```

- [ ] **Step 3: 落ちることを確かめる**

Run: `vendor/bin/phpunit tests/Http`
Expected: クラスが無く Error

- [ ] **Step 4: 実装する**

`src/Http/HttpException.php`:

```php
<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Http;

use RuntimeException;

/**
 * HTTP の失敗（非 2xx、トランスポート失敗、JSON 復号失敗）。symfony の例外は外へ出さない。
 *
 * @author shimomo
 */
final class HttpException extends RuntimeException
{
}
```

`src/Http/HttpResponse.php`:

```php
<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Http;

use JsonException;

/**
 * 取得結果。ヘッダ名は小文字に揃えてある（symfony の getHeaders() と同じ形）。
 *
 * @author shimomo
 */
final readonly class HttpResponse
{
    /**
     * @param string $body
     * @param array<string, list<string>> $headers 小文字のヘッダ名 → 値の一覧
     */
    public function __construct(
        public string $body,
        public array $headers = [],
    ) {
    }

    /**
     * @param string $name 大文字小文字は問わない
     * @return ?string 先頭の値。無ければ null
     */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function json(): array
    {
        try {
            $decoded = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new HttpException('Response body is not valid JSON: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($decoded)) {
            throw new HttpException('Response body is not a JSON array or object.');
        }

        return $decoded;
    }
}
```

`src/Http/NativeSleeper.php`:

```php
<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Http;

use Shimomo\AutoraceNewsCollector\Contracts\Sleeper as SleeperContract;

/**
 * usleep で実際に待つ Sleeper。
 *
 * @author shimomo
 */
final class NativeSleeper implements SleeperContract
{
    /**
     * @param float $seconds
     * @return void
     */
    #[\Override]
    public function sleep(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        usleep((int) round($seconds * 1_000_000));
    }
}
```

`src/Http/HttpFetcher.php`:

```php
<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Http;

use Shimomo\AutoraceNewsCollector\Contracts\Sleeper as SleeperContract;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * symfony/http-client の薄い包み。UA と待機を一箇所に集め、失敗を HttpException に揃える。
 *
 * 再試行は持たない。必要なら呼び出し側が RetryableHttpClient で包んだクライアントを渡す。
 *
 * @author shimomo
 */
final class HttpFetcher
{
    public const string DEFAULT_USER_AGENT = 'autorace-news-collector (+https://github.com/shimomo/autorace-news-collector)';

    private bool $requested = false;

    /**
     * @param \Symfony\Contracts\HttpClient\HttpClientInterface $client
     * @param \Shimomo\AutoraceNewsCollector\Contracts\Sleeper $sleeper
     * @param float $interval リクエスト間の待機秒数
     * @param float $timeout
     * @param string $userAgent
     */
    public function __construct(
        private HttpClientInterface $client,
        private SleeperContract $sleeper,
        private float $interval = 1.0,
        private float $timeout = 15.0,
        private string $userAgent = self::DEFAULT_USER_AGENT,
    ) {
    }

    /**
     * @param string $url
     * @return \Shimomo\AutoraceNewsCollector\Http\HttpResponse
     */
    public function get(string $url): HttpResponse
    {
        // 2回目以降だけ待つ。相手サイトへの礼儀であって、最初の1回を遅らせる理由は無い
        if ($this->requested && $this->interval > 0) {
            $this->sleeper->sleep($this->interval);
        }

        $this->requested = true;

        try {
            $response = $this->client->request('GET', $url, [
                'headers' => [
                    'User-Agent' => $this->userAgent,
                    'Accept-Language' => 'ja',
                ],
                'timeout' => $this->timeout,
            ]);

            $status = $response->getStatusCode();
            $body = $response->getContent(false);
            $rawHeaders = $response->getHeaders(false);
        } catch (ExceptionInterface $e) {
            throw new HttpException(sprintf('GET %s failed: %s', $url, $e->getMessage()), 0, $e);
        }

        if ($status < 200 || $status >= 300) {
            throw new HttpException(sprintf('GET %s returned HTTP %d.', $url, $status));
        }

        $headers = [];

        foreach ($rawHeaders as $name => $values) {
            $headers[strtolower((string) $name)] = array_values(array_map(strval(...), $values));
        }

        return new HttpResponse($body, $headers);
    }

    /**
     * @param string $url
     * @return array<array-key, mixed>
     */
    public function getJson(string $url): array
    {
        return $this->get($url)->json();
    }
}
```

- [ ] **Step 5: 通ることを確かめる**

Run: `vendor/bin/phpunit tests/Http && composer analyse && composer lint`
Expected: `OK (14 tests, ...)`、psalm と pint が通る。psalm が `MixedArrayAccess` を出す場合は該当テストの `@var` 行が閉包の先頭にあるか確かめる

- [ ] **Step 6: ステージしてコミットコマンドを提示する**

```bash
git add src/Http tests/Http tests/Support/SpySleeper.php
git commit -m "feat: add the HTTP fetcher with throttling and error wrapping"
```

---

### Task 4: HTML 読み込みと本文整形

**Files:**
- Create: `src/Html/HtmlDocument.php`, `src/Html/NormalizedBody.php`, `src/Html/BodyNormalizer.php`
- Test: `tests/Html/HtmlDocumentTest.php`, `tests/Html/BodyNormalizerTest.php`

**Interfaces:**
- Produces: `HtmlDocument::fromString(string $html): self`、`HtmlDocument::fromFragment(string $html): self`、`root(): ?DOMElement`、`nodes(string $xpath, ?DOMNode $context = null): list<DOMNode>`、`elements(...): list<DOMElement>`、`first(...): ?DOMElement`、`innerHtml(DOMNode): string`、`outerHtml(DOMNode): string`
- Produces: `NormalizedBody { ?string $html; ?string $text; ?string $thumbnailUrl }`
- Produces: `BodyNormalizer::normalize(string $html, string $baseUrl): NormalizedBody`、`BodyNormalizer::toText(string $html): string`（static）、`BodyNormalizer::absolutize(string $url, string $baseUrl): string`（static）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Html/HtmlDocumentTest.php`:

```php
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
```

`tests/Html/BodyNormalizerTest.php`:

```php
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
```

- [ ] **Step 2: 落ちることを確かめる**

Run: `vendor/bin/phpunit tests/Html`
Expected: クラスが無く Error

- [ ] **Step 3: 実装する**

`src/Html/HtmlDocument.php`:

```php
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
     * libxml の HTML パーサは文字コードの既定が ISO-8859-1 なので、XML 宣言で UTF-8 を明示する。
     * こうすると saveHTML の出力も数値実体参照にならず UTF-8 のまま出る。
     * HTML5 の要素（section, nav など）は libxml にとって未知だが、警告を抑えれば通常の要素として読める。
     *
     * @param string $html
     * @return self
     */
    public static function fromString(string $html): self
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"?>' . $html, LIBXML_NONET | LIBXML_HTML_NODEFDTD);
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
        $list = $this->xpath->query($xpath, $context);

        if ($list === false) {
            throw new InvalidArgumentException(sprintf('Invalid XPath expression: %s', $xpath));
        }

        $nodes = [];

        foreach ($list as $node) {
            $nodes[] = $node;
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
```

`src/Html/NormalizedBody.php`:

```php
<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Html;

/**
 * 整形後の本文。
 *
 * @author shimomo
 */
final readonly class NormalizedBody
{
    /**
     * @param ?string $html 整形済み HTML。テキストが空なら null
     * @param ?string $text プレーンテキスト。空なら null
     * @param ?string $thumbnailUrl 先頭画像の絶対 URL。無ければ null
     */
    public function __construct(
        public ?string $html,
        public ?string $text,
        public ?string $thumbnailUrl,
    ) {
    }
}
```

`src/Html/BodyNormalizer.php`:

```php
<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Html;

/**
 * 本文 HTML の「保存用の掃除」。公開向けのサニタイズではないので、属性の白リスト化はしない。
 *
 * @author shimomo
 */
final class BodyNormalizer
{
    private const string REMOVE_XPATH = './/script|.//style|.//noscript|.//iframe|.//comment()';

    /**
     * @param string $html 本文 HTML 断片
     * @param string $baseUrl 相対 URL の解決に使う記事 URL
     * @return \Shimomo\AutoraceNewsCollector\Html\NormalizedBody
     */
    public function normalize(string $html, string $baseUrl): NormalizedBody
    {
        if (trim($html) === '') {
            return new NormalizedBody(null, null, null);
        }

        $document = HtmlDocument::fromFragment($html);
        $root = $document->root();

        if ($root === null) {
            return new NormalizedBody(null, null, null);
        }

        // XPath の結果は静的なスナップショットなので、回しながら消して問題ない
        foreach ($document->nodes(self::REMOVE_XPATH, $root) as $node) {
            $node->parentNode?->removeChild($node);
        }

        foreach ($document->elements('.//img[@src]', $root) as $image) {
            $image->setAttribute('src', self::absolutize($image->getAttribute('src'), $baseUrl));
        }

        foreach ($document->elements('.//a[@href]', $root) as $anchor) {
            $anchor->setAttribute('href', self::absolutize($anchor->getAttribute('href'), $baseUrl));
        }

        $inner = trim($document->innerHtml($root));
        $text = self::toText($inner);

        $image = $document->first('.//img[@src]', $root);
        $thumbnail = $image?->getAttribute('src');
        $thumbnail = $thumbnail === null || $thumbnail === '' ? null : $thumbnail;

        // 画像だけの投稿でもサムネイルは残す。本文としては空なので html / text は null
        if ($text === '') {
            return new NormalizedBody(null, null, $thumbnail);
        }

        return new NormalizedBody($inner, $text, $thumbnail);
    }

    /**
     * ブロック要素と br の境目を改行にしてタグを落とす。全角空白は意図して使われるので畳まない。
     *
     * @param string $html
     * @return string
     */
    public static function toText(string $html): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $html);
        $text = (string) preg_replace('~<br\s*/?>~i', "\n", $text);
        $text = (string) preg_replace('~</(?:p|div|li|h[1-6]|tr|blockquote|section|article|ul|ol|table|dl|dt|dd|figure|figcaption|pre)>~i', "\n", $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = (string) preg_replace('~[ \t]+~', ' ', $text);
        $text = implode("\n", array_map(trim(...), explode("\n", $text)));
        $text = (string) preg_replace("~\n{3,}~", "\n\n", $text);

        return trim($text);
    }

    /**
     * RFC 3986 の参照解決の簡易版。スキーム付き（data:, mailto: 含む）はそのまま返す。
     *
     * @param string $url
     * @param string $baseUrl
     * @return string
     */
    public static function absolutize(string $url, string $baseUrl): string
    {
        $url = trim($url);

        if ($url === '' || preg_match('~^[a-z][a-z0-9+.-]*:~i', $url) === 1) {
            return $url;
        }

        $base = parse_url($baseUrl);

        if (!is_array($base) || !isset($base['scheme'], $base['host'])) {
            return $url;
        }

        if (str_starts_with($url, '//')) {
            return $base['scheme'] . ':' . $url;
        }

        $origin = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');
        $path = $base['path'] ?? '/';

        if ($path === '') {
            $path = '/';
        }

        if (str_starts_with($url, '#')) {
            return $origin . $path . (isset($base['query']) ? '?' . $base['query'] : '') . $url;
        }

        if (str_starts_with($url, '?')) {
            return $origin . $path . $url;
        }

        if (!str_starts_with($url, '/')) {
            $url = substr($path, 0, (int) strrpos($path, '/') + 1) . $url;
        }

        return $origin . self::removeDotSegments($url);
    }

    /**
     * @param string $path クエリ・フラグメント付きでもよい
     * @return string
     */
    private static function removeDotSegments(string $path): string
    {
        $suffix = '';

        if (preg_match('~^([^?#]*)([?#].*)$~s', $path, $matches) === 1) {
            $path = $matches[1];
            $suffix = $matches[2];
        }

        $segments = explode('/', $path);
        $last = end($segments);
        $output = [];

        foreach ($segments as $segment) {
            if ($segment === '.') {
                continue;
            }

            if ($segment === '..') {
                // 先頭の空要素（ルート）より上へは行かせない
                if (count($output) > 1) {
                    array_pop($output);
                }

                continue;
            }

            $output[] = $segment;
        }

        // 末尾が . や .. のときはディレクトリを指すので末尾のスラッシュを残す
        if ($last === '.' || $last === '..') {
            $output[] = '';
        }

        return implode('/', $output) . $suffix;
    }
}
```

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit tests/Html && composer analyse && composer lint`
Expected: `OK (35 tests, ...)`。落ちた場合に見るところ:
- `keepsJapaneseTextAndEntitiesIntact` で `&#x4F50;` のような数値実体が出る → `loadHTML` に渡す文字列の先頭が `<?xml encoding="UTF-8"?>` になっているか
- `fragmentRootKeepsMarkup...` で `<img ... />` のまま出る、または `<p>` の入れ子が変わる → `saveHTML` に DOMNode を渡しているか（文書全体を渡すと `<html><body>` が付く）

- [ ] **Step 5: ステージしてコミットコマンドを提示する**

```bash
git add src/Html tests/Html
git commit -m "feat: add HTML parsing and body normalization"
```

---

### Task 5: XML フィード読み込み

**Files:**
- Create: `src/Feed/FeedException.php`, `src/Feed/XmlDocument.php`
- Test: `tests/Feed/XmlDocumentTest.php`

**Interfaces:**
- Produces: `XmlDocument::fromString(string $xml): self`（失敗は `FeedException`）、`elements(string $xpath, ?DOMNode $context = null): list<DOMElement>`、`text(...): ?string`（先頭要素の trim 済みテキスト）、`texts(...): list<string>`、`attr(string $xpath, string $name, ?DOMNode $context = null): ?string`
- 登録済み名前空間の接頭辞: `atom`、`yt`、`media`、`content`、`dc`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feed/XmlDocumentTest.php`:

```php
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
        $this->expectExceptionMessage('Failed to parse XML');

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
}
```

- [ ] **Step 2: 落ちることを確かめる**

Run: `vendor/bin/phpunit tests/Feed`
Expected: クラスが無く Error

- [ ] **Step 3: 実装する**

`src/Feed/FeedException.php`:

```php
<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Feed;

use RuntimeException;

/**
 * フィードの解析失敗。
 *
 * @author shimomo
 */
final class FeedException extends RuntimeException
{
}
```

`src/Feed/XmlDocument.php`:

```php
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
        $list = $this->xpath->query($xpath, $context);

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
```

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit tests/Feed && composer analyse && composer lint`
Expected: `OK (4 tests, ...)`

- [ ] **Step 5: ステージしてコミットコマンドを提示する**

```bash
git add src/Feed tests/Feed
git commit -m "feat: add the XML feed reader"
```

---

### Task 6: AUTORACE.JP の RSS ソース

**Files:**
- Create: `src/Sources/AutoraceJpSource.php`
- Create: `tests/Support/Fixtures.php`
- Test: `tests/Sources/AutoraceJpSourceTest.php`
- Fixture（既存）: `tests/fixtures/autorace-jp/rss.xml`（15件。先頭は `/news/2026/10/12/038810/`、末尾は 2026-10-10 13:16:25）

**Interfaces:**
- Consumes: `HttpFetcher::get()`（Task 3）、`XmlDocument`（Task 5）、`BodyNormalizer`（Task 4）、`Article` / `FetchResult` / `SourceFailure`（Task 1, 2）
- Produces: `AutoraceJpSource::__construct(HttpFetcher $fetcher, BodyNormalizer $normalizer = new BodyNormalizer())`、`AutoraceJpSource::FEED_URL`、`key() === 'autorace.jp'`、`name() === 'AUTORACE.JP'`
- Produces: `tests/Support/Fixtures::read(string $path): string`

- [ ] **Step 1: テスト補助の `Fixtures` を書く**

`tests/Support/Fixtures.php`:

```php
<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Tests\Support;

use RuntimeException;

/**
 * tests/fixtures/ の実レスポンスを読む。
 *
 * @author shimomo
 */
final class Fixtures
{
    /**
     * @param string $path tests/fixtures/ からの相対パス
     * @return string
     */
    public static function read(string $path): string
    {
        $content = file_get_contents(__DIR__ . '/../fixtures/' . $path);

        if ($content === false) {
            throw new RuntimeException(sprintf('Fixture "%s" could not be read.', $path));
        }

        return $content;
    }
}
```

- [ ] **Step 2: 失敗するテストを書く**

`tests/Sources/AutoraceJpSourceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Tests\Sources;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shimomo\AutoraceNewsCollector\Http\HttpException;
use Shimomo\AutoraceNewsCollector\Http\HttpFetcher;
use Shimomo\AutoraceNewsCollector\Sources\AutoraceJpSource;
use Shimomo\AutoraceNewsCollector\Tests\Support\Fixtures;
use Shimomo\AutoraceNewsCollector\Tests\Support\SpySleeper;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @author shimomo
 */
final class AutoraceJpSourceTest extends TestCase
{
    private const string BROKEN_FEED = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0">
          <channel>
            <item>
              <title>正常</title>
              <link>https://autorace.jp/news/2026/10/12/000001/</link>
              <description><![CDATA[<p>本文</p>]]></description>
              <pubDate>Mon, 12 Oct 2026 10:00:00 +0900</pubDate>
            </item>
            <item>
              <title>日付なし</title>
              <link>https://autorace.jp/news/2026/10/12/000002/</link>
            </item>
            <item>
              <title>番号なし</title>
              <link>https://autorace.jp/news/campaign/view/</link>
              <pubDate>Mon, 12 Oct 2026 09:00:00 +0900</pubDate>
            </item>
            <item>
              <title>日付が壊れている</title>
              <link>https://autorace.jp/news/2026/10/12/000004/</link>
              <pubDate>yesterday</pubDate>
            </item>
          </channel>
        </rss>
        XML;

    /**
     * @return void
     */
    #[Test]
    public function parsesEveryItemOfTheFixture(): void
    {
        $result = $this->source(Fixtures::read('autorace-jp/rss.xml'))->fetch(null);

        self::assertCount(15, $result->articles);
        self::assertSame([], $result->failures);
    }

    /**
     * @return void
     */
    #[Test]
    public function mapsTheFirstItem(): void
    {
        $article = $this->source(Fixtures::read('autorace-jp/rss.xml'))->fetch(null)->articles[0];

        self::assertSame('autorace.jp', $article->source);
        self::assertSame('AUTORACE.JP', $article->sourceName);
        self::assertSame('038810', $article->id);
        self::assertSame('https://autorace.jp/news/2026/10/12/038810/', $article->url);
        self::assertSame('【日刊提供】佐藤励は序盤展開悔やむ「前に出られていたら」落車避けて3着／飯塚', $article->title);
        self::assertSame(['ニュース', 'レース情報', '飯塚'], $article->categories);
        self::assertSame('2026-10-12T23:20:03+09:00', $article->publishedAt->format(DATE_ATOM));
        self::assertSame('Asia/Tokyo', $article->publishedAt->getTimezone()->getName());
        self::assertNull($article->summary);
        self::assertSame('https://autorace.jp/g2/20261008/images/1012/img-05.jpg', $article->thumbnailUrl);
    }

    /**
     * @return void
     */
    #[Test]
    public function normalizesTheBody(): void
    {
        $article = $this->source(Fixtures::read('autorace-jp/rss.xml'))->fetch(null)->articles[0];

        self::assertNotNull($article->bodyHtml);
        self::assertNotNull($article->bodyText);
        self::assertStringContainsString('src="https://autorace.jp/g2/20261008/images/1012/img-05.jpg"', $article->bodyHtml);
        self::assertStringContainsString('href="https://autorace.jp/g2/20261008/"', $article->bodyHtml);
        self::assertStringStartsWith('3着でレースを終えて悔しそうな表情の佐藤励', $article->bodyText);
        self::assertStringContainsString("\n佐藤励（26＝川口）は、序盤の展開を悔やんだ。", $article->bodyText);
        self::assertStringEndsWith('特設サイトへ', $article->bodyText);
    }

    /**
     * @return void
     */
    #[Test]
    public function filtersBySinceInclusive(): void
    {
        $source = $this->source(Fixtures::read('autorace-jp/rss.xml'));

        $sameSecond = $source->fetch(new DateTimeImmutable('2026-10-11 22:00:02 +09:00'))->articles;
        $midnight = $source->fetch(new DateTimeImmutable('2026-10-12 00:00:00 +09:00'))->articles;

        self::assertCount(7, $sameSecond);
        self::assertSame('2026-10-11T22:00:02+09:00', $sameSecond[6]->publishedAt->format(DATE_ATOM));
        self::assertCount(5, $midnight);
    }

    /**
     * @return void
     */
    #[Test]
    public function skipsBrokenItems(): void
    {
        $result = $this->source(self::BROKEN_FEED)->fetch(null);

        self::assertCount(1, $result->articles);
        self::assertSame('000001', $result->articles[0]->id);
        self::assertSame('<p>本文</p>', $result->articles[0]->bodyHtml);
        self::assertCount(3, $result->failures);
        self::assertSame('https://autorace.jp/news/2026/10/12/000002/', $result->failures[0]->url);
        self::assertSame('Feed item is missing link, title, or pubDate.', $result->failures[0]->message);
        self::assertSame('Could not derive the article id from the link.', $result->failures[1]->message);
        self::assertSame('Unparseable pubDate "yesterday".', $result->failures[2]->message);
        self::assertSame('autorace.jp', $result->failures[2]->source);
    }

    /**
     * @return void
     */
    #[Test]
    public function propagatesFeedFailures(): void
    {
        $this->expectException(HttpException::class);

        $this->source('', 500)->fetch(null);
    }

    /**
     * @param string $feed
     * @param int $status
     * @return \Shimomo\AutoraceNewsCollector\Sources\AutoraceJpSource
     */
    private function source(string $feed, int $status = 200): AutoraceJpSource
    {
        $client = new MockHttpClient(function (string $method, string $url) use ($feed, $status): MockResponse {
            self::assertSame('GET', $method);
            self::assertSame(AutoraceJpSource::FEED_URL, $url);

            return new MockResponse($feed, ['http_code' => $status]);
        });

        return new AutoraceJpSource(new HttpFetcher($client, new SpySleeper(), 0.0));
    }
}
```

- [ ] **Step 3: 落ちることを確かめる**

Run: `vendor/bin/phpunit tests/Sources/AutoraceJpSourceTest.php`
Expected: クラスが無く Error

- [ ] **Step 4: 実装する**

`src/Sources/AutoraceJpSource.php`:

```php
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
```

- [ ] **Step 5: 通ることと、規約を壊すと落ちることを確かめる**

Run: `vendor/bin/phpunit tests/Sources/AutoraceJpSourceTest.php && composer analyse && composer lint`
Expected: `OK (6 tests, ...)`

Run: 一時的に `$publishedAt < $since` を `$publishedAt <= $since` に変えて同じコマンド
Expected: `filtersBySinceInclusive` が落ちる（7件が6件になる）。確かめたら戻す

- [ ] **Step 6: ステージしてコミットコマンドを提示する**

```bash
git add src/Sources/AutoraceJpSource.php tests/Sources/AutoraceJpSourceTest.php tests/Support/Fixtures.php
git commit -m "feat: add the AUTORACE.JP RSS source"
```

---

### Task 7: YouTube 公式チャンネルのソース

**Files:**
- Create: `src/Sources/YouTubeSource.php`
- Test: `tests/Sources/YouTubeSourceTest.php`
- Fixture（既存）: `tests/fixtures/youtube/videos.xml`（15件。先頭は `byxqNooSqmI`、2026-10-12T13:30:45+00:00）

**Interfaces:**
- Consumes: Task 3, 5 と `Article` / `FetchResult` / `SourceFailure`
- Produces: `YouTubeSource::__construct(HttpFetcher $fetcher, string $channelId = YouTubeSource::CHANNEL_ID)`、`YouTubeSource::feedUrl(): string`、`key() === 'youtube'`、`name() === 'オートレース公式 YouTube'`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Sources/YouTubeSourceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Tests\Sources;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shimomo\AutoraceNewsCollector\Http\HttpFetcher;
use Shimomo\AutoraceNewsCollector\Sources\YouTubeSource;
use Shimomo\AutoraceNewsCollector\Tests\Support\Fixtures;
use Shimomo\AutoraceNewsCollector\Tests\Support\SpySleeper;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @author shimomo
 */
final class YouTubeSourceTest extends TestCase
{
    private const string BROKEN_FEED = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <feed xmlns:yt="http://www.youtube.com/xml/schemas/2015" xmlns:media="http://search.yahoo.com/mrss/" xmlns="http://www.w3.org/2005/Atom">
          <entry>
            <yt:videoId>ok123</yt:videoId>
            <title>説明なし</title>
            <published>2026-10-12T13:30:45+00:00</published>
            <media:group>
              <media:description></media:description>
            </media:group>
          </entry>
          <entry>
            <title>videoId なし</title>
            <published>2026-10-12T13:30:45+00:00</published>
          </entry>
          <entry>
            <yt:videoId>bad456</yt:videoId>
            <title>日付が壊れている</title>
            <published>soon</published>
          </entry>
        </feed>
        XML;

    /**
     * @return void
     */
    #[Test]
    public function parsesEveryEntryOfTheFixture(): void
    {
        $result = $this->source(Fixtures::read('youtube/videos.xml'))->fetch(null);

        self::assertCount(15, $result->articles);
        self::assertSame([], $result->failures);
    }

    /**
     * @return void
     */
    #[Test]
    public function mapsTheFirstEntry(): void
    {
        $article = $this->source(Fixtures::read('youtube/videos.xml'))->fetch(null)->articles[0];

        self::assertSame('youtube', $article->source);
        self::assertSame('オートレース公式 YouTube', $article->sourceName);
        self::assertSame('byxqNooSqmI', $article->id);
        self::assertSame('https://www.youtube.com/watch?v=byxqNooSqmI', $article->url);
        self::assertSame('飯塚オートレース中継 2026年10月12日 庄内のやきにく王国presentsＧⅡオーバルチャンピオンカップ　最終日', $article->title);
        self::assertSame([], $article->categories);
        self::assertSame('2026-10-12T22:30:45+09:00', $article->publishedAt->format(DATE_ATOM));
        self::assertNull($article->summary);
        self::assertNull($article->bodyHtml);
        self::assertNotNull($article->bodyText);
        self::assertStringStartsWith('#オートレース #autorace #公営競技', $article->bodyText);
        self::assertStringContainsString('https://autorace.jp/news/campaign/view/?utm_source=youtube&utm_medium=social', $article->bodyText);
        self::assertSame('https://i3.ytimg.com/vi/byxqNooSqmI/hqdefault.jpg', $article->thumbnailUrl);
    }

    /**
     * @return void
     */
    #[Test]
    public function filtersBySince(): void
    {
        $articles = $this->source(Fixtures::read('youtube/videos.xml'))->fetch(new DateTimeImmutable('2026-10-12 00:00:00 +09:00'))->articles;

        self::assertCount(6, $articles);
        self::assertSame('2026-10-12T07:00:06+09:00', $articles[5]->publishedAt->format(DATE_ATOM));
    }

    /**
     * @return void
     */
    #[Test]
    public function skipsEntryWithoutVideoId(): void
    {
        $result = $this->source(self::BROKEN_FEED)->fetch(null);

        self::assertCount(1, $result->articles);
        self::assertSame('ok123', $result->articles[0]->id);
        self::assertNull($result->articles[0]->bodyText);
        self::assertNull($result->articles[0]->thumbnailUrl);
        self::assertCount(2, $result->failures);
        self::assertNull($result->failures[0]->url);
        self::assertSame('Feed entry is missing videoId, title, or published.', $result->failures[0]->message);
        self::assertSame('https://www.youtube.com/watch?v=bad456', $result->failures[1]->url);
        self::assertSame('Unparseable published "soon".', $result->failures[1]->message);
    }

    /**
     * @return void
     */
    #[Test]
    public function buildsTheFeedUrlFromTheChannelId(): void
    {
        $fetcher = new HttpFetcher(new MockHttpClient(), new SpySleeper(), 0.0);

        self::assertSame('https://www.youtube.com/feeds/videos.xml?channel_id=UCJAPqcMNYk3HMknRuPMNvKA', (new YouTubeSource($fetcher))->feedUrl());
        self::assertSame('https://www.youtube.com/feeds/videos.xml?channel_id=UCother', (new YouTubeSource($fetcher, 'UCother'))->feedUrl());
    }

    /**
     * @param string $feed
     * @return \Shimomo\AutoraceNewsCollector\Sources\YouTubeSource
     */
    private function source(string $feed): YouTubeSource
    {
        $client = new MockHttpClient(function (string $method, string $url) use ($feed): MockResponse {
            self::assertSame('GET', $method);
            self::assertSame('https://www.youtube.com/feeds/videos.xml?channel_id=' . YouTubeSource::CHANNEL_ID, $url);

            return new MockResponse($feed);
        });

        return new YouTubeSource(new HttpFetcher($client, new SpySleeper(), 0.0));
    }
}
```

- [ ] **Step 2: 落ちることを確かめる**

Run: `vendor/bin/phpunit tests/Sources/YouTubeSourceTest.php`
Expected: クラスが無く Error

- [ ] **Step 3: 実装する**

`src/Sources/YouTubeSource.php`:

```php
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
```

- [ ] **Step 4: 通ることを確かめる**

Run: `vendor/bin/phpunit tests/Sources/YouTubeSourceTest.php && composer analyse && composer lint`
Expected: `OK (5 tests, ...)`

- [ ] **Step 5: ステージしてコミットコマンドを提示する**

```bash
git add src/Sources/YouTubeSource.php tests/Sources/YouTubeSourceTest.php
git commit -m "feat: add the official YouTube channel source"
```

---

### Task 8: WordPress REST ソース（川口・伊勢崎・浜松・山陽）

**Files:**
- Create: `src/Sources/WordPressSite.php`, `src/Sources/WordPressSource.php`
- Test: `tests/Sources/WordPressSourceTest.php`
- Fixture（既存）: `tests/fixtures/wordpress/{kawaguchi-news,kawaguchi-interview,isesaki-posts,hamamatsu-newsinfo,sanyo-posts}.json`（各10件。`_embed=wp:featuredmedia,wp:term` と `_fields` 付きで採取）

**Interfaces:**
- Consumes: Task 3, 4 と `Article` / `FetchResult` / `SourceFailure`
- Produces: `WordPressSite::__construct(string $key, string $name, string $baseUrl, string $restType, array $fixedCategories = [])`、`endpoint(): string`、プリセット `kawaguchiNews()` / `kawaguchiInterview()` / `isesaki()` / `hamamatsu()` / `sanyo()`
- Produces: `WordPressSource::__construct(WordPressSite $site, HttpFetcher $fetcher, int $maxPages = 3, BodyNormalizer $normalizer = new BodyNormalizer())`、`url(int $page, ?DateTimeImmutable $since): string`、`WordPressSource::PER_PAGE`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Sources/WordPressSourceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Tests\Sources;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shimomo\AutoraceNewsCollector\Article;
use Shimomo\AutoraceNewsCollector\Http\HttpFetcher;
use Shimomo\AutoraceNewsCollector\Sources\WordPressSite;
use Shimomo\AutoraceNewsCollector\Sources\WordPressSource;
use Shimomo\AutoraceNewsCollector\Tests\Support\Fixtures;
use Shimomo\AutoraceNewsCollector\Tests\Support\SpySleeper;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @author shimomo
 */
final class WordPressSourceTest extends TestCase
{
    /**
     * @return void
     */
    #[Test]
    public function buildsTheQueryWithoutSince(): void
    {
        $url = $this->source(WordPressSite::kawaguchiNews(), [])->url(1, null);
        $query = self::query($url);

        self::assertStringStartsWith('https://www.kawaguchiauto.jp/wp-json/wp/v2/news?', $url);
        self::assertSame('100', $query['per_page']);
        self::assertSame('1', $query['page']);
        self::assertSame('date', $query['orderby']);
        self::assertSame('desc', $query['order']);
        self::assertSame('wp:featuredmedia,wp:term', $query['_embed']);
        self::assertSame('id,date_gmt,link,title,content,excerpt,_links,_embedded', $query['_fields']);
        self::assertArrayNotHasKey('after', $query);
    }

    /**
     * @return void
     */
    #[Test]
    public function buildsAfterFromSinceMinusOneSecondInUtc(): void
    {
        $query = self::query($this->source(WordPressSite::sanyo(), [])->url(2, new DateTimeImmutable('2026-10-12 00:00:00 +09:00')));

        self::assertSame('2', $query['page']);
        self::assertSame('2026-10-11T14:59:59+00:00', $query['after']);
    }

    /**
     * @return void
     */
    #[Test]
    public function mapsKawaguchiNews(): void
    {
        $article = $this->fixtureArticles(WordPressSite::kawaguchiNews(), 'wordpress/kawaguchi-news.json')[0];

        self::assertSame('kawaguchi', $article->source);
        self::assertSame('川口オートレース', $article->sourceName);
        self::assertSame('13509', $article->id);
        self::assertSame('https://www.kawaguchiauto.jp/news/20261004/', $article->url);
        self::assertSame('【10月16日～20日】非開催払戻休止のお知らせ', $article->title);
        self::assertSame(['重要なニュース'], $article->categories);
        self::assertSame('2026-10-04T08:00:00+09:00', $article->publishedAt->format(DATE_ATOM));
        self::assertSame('Asia/Tokyo', $article->publishedAt->getTimezone()->getName());
        self::assertNotNull($article->summary);
        self::assertStringStartsWith('10月16日(金)～20日(火)は、設備点検のため', $article->summary);
        self::assertNotNull($article->bodyHtml);
        self::assertNotNull($article->bodyText);
        self::assertStringContainsString('設備点検', $article->bodyText);
        self::assertSame('https://www.kawaguchiauto.jp/wp-content/uploads/2026/10/1016.jpg', $article->thumbnailUrl);
    }

    /**
     * @return void
     */
    #[Test]
    public function mapsKawaguchiInterview(): void
    {
        $article = $this->fixtureArticles(WordPressSite::kawaguchiInterview(), 'wordpress/kawaguchi-interview.json')[0];

        self::assertSame('kawaguchi', $article->source);
        self::assertSame('13596', $article->id);
        self::assertSame('https://www.kawaguchiauto.jp/interview/20261007/', $article->url);
        self::assertSame('デイリースポーツ杯 黒川 京介選手(2026/10/7)', $article->title);
        self::assertSame(['選手インタビュー'], $article->categories);
        self::assertSame('2026-10-07T18:42:39+09:00', $article->publishedAt->format(DATE_ATOM));
        self::assertNotNull($article->summary);
        self::assertStringStartsWith('ありがとうございます。', $article->summary);
        self::assertNotNull($article->bodyText);
        self::assertStringContainsString('今年9回目の優勝', $article->bodyText);
        self::assertSame('https://www.kawaguchiauto.jp/wp-content/uploads/2026/10/winner_20261007-1.jpg', $article->thumbnailUrl);
    }

    /**
     * @return void
     */
    #[Test]
    public function mapsIsesakiIncludingEmptyBodies(): void
    {
        $articles = $this->fixtureArticles(WordPressSite::isesaki(), 'wordpress/isesaki-posts.json');

        self::assertSame('isesaki', $articles[0]->source);
        self::assertSame('伊勢崎オートレース', $articles[0]->sourceName);
        self::assertSame('7161', $articles[0]->id);
        self::assertSame('https://www.isesaki-auto.jp/7161/', $articles[0]->url);
        self::assertSame('おトクにPLAY!オッズパーク杯伊勢崎オートアフター5ナイター　優勝者は櫻井晴光選手', $articles[0]->title);
        self::assertSame(['お知らせ'], $articles[0]->categories);
        self::assertSame('2026-10-07T21:14:40+09:00', $articles[0]->publishedAt->format(DATE_ATOM));
        self::assertNull($articles[0]->summary);
        self::assertNull($articles[0]->bodyHtml);
        self::assertNull($articles[0]->bodyText);
        self::assertNull($articles[0]->thumbnailUrl);

        self::assertSame('7137', $articles[1]->id);
        self::assertNotNull($articles[1]->bodyText);
        self::assertStringContainsString('横断幕掲出の注意事項', $articles[1]->bodyText);
        self::assertSame('https://www.isesaki-auto.jp/wp-content/uploads/2026/07/8f6a3d5697272cb51d6bd3c5a48c3424.jpg', $articles[1]->thumbnailUrl);
    }

    /**
     * @return void
     */
    #[Test]
    public function mapsHamamatsuWithoutCategories(): void
    {
        $article = $this->fixtureArticles(WordPressSite::hamamatsu(), 'wordpress/hamamatsu-newsinfo.json')[0];

        self::assertSame('hamamatsu', $article->source);
        self::assertSame('浜松オート', $article->sourceName);
        self::assertSame('4969', $article->id);
        self::assertSame('https://www.hamamatsuauto.jp/newsinfo/4969', $article->url);
        self::assertSame('10月18日（日）オートレース一宮　北市唯選手トークショー実施のお知らせ', $article->title);
        self::assertSame([], $article->categories);
        self::assertSame('2026-09-22T10:39:30+09:00', $article->publishedAt->format(DATE_ATOM));
        self::assertNull($article->summary);
        self::assertNotNull($article->bodyText);
        self::assertStringContainsString('北市唯選手トークショー', $article->bodyText);
    }

    /**
     * @return void
     */
    #[Test]
    public function mapsSanyo(): void
    {
        $article = $this->fixtureArticles(WordPressSite::sanyo(), 'wordpress/sanyo-posts.json')[0];

        self::assertSame('sanyo', $article->source);
        self::assertSame('山陽オート', $article->sourceName);
        self::assertSame('3974', $article->id);
        self::assertSame('https://sanyoauto.jp/topics/banner-guide/202610123974.html', $article->url);
        self::assertSame('【10/28(水)必着】日刊スポーツGⅡ第37回若獅子杯争奪戦　横断幕の掲出について', $article->title);
        self::assertSame(['横断幕の掲出'], $article->categories);
        self::assertSame('2026-10-12T22:29:22+09:00', $article->publishedAt->format(DATE_ATOM));
        self::assertNotNull($article->bodyText);
        self::assertStringStartsWith('２０２６年１１月１１日(水)～１１月１５日(日)開催', $article->bodyText);
    }

    /**
     * @return void
     */
    #[Test]
    public function followsPagesWhenSinceIsGiven(): void
    {
        /** @var list<string> $urls */
        $urls = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$urls): MockResponse {
            $urls[] = $url;

            return self::wpResponse([self::post(count($urls), '2026-10-05T00:00:00')], 3);
        });
        $source = new WordPressSource(WordPressSite::sanyo(), new HttpFetcher($client, new SpySleeper(), 0.0), 2);

        $result = $source->fetch(new DateTimeImmutable('2026-10-01 00:00:00 +09:00'));

        self::assertCount(2, $urls);
        self::assertStringContainsString('page=1', $urls[0]);
        self::assertStringContainsString('page=2', $urls[1]);
        self::assertCount(2, $result->articles);
        self::assertSame(['1', '2'], [$result->articles[0]->id, $result->articles[1]->id]);
    }

    /**
     * @return void
     */
    #[Test]
    public function fetchesOnlyTheFirstPageWithoutSince(): void
    {
        $client = new MockHttpClient([self::wpResponse([self::post(1)], 3)]);
        $source = new WordPressSource(WordPressSite::sanyo(), new HttpFetcher($client, new SpySleeper(), 0.0));

        $result = $source->fetch(null);

        self::assertSame(1, $client->getRequestsCount());
        self::assertCount(1, $result->articles);
    }

    /**
     * @return void
     */
    #[Test]
    public function stopsAtTotalPages(): void
    {
        $client = new MockHttpClient([self::wpResponse([self::post(1, '2026-10-05T00:00:00')], 1)]);
        $source = new WordPressSource(WordPressSite::sanyo(), new HttpFetcher($client, new SpySleeper(), 0.0));

        $source->fetch(new DateTimeImmutable('2026-10-01 00:00:00 +09:00'));

        self::assertSame(1, $client->getRequestsCount());
    }

    /**
     * @return void
     */
    #[Test]
    public function treatsMissingTotalPagesHeaderAsOnePage(): void
    {
        $client = new MockHttpClient([new MockResponse((string) json_encode([self::post(1, '2026-10-05T00:00:00')]))]);
        $source = new WordPressSource(WordPressSite::sanyo(), new HttpFetcher($client, new SpySleeper(), 0.0));

        $source->fetch(new DateTimeImmutable('2026-10-01 00:00:00 +09:00'));

        self::assertSame(1, $client->getRequestsCount());
    }

    /**
     * @return void
     */
    #[Test]
    public function filtersBySinceInclusive(): void
    {
        // 2026-10-11T15:00:00Z は JST の 2026-10-12 00:00:00
        $responses = [self::wpResponse([self::post(1, '2026-10-11T15:00:00'), self::post(2, '2026-10-11T14:59:59')])];

        $result = $this->source(WordPressSite::sanyo(), $responses)->fetch(new DateTimeImmutable('2026-10-12 00:00:00 +09:00'));

        self::assertCount(1, $result->articles);
        self::assertSame('1', $result->articles[0]->id);
    }

    /**
     * @return void
     */
    #[Test]
    public function skipsPostsWithoutRequiredFields(): void
    {
        $responses = [new MockResponse((string) json_encode([
            ['link' => 'https://example.test/no-id/'],
            self::post(2, 'soon'),
            self::post(3, '2026-10-01T00:00:00', '<p></p>'),
            7,
            self::post(5),
        ]))];

        $result = $this->source(WordPressSite::sanyo(), $responses)->fetch(null);

        self::assertCount(1, $result->articles);
        self::assertSame('5', $result->articles[0]->id);
        self::assertCount(4, $result->failures);
        self::assertSame('https://example.test/no-id/', $result->failures[0]->url);
        self::assertSame('Post is missing id, link, date_gmt, or title.', $result->failures[0]->message);
        self::assertSame('Unparseable date_gmt "soon".', $result->failures[1]->message);
        self::assertSame('Post title is empty.', $result->failures[2]->message);
        self::assertSame('Post entry is not an object.', $result->failures[3]->message);
        self::assertSame('sanyo', $result->failures[3]->source);
    }

    /**
     * @return void
     */
    #[Test]
    public function toleratesMissingEmbedded(): void
    {
        $responses = [self::wpResponse([self::post(1, '2026-10-01T00:00:00', 'T', '<p><img src="/a.jpg">本文</p>')])];

        $article = $this->source(WordPressSite::kawaguchiInterview(), $responses)->fetch(null)->articles[0];

        self::assertSame(['選手インタビュー'], $article->categories);
        self::assertSame('https://example.test/a.jpg', $article->thumbnailUrl);
    }

    /**
     * @return void
     */
    #[Test]
    public function ignoresFeaturedMediaErrorObject(): void
    {
        $embedded = ['wp:featuredmedia' => [['code' => 'rest_forbidden', 'data' => ['status' => 401]]], 'wp:term' => [[['name' => 'お知らせ']], []]];
        $responses = [self::wpResponse([self::post(1, '2026-10-01T00:00:00', 'T', '<p>本文</p>', $embedded)])];

        $article = $this->source(WordPressSite::isesaki(), $responses)->fetch(null)->articles[0];

        self::assertNull($article->thumbnailUrl);
        self::assertSame(['お知らせ'], $article->categories);
    }

    /**
     * @return void
     */
    #[Test]
    public function decodesTitleEntitiesAndStripsTags(): void
    {
        $responses = [self::wpResponse([self::post(1, '2026-10-01T00:00:00', 'A &amp; B &#8211; <em>C</em>')])];

        $article = $this->source(WordPressSite::sanyo(), $responses)->fetch(null)->articles[0];

        self::assertSame('A & B – C', $article->title);
    }

    /**
     * @return void
     */
    #[Test]
    public function prependsFixedCategoriesToTermNames(): void
    {
        $embedded = ['wp:term' => [[['name' => 'イベント'], ['name' => '']]]];
        $responses = [self::wpResponse([self::post(1, '2026-10-01T00:00:00', 'T', '<p>本文</p>', $embedded)])];

        $article = $this->source(WordPressSite::kawaguchiInterview(), $responses)->fetch(null)->articles[0];

        self::assertSame(['選手インタビュー', 'イベント'], $article->categories);
    }

    /**
     * @return void
     */
    #[Test]
    public function presetsPointAtTheRightEndpoints(): void
    {
        self::assertSame('https://www.kawaguchiauto.jp/wp-json/wp/v2/news', WordPressSite::kawaguchiNews()->endpoint());
        self::assertSame('https://www.kawaguchiauto.jp/wp-json/wp/v2/interview', WordPressSite::kawaguchiInterview()->endpoint());
        self::assertSame('https://www.isesaki-auto.jp/wp-json/wp/v2/posts', WordPressSite::isesaki()->endpoint());
        self::assertSame('https://www.hamamatsuauto.jp/wp-json/wp/v2/newsinfo', WordPressSite::hamamatsu()->endpoint());
        self::assertSame('https://sanyoauto.jp/wp-json/wp/v2/posts', WordPressSite::sanyo()->endpoint());
    }

    /**
     * @param \Shimomo\AutoraceNewsCollector\Sources\WordPressSite $site
     * @param string $fixture
     * @return list<\Shimomo\AutoraceNewsCollector\Article>
     */
    private function fixtureArticles(WordPressSite $site, string $fixture): array
    {
        $client = new MockHttpClient(function (string $method, string $url) use ($site, $fixture): MockResponse {
            self::assertSame('GET', $method);
            self::assertStringStartsWith($site->endpoint() . '?', $url);

            return new MockResponse(Fixtures::read($fixture), ['response_headers' => ['x-wp-totalpages' => '1']]);
        });

        $result = $this->source($site, $client)->fetch(null);

        self::assertCount(10, $result->articles);
        self::assertSame([], $result->failures);

        foreach ($result->articles as $article) {
            self::assertInstanceOf(Article::class, $article);
        }

        return $result->articles;
    }

    /**
     * @param \Shimomo\AutoraceNewsCollector\Sources\WordPressSite $site
     * @param list<\Symfony\Component\HttpClient\Response\MockResponse>|\Symfony\Component\HttpClient\MockHttpClient $responses
     * @return \Shimomo\AutoraceNewsCollector\Sources\WordPressSource
     */
    private function source(WordPressSite $site, array|MockHttpClient $responses): WordPressSource
    {
        $client = $responses instanceof MockHttpClient ? $responses : new MockHttpClient($responses);

        return new WordPressSource($site, new HttpFetcher($client, new SpySleeper(), 0.0));
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param int $totalPages
     * @return \Symfony\Component\HttpClient\Response\MockResponse
     */
    private static function wpResponse(array $items, int $totalPages = 1): MockResponse
    {
        return new MockResponse((string) json_encode($items), ['response_headers' => ['x-wp-totalpages' => (string) $totalPages]]);
    }

    /**
     * @param int $id
     * @param string $dateGmt
     * @param string $title
     * @param string $content
     * @param ?array<string, mixed> $embedded
     * @return array<string, mixed>
     */
    private static function post(int $id, string $dateGmt = '2026-10-01T00:00:00', string $title = 'タイトル', string $content = '<p>本文</p>', ?array $embedded = null): array
    {
        $post = [
            'id' => $id,
            'date_gmt' => $dateGmt,
            'link' => 'https://example.test/' . $id . '/',
            'title' => ['rendered' => $title],
            'content' => ['rendered' => $content],
            'excerpt' => ['rendered' => ''],
        ];

        if ($embedded !== null) {
            $post['_embedded'] = $embedded;
        }

        return $post;
    }

    /**
     * @param string $url
     * @return array<array-key, mixed>
     */
    private static function query(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }
}
```

- [ ] **Step 2: 落ちることを確かめる**

Run: `vendor/bin/phpunit tests/Sources/WordPressSourceTest.php`
Expected: クラスが無く Error

- [ ] **Step 3: 実装する**

`src/Sources/WordPressSite.php`:

```php
<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Sources;

/**
 * WordPress 製のレース場公式サイト1つ分の設定。
 *
 * @author shimomo
 */
final readonly class WordPressSite
{
    /**
     * @param string $key ソースキー
     * @param string $name 表示名
     * @param string $baseUrl 末尾スラッシュなし
     * @param string $restType wp/v2 直下のルート名（posts / news / interview / newsinfo）
     * @param list<string> $fixedCategories 分類の先頭に固定で足すラベル
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $baseUrl,
        public string $restType,
        public array $fixedCategories = [],
    ) {
    }

    /**
     * @return self
     */
    public static function kawaguchiNews(): self
    {
        return new self('kawaguchi', '川口オートレース', 'https://www.kawaguchiauto.jp', 'news');
    }

    /**
     * interview は分類を持たないので固定ラベルで区別する。
     *
     * @return self
     */
    public static function kawaguchiInterview(): self
    {
        return new self('kawaguchi', '川口オートレース', 'https://www.kawaguchiauto.jp', 'interview', ['選手インタビュー']);
    }

    /**
     * @return self
     */
    public static function isesaki(): self
    {
        return new self('isesaki', '伊勢崎オートレース', 'https://www.isesaki-auto.jp', 'posts');
    }

    /**
     * newsinfo の区分（news / race / event）は REST に露出していないので分類は取れない。
     *
     * @return self
     */
    public static function hamamatsu(): self
    {
        return new self('hamamatsu', '浜松オート', 'https://www.hamamatsuauto.jp', 'newsinfo');
    }

    /**
     * @return self
     */
    public static function sanyo(): self
    {
        return new self('sanyo', '山陽オート', 'https://sanyoauto.jp', 'posts');
    }

    /**
     * @return string
     */
    public function endpoint(): string
    {
        return $this->baseUrl . '/wp-json/wp/v2/' . $this->restType;
    }
}
```

`src/Sources/WordPressSource.php`:

```php
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
```

- [ ] **Step 4: 通ることと、規約を壊すと落ちることを確かめる**

Run: `vendor/bin/phpunit tests/Sources/WordPressSourceTest.php && composer analyse && composer lint`
Expected: `OK (19 tests, ...)`

Run: 一時的に `url()` の `sub(new DateInterval('PT1S'))` を外して同じコマンド
Expected: `buildsAfterFromSinceMinusOneSecondInUtc` が落ちる。確かめたら戻す

- [ ] **Step 5: ステージしてコミットコマンドを提示する**

```bash
git add src/Sources/WordPressSite.php src/Sources/WordPressSource.php tests/Sources/WordPressSourceTest.php
git commit -m "feat: add the WordPress REST source with the five track presets"
```

---

### Task 9: 日刊スポーツのソース（Atom ＋ 記事ページ）

**Files:**
- Create: `src/Sources/NikkanSportsSource.php`
- Test: `tests/Sources/NikkanSportsSourceTest.php`
- Fixture（既存）: `tests/fixtures/nikkansports/atom.xml`（10件。先頭は `202610120001800`、2026-10-12T22:51:55+09:00。12日が4件、11日が3件、10日が3件）、`tests/fixtures/nikkansports/article-202610120001800.html`

**Interfaces:**
- Consumes: Task 3, 4, 5 と `Article` / `FetchResult` / `SourceFailure`
- Produces: `NikkanSportsSource::__construct(HttpFetcher $fetcher, BodyNormalizer $normalizer = new BodyNormalizer())`、`NikkanSportsSource::FEED_URL`、`key() === 'nikkansports'`、`name() === '日刊スポーツ'`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Sources/NikkanSportsSourceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Tests\Sources;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shimomo\AutoraceNewsCollector\Http\HttpFetcher;
use Shimomo\AutoraceNewsCollector\Sources\NikkanSportsSource;
use Shimomo\AutoraceNewsCollector\Tests\Support\Fixtures;
use Shimomo\AutoraceNewsCollector\Tests\Support\SpySleeper;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @author shimomo
 */
final class NikkanSportsSourceTest extends TestCase
{
    private const string FIRST_ARTICLE_URL = 'https://www.nikkansports.com/public_race/news/202610120001800.html';

    private const string BROKEN_FEED = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <feed xmlns="http://www.w3.org/2005/Atom">
          <entry>
            <title>リンクなし</title>
            <published>2026-10-12T22:51:55+09:00</published>
          </entry>
          <entry>
            <title>番号なし</title>
            <link rel="alternate" type="text/html" href="https://www.nikkansports.com/public_race/auto/index.html" />
            <published>2026-10-12T22:51:55+09:00</published>
          </entry>
          <entry>
            <title>正常</title>
            <link rel="alternate" type="text/html" href="https://www.nikkansports.com/public_race/news/202610120001800.html" />
            <published>2026-10-12T22:51:55+09:00</published>
            <summary>要約</summary>
          </entry>
        </feed>
        XML;

    private const string PAGE_WITHOUT_BODY = '<html><body><section id="articleMain"><p>本文の無いレイアウト</p></section></body></html>';

    /**
     * @return void
     */
    #[Test]
    public function fetchesEveryEntryAndItsArticlePage(): void
    {
        $client = $this->client(Fixtures::read('nikkansports/atom.xml'));

        $result = $this->source($client)->fetch(null);

        self::assertCount(10, $result->articles);
        self::assertSame(11, $client->getRequestsCount());
        // 記事ページのフィクスチャは先頭の1件分だけなので、残り9件は 404 で要約のみになる
        self::assertCount(9, $result->failures);
        self::assertNotNull($result->articles[0]->bodyHtml);
        self::assertNull($result->articles[1]->bodyHtml);
        self::assertNotNull($result->articles[1]->summary);
        self::assertStringEndsWith('.html', (string) $result->failures[0]->url);
        self::assertStringContainsString('returned HTTP 404.', $result->failures[0]->message);
        self::assertSame('nikkansports', $result->failures[0]->source);
    }

    /**
     * @return void
     */
    #[Test]
    public function mapsTheFirstEntry(): void
    {
        $article = $this->source($this->client(Fixtures::read('nikkansports/atom.xml')))->fetch(null)->articles[0];

        self::assertSame('nikkansports', $article->source);
        self::assertSame('日刊スポーツ', $article->sourceName);
        self::assertSame('202610120001800', $article->id);
        self::assertSame(self::FIRST_ARTICLE_URL, $article->url);
        self::assertSame('【オートレース】佐藤励は優勝戦３着も序盤の展開を悔やむ「チャンスがあったかも…」／飯塚G2', $article->title);
        self::assertSame([], $article->categories);
        self::assertSame('2026-10-12T22:51:55+09:00', $article->publishedAt->format(DATE_ATOM));
        self::assertNotNull($article->summary);
        self::assertStringStartsWith('佐藤励（26＝川口）は、序盤の展開を悔やんだ。', $article->summary);
        self::assertSame('https://www.nikkansports.com/public_race/news/img/202610120001800-w500_0.jpg', $article->thumbnailUrl);
    }

    /**
     * @return void
     */
    #[Test]
    public function extractsPrologueAndBodyFromTheArticlePage(): void
    {
        $article = $this->source($this->client(Fixtures::read('nikkansports/atom.xml')))->fetch(null)->articles[0];

        self::assertNotNull($article->bodyHtml);
        self::assertNotNull($article->bodyText);
        self::assertStringStartsWith('<p class="prologue">＜飯塚オート：オーバルチャンピオンカップ＞◇G2◇最終日◇12日</p>', $article->bodyHtml);
        self::assertStringContainsString('<p>佐藤励（26＝川口）は、序盤の展開を悔やんだ。', $article->bodyHtml);
        self::assertStringNotContainsString('<script', $article->bodyHtml);
        self::assertStringStartsWith('＜飯塚オート：オーバルチャンピオンカップ＞◇G2◇最終日◇12日', $article->bodyText);
        self::assertStringContainsString("\n佐藤励（26＝川口）は、序盤の展開を悔やんだ。", $article->bodyText);
        self::assertStringEndsWith('決意を新たにしていた。', $article->bodyText);
    }

    /**
     * @return void
     */
    #[Test]
    public function skipsArticlePagesFilteredBySince(): void
    {
        $client = $this->client(Fixtures::read('nikkansports/atom.xml'));

        $result = $this->source($client)->fetch(new DateTimeImmutable('2026-10-12 22:00:00 +09:00'));

        self::assertCount(3, $result->articles);
        self::assertSame(4, $client->getRequestsCount());
        self::assertCount(2, $result->failures);
    }

    /**
     * @return void
     */
    #[Test]
    public function returnsSummaryOnlyWhenBodyIsMissing(): void
    {
        $client = $this->client(Fixtures::read('nikkansports/atom.xml'), self::PAGE_WITHOUT_BODY);

        $result = $this->source($client)->fetch(new DateTimeImmutable('2026-10-12 22:30:00 +09:00'));

        self::assertCount(1, $result->articles);
        self::assertSame(2, $client->getRequestsCount());
        self::assertNull($result->articles[0]->bodyHtml);
        self::assertNull($result->articles[0]->bodyText);
        self::assertNotNull($result->articles[0]->summary);
        self::assertSame('https://www.nikkansports.com/public_race/news/img/202610120001800-w500_0.jpg', $result->articles[0]->thumbnailUrl);
        self::assertCount(1, $result->failures);
        self::assertSame(self::FIRST_ARTICLE_URL, $result->failures[0]->url);
        self::assertSame('Article body (#articleMain div#news) was not found.', $result->failures[0]->message);
    }

    /**
     * @return void
     */
    #[Test]
    public function skipsBrokenEntry(): void
    {
        $result = $this->source($this->client(self::BROKEN_FEED))->fetch(null);

        self::assertCount(1, $result->articles);
        self::assertSame('202610120001800', $result->articles[0]->id);
        self::assertSame('要約', $result->articles[0]->summary);
        self::assertNotNull($result->articles[0]->bodyText);
        self::assertCount(2, $result->failures);
        self::assertNull($result->failures[0]->url);
        self::assertSame('Feed entry is missing link, title, or published.', $result->failures[0]->message);
        self::assertSame('https://www.nikkansports.com/public_race/auto/index.html', $result->failures[1]->url);
        self::assertSame('Could not derive the article id from the link.', $result->failures[1]->message);
    }

    /**
     * フィードは FEED_URL、記事ページは先頭1件のフィクスチャ（または差し替え HTML）、それ以外は 404。
     *
     * @param string $feed
     * @param ?string $articlePage null なら先頭の記事 URL だけフィクスチャを返す
     * @return \Symfony\Component\HttpClient\MockHttpClient
     */
    private function client(string $feed, ?string $articlePage = null): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url) use ($feed, $articlePage): MockResponse {
            self::assertSame('GET', $method);

            if ($url === NikkanSportsSource::FEED_URL) {
                return new MockResponse($feed);
            }

            if ($articlePage !== null) {
                return new MockResponse($articlePage);
            }

            if ($url === self::FIRST_ARTICLE_URL) {
                return new MockResponse(Fixtures::read('nikkansports/article-202610120001800.html'));
            }

            return new MockResponse('', ['http_code' => 404]);
        });
    }

    /**
     * @param \Symfony\Component\HttpClient\MockHttpClient $client
     * @return \Shimomo\AutoraceNewsCollector\Sources\NikkanSportsSource
     */
    private function source(MockHttpClient $client): NikkanSportsSource
    {
        return new NikkanSportsSource(new HttpFetcher($client, new SpySleeper(), 0.0));
    }
}
```

- [ ] **Step 2: 落ちることを確かめる**

Run: `vendor/bin/phpunit tests/Sources/NikkanSportsSourceTest.php`
Expected: クラスが無く Error

- [ ] **Step 3: 実装する**

`src/Sources/NikkanSportsSource.php`:

```php
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
```

- [ ] **Step 4: 通ることと、規約を壊すと落ちることを確かめる**

Run: `vendor/bin/phpunit tests/Sources/NikkanSportsSourceTest.php && composer analyse && composer lint`
Expected: `OK (6 tests, ...)`。`extractsPrologueAndBodyFromTheArticlePage` が落ちる場合は `HtmlDocument::fromString()` に渡す文字列の先頭が `<?xml encoding="UTF-8"?>` か、XPath の `section` が libxml に要素として読めているか（`vendor/bin/phpunit --filter extracts` の失敗メッセージで `bodyHtml` の先頭を見る）を確かめる

Run: 一時的に `fetch()` の since 判定を記事ページ取得の後ろへ動かして同じコマンド
Expected: `skipsArticlePagesFilteredBySince` が落ちる（4リクエストが11になる）。確かめたら戻す

- [ ] **Step 5: ステージしてコミットコマンドを提示する**

```bash
git add src/Sources/NikkanSportsSource.php tests/Sources/NikkanSportsSourceTest.php
git commit -m "feat: add the Nikkan Sports source with article body extraction"
```

---

### Task 10: `Collector` と既定のソース構成

**Files:**
- Create: `src/Collector.php`
- Create: `tests/Support/StubSource.php`
- Test: `tests/CollectorTest.php`

**Interfaces:**
- Consumes: すべてのソース（Task 6〜9）、`HttpFetcher` / `NativeSleeper`（Task 3）、`CollectResult` / `SourceFailure`（Task 2）
- Produces: `Collector::__construct(array $sources)`、`Collector::default(?HttpFetcher $fetcher = null): self`、`sources(): list<Source>`、`collect(?DateTimeImmutable $since = null): CollectResult`
- Produces: `tests/Support/StubSource`

- [ ] **Step 1: テスト補助の `StubSource` を書く**

`tests/Support/StubSource.php`:

```php
<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Tests\Support;

use DateTimeImmutable;
use Shimomo\AutoraceNewsCollector\Article;
use Shimomo\AutoraceNewsCollector\Contracts\Source as SourceContract;
use Shimomo\AutoraceNewsCollector\FetchResult;
use Shimomo\AutoraceNewsCollector\SourceFailure;
use Throwable;

/**
 * 決め打ちの結果を返す Source。受け取った since を記録する。
 *
 * @author shimomo
 */
final class StubSource implements SourceContract
{
    /**
     * @var list<?\DateTimeImmutable>
     */
    public array $received = [];

    /**
     * @param string $key
     * @param list<\Shimomo\AutoraceNewsCollector\Article> $articles
     * @param list<\Shimomo\AutoraceNewsCollector\SourceFailure> $failures
     * @param ?\Throwable $throws fetch() で投げる例外
     */
    public function __construct(
        private string $key,
        private array $articles = [],
        private array $failures = [],
        private ?Throwable $throws = null,
    ) {
    }

    /**
     * @return string
     */
    #[\Override]
    public function key(): string
    {
        return $this->key;
    }

    /**
     * @return string
     */
    #[\Override]
    public function name(): string
    {
        return 'stub ' . $this->key;
    }

    /**
     * @param ?\DateTimeImmutable $since
     * @return \Shimomo\AutoraceNewsCollector\FetchResult
     */
    #[\Override]
    public function fetch(?DateTimeImmutable $since): FetchResult
    {
        $this->received[] = $since;

        if ($this->throws !== null) {
            throw $this->throws;
        }

        return new FetchResult($this->articles, $this->failures);
    }
}
```

- [ ] **Step 2: 失敗するテストを書く**

`tests/CollectorTest.php`:

```php
<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector\Tests;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Shimomo\AutoraceNewsCollector\Article;
use Shimomo\AutoraceNewsCollector\Collector;
use Shimomo\AutoraceNewsCollector\Contracts\Source as SourceContract;
use Shimomo\AutoraceNewsCollector\Http\HttpFetcher;
use Shimomo\AutoraceNewsCollector\SourceFailure;
use Shimomo\AutoraceNewsCollector\Sources\AutoraceJpSource;
use Shimomo\AutoraceNewsCollector\Sources\NikkanSportsSource;
use Shimomo\AutoraceNewsCollector\Sources\WordPressSite;
use Shimomo\AutoraceNewsCollector\Sources\WordPressSource;
use Shimomo\AutoraceNewsCollector\Sources\YouTubeSource;
use Shimomo\AutoraceNewsCollector\Tests\Support\Fixtures;
use Shimomo\AutoraceNewsCollector\Tests\Support\SpySleeper;
use Shimomo\AutoraceNewsCollector\Tests\Support\StubSource;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @author shimomo
 */
final class CollectorTest extends TestCase
{
    /**
     * @return void
     */
    #[Test]
    public function sortsArticlesNewestFirstAcrossSources(): void
    {
        $a1 = self::article('a', '1', '2026-10-12 10:00:00');
        $a2 = self::article('a', '2', '2026-10-12 12:00:00');
        $b1 = self::article('b', '1', '2026-10-12 11:00:00');

        $result = (new Collector([new StubSource('a', [$a1, $a2]), new StubSource('b', [$b1])]))->collect();

        self::assertSame([$a2, $b1, $a1], $result->articles);
        self::assertSame([], $result->failures);
    }

    /**
     * @return void
     */
    #[Test]
    public function keepsSourceOrderForEqualTimestamps(): void
    {
        $a1 = self::article('a', '1', '2026-10-12 10:00:00');
        $a2 = self::article('a', '2', '2026-10-12 10:00:00');
        $b1 = self::article('b', '1', '2026-10-12 10:00:00');

        $result = (new Collector([new StubSource('a', [$a1, $a2]), new StubSource('b', [$b1])]))->collect();

        self::assertSame([$a1, $a2, $b1], $result->articles);
    }

    /**
     * @return void
     */
    #[Test]
    public function isolatesSourceExceptions(): void
    {
        $exception = new RuntimeException('feed down');
        $b1 = self::article('b', '1', '2026-10-12 10:00:00');

        $result = (new Collector([new StubSource('a', [], [], $exception), new StubSource('b', [$b1])]))->collect();

        self::assertSame([$b1], $result->articles);
        self::assertCount(1, $result->failures);
        self::assertSame('a', $result->failures[0]->source);
        self::assertNull($result->failures[0]->url);
        self::assertSame('feed down', $result->failures[0]->message);
        self::assertSame($exception, $result->failures[0]->previous);
    }

    /**
     * @return void
     */
    #[Test]
    public function propagatesItemFailures(): void
    {
        $failure = new SourceFailure('a', 'https://example.test/x', 'broken item');

        $result = (new Collector([new StubSource('a', [], [$failure])]))->collect();

        self::assertSame([], $result->articles);
        self::assertSame([$failure], $result->failures);
    }

    /**
     * @return void
     */
    #[Test]
    public function passesSinceToEverySource(): void
    {
        $since = new DateTimeImmutable('2026-10-12 00:00:00 +09:00');
        $a = new StubSource('a');
        $b = new StubSource('b');

        (new Collector([$a, $b]))->collect($since);
        (new Collector([$a]))->collect();

        self::assertSame([$since, null], $a->received);
        self::assertSame([$since], $b->received);
    }

    /**
     * @return void
     */
    #[Test]
    public function defaultBuildsEightSources(): void
    {
        $sources = Collector::default(new HttpFetcher(new MockHttpClient(), new SpySleeper(), 0.0))->sources();

        self::assertSame(
            ['autorace.jp', 'nikkansports', 'kawaguchi', 'kawaguchi', 'isesaki', 'hamamatsu', 'sanyo', 'youtube'],
            array_map(static fn(SourceContract $source): string => $source->key(), $sources),
        );
        self::assertInstanceOf(AutoraceJpSource::class, $sources[0]);
        self::assertInstanceOf(NikkanSportsSource::class, $sources[1]);
        self::assertInstanceOf(WordPressSource::class, $sources[2]);
        self::assertInstanceOf(WordPressSource::class, $sources[6]);
        self::assertInstanceOf(YouTubeSource::class, $sources[7]);
        self::assertSame('川口オートレース', $sources[3]->name());
    }

    /**
     * 8ソースのフィクスチャを URL で振り分け、default() の配線とスロットリングを通しで確かめる。
     *
     * @return void
     */
    #[Test]
    public function defaultCollectsEveryFixture(): void
    {
        $client = new MockHttpClient(static function (string $method, string $url): MockResponse {
            $wordpress = [
                WordPressSite::kawaguchiNews()->endpoint() => 'wordpress/kawaguchi-news.json',
                WordPressSite::kawaguchiInterview()->endpoint() => 'wordpress/kawaguchi-interview.json',
                WordPressSite::isesaki()->endpoint() => 'wordpress/isesaki-posts.json',
                WordPressSite::hamamatsu()->endpoint() => 'wordpress/hamamatsu-newsinfo.json',
                WordPressSite::sanyo()->endpoint() => 'wordpress/sanyo-posts.json',
            ];

            if ($url === AutoraceJpSource::FEED_URL) {
                return new MockResponse(Fixtures::read('autorace-jp/rss.xml'));
            }

            if ($url === NikkanSportsSource::FEED_URL) {
                return new MockResponse(Fixtures::read('nikkansports/atom.xml'));
            }

            if (str_starts_with($url, 'https://www.nikkansports.com/public_race/news/')) {
                return new MockResponse(Fixtures::read('nikkansports/article-202610120001800.html'));
            }

            if (str_starts_with($url, 'https://www.youtube.com/feeds/videos.xml?channel_id=')) {
                return new MockResponse(Fixtures::read('youtube/videos.xml'));
            }

            foreach ($wordpress as $endpoint => $fixture) {
                if (str_starts_with($url, $endpoint . '?')) {
                    return new MockResponse(Fixtures::read($fixture), ['response_headers' => ['x-wp-totalpages' => '1']]);
                }
            }

            self::fail('Unexpected request: ' . $method . ' ' . $url);
        });
        $sleeper = new SpySleeper();

        $result = Collector::default(new HttpFetcher($client, $sleeper))->collect();

        self::assertCount(90, $result->articles);
        self::assertSame([], $result->failures);
        self::assertSame(18, $client->getRequestsCount());
        self::assertCount(17, $sleeper->calls);
        self::assertSame('autorace.jp', $result->articles[0]->source);
        self::assertSame('038810', $result->articles[0]->id);

        $keys = array_values(array_unique(array_map(static fn(Article $article): string => $article->source, $result->articles)));
        sort($keys);

        self::assertSame(['autorace.jp', 'hamamatsu', 'isesaki', 'kawaguchi', 'nikkansports', 'sanyo', 'youtube'], $keys);
    }

    /**
     * @param string $source
     * @param string $id
     * @param string $time Asia/Tokyo
     * @return \Shimomo\AutoraceNewsCollector\Article
     */
    private static function article(string $source, string $id, string $time): Article
    {
        return new Article(
            $source,
            'name ' . $source,
            $id,
            'https://example.test/' . $source . '/' . $id,
            'title ' . $id,
            [],
            new DateTimeImmutable($time, new DateTimeZone('Asia/Tokyo')),
        );
    }
}
```

- [ ] **Step 3: 落ちることを確かめる**

Run: `vendor/bin/phpunit tests/CollectorTest.php`
Expected: クラスが無く Error

- [ ] **Step 4: 実装する**

`src/Collector.php`:

```php
<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNewsCollector;

use DateTimeImmutable;
use Shimomo\AutoraceNewsCollector\Contracts\Source as SourceContract;
use Shimomo\AutoraceNewsCollector\Html\BodyNormalizer;
use Shimomo\AutoraceNewsCollector\Http\HttpFetcher;
use Shimomo\AutoraceNewsCollector\Http\NativeSleeper;
use Shimomo\AutoraceNewsCollector\Sources\AutoraceJpSource;
use Shimomo\AutoraceNewsCollector\Sources\NikkanSportsSource;
use Shimomo\AutoraceNewsCollector\Sources\WordPressSite;
use Shimomo\AutoraceNewsCollector\Sources\WordPressSource;
use Shimomo\AutoraceNewsCollector\Sources\YouTubeSource;
use Symfony\Component\HttpClient\HttpClient;
use Throwable;

/**
 * 複数のソースを順に回して結果を束ねる。collect() は例外を投げず、失敗は failures に載せる。
 *
 * @author shimomo
 */
final class Collector
{
    /**
     * @param list<\Shimomo\AutoraceNewsCollector\Contracts\Source> $sources
     */
    public function __construct(
        private array $sources,
    ) {
    }

    /**
     * 既定の8ソース。ソースキーは7種で、川口だけ news と interview の2つ。
     * 同じ HttpFetcher を全ソースで共有するので、待機間隔もソースをまたいで効く。
     *
     * @param ?\Shimomo\AutoraceNewsCollector\Http\HttpFetcher $fetcher
     * @return self
     */
    public static function default(?HttpFetcher $fetcher = null): self
    {
        $fetcher ??= new HttpFetcher(HttpClient::create(), new NativeSleeper());
        $normalizer = new BodyNormalizer();

        return new self([
            new AutoraceJpSource($fetcher, $normalizer),
            new NikkanSportsSource($fetcher, $normalizer),
            new WordPressSource(WordPressSite::kawaguchiNews(), $fetcher, normalizer: $normalizer),
            new WordPressSource(WordPressSite::kawaguchiInterview(), $fetcher, normalizer: $normalizer),
            new WordPressSource(WordPressSite::isesaki(), $fetcher, normalizer: $normalizer),
            new WordPressSource(WordPressSite::hamamatsu(), $fetcher, normalizer: $normalizer),
            new WordPressSource(WordPressSite::sanyo(), $fetcher, normalizer: $normalizer),
            new YouTubeSource($fetcher),
        ]);
    }

    /**
     * @return list<\Shimomo\AutoraceNewsCollector\Contracts\Source>
     */
    public function sources(): array
    {
        return $this->sources;
    }

    /**
     * @param ?\DateTimeImmutable $since publishedAt >= since の記事だけ返す
     * @return \Shimomo\AutoraceNewsCollector\CollectResult
     */
    public function collect(?DateTimeImmutable $since = null): CollectResult
    {
        $articles = [];
        $failures = [];

        foreach ($this->sources as $source) {
            try {
                $result = $source->fetch($since);
            } catch (Throwable $e) {
                // 1ソースの失敗で全体を止めない。呼び出し側は failures を見て判断する
                $failures[] = new SourceFailure($source->key(), null, $e->getMessage(), $e);

                continue;
            }

            foreach ($result->articles as $article) {
                $articles[] = $article;
            }

            foreach ($result->failures as $failure) {
                $failures[] = $failure;
            }
        }

        // 同時刻はソース順・フィード順を保つ。PHP 8 の usort は安定
        usort($articles, static fn(Article $a, Article $b): int => $b->publishedAt <=> $a->publishedAt);

        return new CollectResult($articles, $failures);
    }
}
```

- [ ] **Step 5: 通ることを確かめる**

Run: `vendor/bin/phpunit tests/CollectorTest.php && composer analyse && composer lint`
Expected: `OK (7 tests, ...)`

Run: `composer test`
Expected: 全テストが通る。`vendor/bin/phpunit --list-tests | tail -1` で件数が 100 前後あることを見る（`#[Test]` の付け忘れで黙って減っていないか）

- [ ] **Step 6: ステージしてコミットコマンドを提示する**

```bash
git add src/Collector.php tests/CollectorTest.php tests/Support/StubSource.php
git commit -m "feat: add the Collector with the default source set"
```

---

### Task 11: README と最終確認

**Files:**
- Create: `README.md`
- Create: `CLAUDE.md`（`.gitignore` 済みなのでコミットされない。ローカル用）

- [ ] **Step 1: README を書く**

`README.md`:

````markdown
# 🏍️ autorace-news-collector

[![test](https://github.com/shimomo/autorace-news-collector/actions/workflows/test.yml/badge.svg)](https://github.com/shimomo/autorace-news-collector/actions/workflows/test.yml)
[![psalm](https://github.com/shimomo/autorace-news-collector/actions/workflows/psalm.yml/badge.svg)](https://github.com/shimomo/autorace-news-collector/actions/workflows/psalm.yml)
[![lint](https://github.com/shimomo/autorace-news-collector/actions/workflows/lint.yml/badge.svg)](https://github.com/shimomo/autorace-news-collector/actions/workflows/lint.yml)

オートレースの最新記事を、公式サイトの RSS、日刊スポーツの Atom、各レース場公式サイトの WordPress REST API、公式 YouTube チャンネルのフィードから集めて `Article` の配列として返す PHP ライブラリです。

保存・既読管理・重複排除は呼び出し側の責務です。HTML の一覧ページはスクレイピングしません。

---

## 📦 インストール

PHP 8.4 以上（`ext-dom`、`ext-json`、`ext-libxml`）。

```bash
composer require shimomo/autorace-news-collector
```

---

## 🚀 使い方

```php
use Shimomo\AutoraceNewsCollector\Collector;

$collector = Collector::default();
$result    = $collector->collect(since: new DateTimeImmutable('-1 day'));

foreach ($result->articles as $article) {
    // $article->source, $article->id, $article->title, $article->bodyText, ...
}

foreach ($result->failures as $failure) {
    // $failure->source, $failure->url, $failure->message, $failure->previous
}
```

ソースを選ぶ場合は `Collector` に直接渡します。

```php
use Shimomo\AutoraceNewsCollector\Collector;
use Shimomo\AutoraceNewsCollector\Http\HttpFetcher;
use Shimomo\AutoraceNewsCollector\Http\NativeSleeper;
use Shimomo\AutoraceNewsCollector\Sources\AutoraceJpSource;
use Shimomo\AutoraceNewsCollector\Sources\WordPressSite;
use Shimomo\AutoraceNewsCollector\Sources\WordPressSource;
use Symfony\Component\HttpClient\HttpClient;

$fetcher   = new HttpFetcher(HttpClient::create(), new NativeSleeper(), interval: 2.0);
$collector = new Collector([
    new AutoraceJpSource($fetcher),
    new WordPressSource(WordPressSite::kawaguchiNews(), $fetcher),
]);
```

`HttpFetcher` は User-Agent、タイムアウト（既定 15 秒）、リクエスト間隔（既定 1 秒）を持ちます。再試行が要るときは symfony の `RetryableHttpClient` で包んだクライアントを渡してください。

---

## 📰 収集元

| `source` | 表示名 | 取得経路 | 本文 | 1回の件数 |
|---|---|---|---|---|
| `autorace.jp` | AUTORACE.JP | RSS | フィードに本文 HTML | 15 |
| `nikkansports` | 日刊スポーツ | Atom ＋ 記事ページ | 記事ページから抽出 | 10 |
| `kawaguchi` | 川口オートレース | WordPress REST（お知らせ・選手インタビュー） | API に本文 | 最大 100 |
| `isesaki` | 伊勢崎オートレース | WordPress REST | API に本文（空の投稿が多い） | 最大 100 |
| `hamamatsu` | 浜松オート | WordPress REST | API に本文 | 最大 100 |
| `sanyo` | 山陽オート | WordPress REST | API に本文 | 最大 100 |
| `youtube` | オートレース公式 YouTube | Atom | 動画説明文 | 15 |

飯塚オート公式は RSS も API も無いため対象外です。

---

## 📄 `Article`

| プロパティ | 型 | 内容 |
|---|---|---|
| `source` | `string` | ソースキー |
| `sourceName` | `string` | 表示名 |
| `id` | `string` | ソース内で安定した ID。`(source, id)` で重複判定できる |
| `url` | `string` | 記事 URL |
| `title` | `string` | |
| `categories` | `list<string>` | ソース固有の分類 |
| `publishedAt` | `DateTimeImmutable` | Asia/Tokyo |
| `summary` | `?string` | フィードの要約 |
| `bodyHtml` | `?string` | 整形済み本文 HTML（script 等を除去、URL を絶対化） |
| `bodyText` | `?string` | 本文のプレーンテキスト |
| `thumbnailUrl` | `?string` | アイキャッチまたは本文先頭画像 |

---

## 🔁 `since` と重複

- `collect(since)` は `publishedAt >= since` の記事を返します。省略時は各ソースが1回で返せる範囲をそのまま返します。
- 日刊スポーツの記事は AUTORACE.JP にも「【日刊提供】」として載るため、同じ内容が2ソースから返ります。URL も ID も異なるのでライブラリでは束ねません。

---

## ⚠️ 失敗の扱い

`collect()` は例外を投げません。ソース全体の失敗（HTTP エラー、フィードの解析失敗）も、1件単位の失敗（必須項目の欠落、日刊の記事ページ取得失敗）も `failures` に `SourceFailure` として積まれ、他の記事は返ります。日刊の記事ページ取得に失敗した記事は要約だけを持つ `Article` になります。

---

## ⏱️ 実行間隔の目安

日刊スポーツの Atom は最新 10 件しか無く、SG 開催日は 1 日に 10 本を超えます。1〜2 時間おきに実行してください。各サイトへのアクセスは 1 秒間隔です。

---

## 📝 注意

AUTORACE.JP のサイトポリシーは無断転載・複製・公開を禁じています。本ライブラリは本人の私的な保存に限って使うものです。

---

## 🛠️ 開発

```bash
composer test       # phpunit
composer analyse    # psalm
composer lint       # pint --test
composer fix        # pint
composer normalize  # composer.json の整形
```

テストはネットワークへ出ません。`tests/fixtures/` は 2026-10-13 に採取した実レスポンスです。
````

- [ ] **Step 2: CLAUDE.md を書く（ローカル用、コミットしない）**

`CLAUDE.md`:

```markdown
# CLAUDE.md

オートレースの記事収集ライブラリ。PHP 8.4 以上、単一パッケージ。設計は
`docs/superpowers/specs/2026-10-13-autorace-news-collector-design.md`、収集元の調査は `docs/sources.md`。

## コマンド

```bash
composer test       # phpunit
composer analyse    # psalm。errorLevel=1・エラー0を維持する
composer lint       # pint --test
composer fix        # pint
composer normalize  # composer.json（4スペース）
```

## 構成

- `Collector` → `Contracts\Source` の実装（`Sources/`）→ `Http\HttpFetcher`（symfony/http-client の包み）
- XML も HTML も DOMDocument で読む（`Feed\XmlDocument`、`Html\HtmlDocument`）。dom-crawler は使わない
- 本文は `Html\BodyNormalizer` で掃除する。公開向けサニタイズではない

## 規約（boatrace と同じ）

- `declare(strict_types=1)`、`final`、`@author shimomo`、全メソッドに `@param` / `@return`（psalm 記法）
- インターフェース実装に `#[\Override]`、契約の import は `as XxxContract`
- コメントは日本語で「なぜ」。例外メッセージは英語
- テストは `#[Test]` ＋ 英語 lowerCamelCase。`test_` 接頭辞は使わない（付け忘れると黙って収集されない）
- ネットワークへは出ない。`tests/fixtures/` は 2026-10-13 採取の実レスポンス
- コミットは Conventional Commits 英語1行。GPG 署名のためコミットは本人が実行する

## ソースごとの癖

- AUTORACE.JP: RSS の description に本文 HTML が丸ごと入る。【日刊提供】記事は日刊と重複する。pubDate は一括登録で1秒刻みになることがある
- 日刊スポーツ: Atom は要約のみ・最新10件。本文は記事ページの `section#articleMain` 内 `p.prologue` ＋ `div#news`。写真は Atom の `link[rel=enclosure]`（記事ページの img は空画像で実体は style の背景）
- WordPress 4場: `_embed=wp:featuredmedia,wp:term` でサムネイルと分類名を同じレスポンスに含める。`after` は「より後」なので1秒引いて UTC（時差付き）で渡す
- 伊勢崎: 本文が空の投稿が多い（画像だけのことも）
- 浜松: 区分（news / race / event）が REST に露出していないので categories は空
- 川口: news と interview で source キーは同じ `kawaguchi`。interview は固定で「選手インタビュー」
- libxml: HTML は `<?xml encoding="UTF-8"?>` を前置して読む（既定が ISO-8859-1 で、saveHTML が数値実体を吐く）
- psalm: `WordPressSource` は JSON を歩くのでクラス全体で `MixedAssignment` を抑えている
```

- [ ] **Step 3: 全体を確認する**

Run:

```bash
composer test
composer analyse
composer lint
composer normalize --dry-run --no-check-lock --no-interaction
vendor/bin/phpunit --list-tests | tail -1
find src tests -name '*.php' -print0 | xargs -0 awk 'prev ~ /^[[:space:]]*\*\/$/ && $0 ~ /^[[:space:]]*\/\*\*$/ { printf "%s:%d\n", FILENAME, FNR } { prev = $0 }'
git status --short
```

Expected: テスト・psalm・pint・normalize がすべて通る。awk（宙に浮いた docblock の検出）は何も出力しない。`git status` に `CLAUDE.md` が出ない（ignore 済み）

- [ ] **Step 4: ステージしてコミットコマンドを提示する**

```bash
git add README.md
git commit -m "docs: add the README"
```

---

## 完了条件

- `composer test` / `composer analyse` / `composer lint` が通る
- `Collector::default()->collect()` がフィクスチャ経由で 90 件を返す（`CollectorTest::defaultCollectsEveryFixture`）
- 設計書の第2〜9節の要件に対応するテストがある（`Review Focus` の5項目を含む）
