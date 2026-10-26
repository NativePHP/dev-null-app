<?php

use App\Devnull\Data\BodyBlock;
use App\Devnull\Data\BodyRun;
use App\Devnull\Data\Commit;
use App\Devnull\Data\CommitBody;
use App\Devnull\Data\Mention;

/**
 * @param  list<BodyBlock>  $blocks
 * @return list<array{0: string, 1?: string}>
 */
function runsOf(array $blocks): array
{
    return array_map(fn (BodyRun $run): array => match (true) {
        $run->isMention() => [$run->text, '@'.$run->username],
        $run->isCode => [$run->text, 'code'],
        $run->isWordmark => [$run->text, 'wordmark'],
        $run->link !== null => [$run->text, 'link'],
        default => [$run->text],
    }, $blocks[0]->runs);
}

/**
 * The first link in the first paragraph: where it goes, its muted prefix, what
 * it shows, and the dev/null commit it opens in the app.
 *
 * @param  list<BodyBlock>  $blocks
 * @return array{0: string, 1: string, 2: string, 3: string|null}
 */
function linkIn(array $blocks): array
{
    foreach ($blocks[0]->runs as $run) {
        if ($run->link !== null) {
            return [$run->link->href, $run->link->prefix, $run->link->shown, $run->link->commit];
        }
    }

    throw new RuntimeException('There is no link.');
}

it('links mentions by code point, so emoji before them do not shift them', function () {
    // "🚀" is one code point but two UTF-16 units and four bytes.
    $body = '🚀🚀 shipped with @ada and 👩‍💻 @grace-h!';

    $blocks = CommitBody::blocks($body, [['type' => 'text', 'text' => $body]], [
        new Mention('ada', 16, 4),
        new Mention('grace-h', 29, 8),
    ]);

    expect($blocks)->toHaveCount(1)
        ->and(runsOf($blocks))->toBe([
            ['🚀🚀 shipped with '],
            ['@ada', '@ada'],
            [' and 👩‍💻 '],
            ['@grace-h', '@grace-h'],
            ['!'],
        ]);
});

it('places mentions on either side of inline code, whose backticks the segments drop', function () {
    $body = 'Ask @nuno to run `composer test` before @taylor merges';

    $blocks = CommitBody::blocks($body, [
        ['type' => 'text', 'text' => 'Ask @nuno to run '],
        ['type' => 'code', 'code' => 'composer test'],
        ['type' => 'text', 'text' => ' before @taylor merges'],
    ], [new Mention('nuno', 4, 5), new Mention('taylor', 40, 7)]);

    expect(runsOf($blocks))->toBe([
        ['Ask '],
        ['@nuno', '@nuno'],
        [' to run '],
        ['composer test', 'code'],
        [' before '],
        ['@taylor', '@taylor'],
        [' merges'],
    ]);
});

it('splits paragraphs around a code block and shows the code as written', function () {
    $body = "Fixed it @ada:\n```php\n\$a = '<b>';\n  return \$a;\n```\nthanks @grace";

    $blocks = CommitBody::blocks($body, [
        ['type' => 'text', 'text' => 'Fixed it @ada:'],
        ['type' => 'block', 'language' => 'php', 'html' => '<span class="hl-variable">$a</span> = …'],
        ['type' => 'text', 'text' => 'thanks @grace'],
    ], [new Mention('ada', 9, 4), new Mention('grace', 58, 6)]);

    expect($blocks)->toHaveCount(3)
        ->and($blocks[0]->runs[1]->username)->toBe('ada')
        ->and($blocks[1]->isCode())->toBeTrue()
        ->and($blocks[1]->code)->toBe("\$a = '<b>';\n  return \$a;")
        ->and($blocks[1]->language)->toBe('php')
        ->and(array_map(fn (BodyRun $run): string => $run->text, $blocks[2]->runs))->toBe(['thanks ', '@grace'])
        ->and($blocks[2]->runs[1]->username)->toBe('grace');
});

it('does not find prose inside a code block it already passed', function () {
    $body = "```\nsee @ada\n```\nsee @ada";

    $blocks = CommitBody::blocks($body, [
        ['type' => 'block', 'language' => null, 'html' => 'see @ada'],
        ['type' => 'text', 'text' => 'see @ada'],
    ], [new Mention('ada', 21, 4)]);

    expect($blocks[0]->code)->toBe('see @ada')
        ->and($blocks[0]->language)->toBeNull()
        ->and(runsOf([$blocks[1]]))->toBe([['see '], ['@ada', '@ada']]);
});

