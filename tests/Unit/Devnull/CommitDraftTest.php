<?php

use App\Devnull\Data\BodyLink;
use App\Devnull\Data\Commit;
use App\Devnull\Data\CommitBody;
use App\Devnull\IssueCommand;

it('uses the web character allowance for a commit draft', function (string $body, int $length, bool $fits) {
    expect(CommitBody::characterCount($body))->toBe($length)
        ->and(Commit::fits($body))->toBe($fits);
})->with([
    'empty' => ['', 0, false],
    'whitespace' => [" \n\t ", 0, false],
    'one character' => ['a', 1, true],
    'trimmed unicode' => ['  🚀  ', 1, true],
    'limit' => [str_repeat('a', 4000), 4000, true],
    'over limit' => [str_repeat('a', 4001), 4001, false],
    'long prose URL' => ['https://example.com/'.str_repeat('x', 200), 23, true],
    'short URL' => ['https://a.io', 12, true],
    'long bare URL' => ['example.com/'.str_repeat('x', 200), 23, true],
    'URL at allowance' => [str_repeat('a', 3976).' https://example.com/'.str_repeat('x', 200), 4000, true],
    'repeated URL' => ['https://example.com/'.str_repeat('x', 100).' https://example.com/'.str_repeat('x', 100), 47, true],
    'inline code stays literal' => ['`https://example.com/'.str_repeat('x', 100).'`', 122, true],
    'fenced code stays literal' => ["```\nhttps://example.com/".str_repeat('x', 100)."\n```", 128, true],
    'prose blank lines collapse' => ["a\r\n\r\n\r\nb", 4, true],
    'code blank lines stay' => ["```\na\n\n\nb\n```", 13, true],
    'invalid scheme boundary' => ['éhttps://example.com/'.str_repeat('x', 100), 121, true],
]);

it('rejects an oversized prose URL but counts code literally', function () {
    $url = 'https://example.com/'.str_repeat('x', 2030);
    expect(BodyLink::hasOversizedUrl($url))->toBeTrue()
        ->and(Commit::fits($url))->toBeFalse()
        ->and(Commit::fits('`'.$url.'`'))->toBeTrue()
        ->and(Commit::fits(str_repeat('https://example.com/'.str_repeat('x', 2000).' ', 21)))->toBeFalse();
});

it('normalizes only prose when measuring a draft', function () {
    expect(CommitBody::normalize("  first\r\n\r\n\r\nlast\n```\na\n\n\nb\n```  "))
        ->toBe("first\n\nlast\n```\na\n\n\nb\n```");
});

it('keeps issue bodies at three to four thousand literal characters', function () {
    expect(IssueCommand::fits('a'))->toBeFalse()
        ->and(IssueCommand::fits('  abc  '))->toBeTrue()
        ->and(IssueCommand::fits(str_repeat('a', 3976).' https://example.com/'.str_repeat('x', 100)))->toBeFalse()
        ->and(Commit::fits(str_repeat('a', 3976).' https://example.com/'.str_repeat('x', 100)))->toBeTrue();
});

it('keeps smart sentence punctuation outside links', function (string $punctuation) {
    $links = BodyLink::in('Read https://example.com/docs'.$punctuation);
    expect($links)->toHaveCount(1)
        ->and($links[0][1])->toBe(24)
        ->and($links[0][2]->href)->toBe('https://example.com/docs');
})->with(['…', '”', '’', '…).', '”!)']);