it('leaves out mentions that do not line up with the body', function () {
    $body = 'Hello @ada';

    $blocks = CommitBody::blocks($body, null, [
        new Mention('ada', 5, 4),
        new Mention('ada', 40, 4),
        new Mention('grace', 6, 4),
    ]);

    expect(runsOf($blocks))->toBe([['Hello @ada']]);
});

it('treats a body without segments as one paragraph, and an empty one as nothing', function () {
    expect(runsOf(CommitBody::blocks('Plain', null)))->toBe([['Plain']])
        ->and(CommitBody::blocks('', null))->toBe([]);
});

it('reads mentions and segments straight from the API', function () {
    $commit = Commit::fromArray([
        'id' => 'c1',
        'body' => '😀 @ada `x` @bob',
        'body_segments' => [['type' => 'text', 'text' => '😀 @ada '], ['type' => 'code', 'code' => 'x'], ['type' => 'text', 'text' => ' @bob']],
        'mentions' => [['username' => 'ada', 'offset' => 2, 'length' => 4], ['username' => 'bob', 'offset' => 11, 'length' => 4], ['username' => 'bad']],
        'author' => ['username' => 'local_dev'],
    ]);

    expect($commit->mentions)->toHaveCount(2)
        ->and(runsOf($commit->content))->toBe([['😀 '], ['@ada', '@ada'], [' '], ['x', 'code'], [' '], ['@bob', '@bob']]);
});

it('draws a standalone /dev/null as the wordmark, keeping its text', function () {
    $body = 'piped to /dev/null, then /dev/null. (/dev/null)';

    $blocks = CommitBody::blocks($body, null);

    expect(runsOf($blocks))->toBe([
        ['piped to '],
        ['/dev/null', 'wordmark'],
        [', then '],
        ['/dev/null', 'wordmark'],
        ['. ('],
        ['/dev/null', 'wordmark'],
        [')'],
    ])->and(implode('', array_map(fn (BodyRun $run): string => $run->text, $blocks[0]->runs)))->toBe($body);
});

it('leaves /dev/null inside longer paths, words, URLs and usernames as text', function () {
    $body = '/dev/nullable /dev/null/log a/dev/null /dev/null_1 /DEV/NULL https://example.com/dev/null example.com/?to=/dev/null @devnull';

    expect(runsOf(CommitBody::blocks($body, null)))->toBe([
        ['/dev/nullable /dev/null/log a/dev/null /dev/null_1 /DEV/NULL '],
        ['https://example.com/dev/null', 'link'],
        [' '],
        ['example.com/?to=/dev/null', 'link'],
        [' @devnull'],
    ]);
});

it('draws the wordmark beside mentions but not in inline code or code blocks', function () {
    $body = "@ada sent it to /dev/null, not `/dev/null`:\n```sh\necho hi > /dev/null\n```";

    $blocks = CommitBody::blocks($body, [
        ['type' => 'text', 'text' => '@ada sent it to /dev/null, not '],
        ['type' => 'code', 'code' => '/dev/null'],
        ['type' => 'text', 'text' => ':'],
        ['type' => 'block', 'language' => 'sh', 'html' => 'echo hi &gt; /dev/null'],
    ], [new Mention('ada', 0, 4)]);

    expect(runsOf($blocks))->toBe([
        ['@ada', '@ada'],
        [' sent it to '],
        ['/dev/null', 'wordmark'],
        [', not '],
        ['/dev/null', 'code'],
        [':'],
    ])->and($blocks[1]->code)->toBe('echo hi > /dev/null');
});

it('draws the wordmark at the end of a preview, before the ellipsis it was cut with', function () {
    $body = 'Logs go to /dev/null and nowhere else, which is the whole point of it.';

    expect(runsOf(CommitBody::blocks($body, [['type' => 'text', 'text' => 'Logs go to /dev/null…']])))->toBe([
        ['Logs go to '],
        ['/dev/null', 'wordmark'],
        ['…'],
    ]);
});

it('links a URL, shortened like the web, and still places the mentions around it', function () {
    $url = 'https://www.example.com/a-long-path-to-the-release-notes';
    $body = "Thanks @ORIGINAL! See {$url} and @futuredev.";

    $blocks = CommitBody::blocks($body, null, [new Mention('original', 7, 9), new Mention('futuredev', mb_strpos($body, '@futuredev'), 10)]);

    expect(runsOf($blocks))->toBe([
        ['Thanks '],
        ['@ORIGINAL', '@original'],
        ['! See '],
        [$url, 'link'],
        [' and '],
        ['@futuredev', '@futuredev'],
        ['.'],
    ])->and(linkIn($blocks))->toBe([$url, '', 'example.com/a-long-path-to-the…', null]);
});

it('leaves sentence punctuation out of a link, but keeps a bracket that pairs inside it', function (string $body, string $link, string $after) {
    expect(runsOf(CommitBody::blocks($body, null)))->toBe([['Read '], [$link, 'link'], [$after]]);
})->with([
    'a full stop' => ['Read https://laravel.com/docs.', 'https://laravel.com/docs', '.'],
    'a closing bracket' => ['Read https://en.wikipedia.org/wiki/Foo_(bar)).', 'https://en.wikipedia.org/wiki/Foo_(bar)', ').'],
]);

it('links a bare domain to https', function () {
    expect(linkIn(CommitBody::blocks('Built with nativephp.com', null)))->toBe(['https://nativephp.com', '', 'nativephp.com', null])
        ->and(linkIn(CommitBody::blocks('Read laravel.com/docs', null)))->toBe(['https://laravel.com/docs', '', 'laravel.com/docs', null]);
});

it('leaves code, files and emails that look like a domain as text', function (string $body) {
    expect(runsOf(CommitBody::blocks($body, null)))->toBe([[$body]]);
})->with(['user.name', 'socket.io.js', 'deploy.sh', 'bug.It', 'ada@example.com', 'array.at(-1)', 'nativephp.community']);

it('leaves URLs in inline code and code blocks as code', function () {
    $body = "See `https://inline.example.com`:\n```text\nhttps://block.example.com\n```";

    $blocks = CommitBody::blocks($body, [
        ['type' => 'text', 'text' => 'See '],
        ['type' => 'code', 'code' => 'https://inline.example.com'],
        ['type' => 'text', 'text' => ':'],
        ['type' => 'block', 'language' => 'text', 'html' => 'https://block.example.com'],
    ]);

    expect(runsOf($blocks))->toBe([['See '], ['https://inline.example.com', 'code'], [':']])
        ->and($blocks[1]->code)->toBe('https://block.example.com');
});

it('shows a dev/null commit link as its author and short hash, to open in the app', function () {
    $url = 'https://dev-null.sh/@Hacksore/commits/01a14a9d-7971-73ea-8f35-135FE5149CA8';

    $blocks = CommitBody::blocks("same root cause as {$url}.", null);

    expect(runsOf($blocks))->toBe([['same root cause as '], [$url, 'link'], ['.']])
        ->and(linkIn($blocks))->toBe([$url, 'Hacksore/', '5149ca8', '01a14a9d-7971-73ea-8f35-135fe5149ca8']);
});

it('keeps other dev/null links as URLs', function (string $url) {
    expect(linkIn(CommitBody::blocks($url, null)))->toMatchArray([0 => $url, 1 => '', 3 => null]);
})->with([
    'https://dev-null.sh/@Hacksore/commits/01a14a9d-7971-73ea-8f35-135fe5149ca8/photos/1',
    'https://dev-null.sh/@Hacksore/commits/01a14a9d-7971-73ea-8f35-135fe5149ca8?tab=replies',
    'https://example.com/@Hacksore/commits/01a14a9d-7971-73ea-8f35-135fe5149ca8',
    'https://dev-null.sh/@Hacksore',
]);

it('shows a GitHub repository, issue or pull request as GitHub writes it', function (string $written, string $href, string $prefix, string $shown) {
    expect(linkIn(CommitBody::blocks("built on {$written}.", null)))->toBe([$href, $prefix, $shown, null]);
})->with([
    'a repository' => ['https://github.com/laravel/framework', 'https://github.com/laravel/framework', 'laravel/', 'framework'],
    'an issue' => ['https://github.com/laravel/framework/issues/42', 'https://github.com/laravel/framework/issues/42', 'laravel/', 'framework#42'],
    'a pull request' => ['https://github.com/laravel/framework/pull/42', 'https://github.com/laravel/framework/pull/42', 'laravel/', 'framework#42'],
    'a bare domain' => ['github.com/laravel/framework', 'https://github.com/laravel/framework', 'laravel/', 'framework'],
    'www' => ['https://www.github.com/laravel/framework', 'https://www.github.com/laravel/framework', 'laravel/', 'framework'],
    'capitals' => ['HTTPS://GitHub.com/laravel/framework', 'https://GitHub.com/laravel/framework', 'laravel/', 'framework'],
    'a dot in the name' => ['http://github.com/nunomaduro/dev-null.sh', 'http://github.com/nunomaduro/dev-null.sh', 'nunomaduro/', 'dev-null.sh'],
]);

it('keeps other GitHub links as URLs', function (string $url) {
    expect(linkIn(CommitBody::blocks($url, null)))->toMatchArray([0 => $url, 1 => '']);
})->with([
    'https://github.com/laravel',
    'https://github.com/laravel/framework/',
    'https://github.com/laravel/framework/blob/12.x/README.md',
    'https://github.com/laravel/framework/tree/12.x',
    'https://github.com/laravel/framework/releases',
    'https://github.com/laravel/framework/commit/4fa008f',
    'https://github.com/laravel/framework/pull/42/files',
    'https://github.com/laravel/framework/pull/42/',
    'https://github.com/laravel/framework/issues/0',
    'https://github.com/laravel/framework/issues/042',
    'https://github.com/laravel/framework/issues',
    'https://github.com/laravel/framework/issues/42#issuecomment-1',
    'https://github.com/laravel/framework/issues/42?page=2',
    'https://github.com/laravel/framework/discussions/42',
    'https://github.com/laravel/framework?tab=readme',
    'https://github.com/laravel/framework#readme',
    'https://github.com/features/actions',
    'https://github.com/orgs/laravel',
    'https://github.com/settings/profile',
    'https://gist.github.com/laravel/framework',
    'https://notgithub.com/laravel/framework',
]);

/**
 * Each block as its kind and its text, runs joined.
 *
 * @param  list<BodyBlock>  $blocks
 * @return list<array{0: string, 1: string}>
 */
function quotesOf(array $blocks): array
{
    return array_map(fn (BodyBlock $block): array => [
        match (true) {
            $block->isCode() => 'code',
            $block->isQuote() => 'quote',
            default => 'paragraph',
        },
        $block->isCode() ? (string) $block->code : implode('', array_map(fn (BodyRun $run): string => $run->text, $block->runs)),
    ], $blocks);
}

it('groups lines that start with > into quotes, as the web does', function () {
    $body = "> First `code`\n> @ada https://example.com\nNormal > text\n>Last";

    $blocks = CommitBody::blocks($body, [
        ['type' => 'text', 'text' => '> First '],
        ['type' => 'code', 'code' => 'code'],
        ['type' => 'text', 'text' => "\n> @ada https://example.com\nNormal > text\n>Last"],
    ], [new Mention('ada', 17, 4)]);

    expect(quotesOf($blocks))->toBe([
        ['quote', "First code\n@ada https://example.com"],
        ['paragraph', 'Normal > text'],
        ['quote', 'Last'],
    ])->and(array_map(fn (BodyRun $run): array => [$run->text, $run->isCode, $run->username, $run->link?->href], $blocks[0]->runs))->toBe([
        ['First ', false, null, null],
        ['code', true, null, null],
        ["\n", false, null, null],
        ['@ada', false, 'ada', null],
        [' ', false, null, null],
        ['https://example.com', false, null, 'https://example.com'],
    ]);
});

it('leaves a > that is inside code or not at the start of a line as written', function () {
    $body = "`code`> literal\n```text\n> fenced\n```\n> quoted\n>\n> `> inline`\n\nAfter";

    $blocks = CommitBody::blocks($body, [
        ['type' => 'code', 'code' => 'code'],
        ['type' => 'text', 'text' => '> literal'],
        ['type' => 'block', 'language' => 'text'],
        ['type' => 'text', 'text' => "> quoted\n>\n> "],
        ['type' => 'code', 'code' => '> inline'],
        ['type' => 'text', 'text' => "\n\nAfter"],
    ]);

    expect(quotesOf($blocks))->toBe([
        ['paragraph', 'code> literal'],
        ['code', '> fenced'],
        ['quote', "quoted\n\n> inline"],
        ['paragraph', "\nAfter"],
    ]);
});

it('keeps Windows line breaks inside a quote and drops the one before the next block', function () {
    expect(quotesOf(CommitBody::blocks("Before\r\n> one\r\n> two\r\nAfter", null)))->toBe([
        ['paragraph', 'Before'],
        ['quote', "one\r\ntwo"],
        ['paragraph', 'After'],
    ]);
});

it('quotes a line that is only the marker', function () {
    expect(quotesOf(CommitBody::blocks('>', null)))->toBe([['quote', '']]);
});
