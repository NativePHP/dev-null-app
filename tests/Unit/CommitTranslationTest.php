<?php

use App\Devnull\Data\Commit;
use App\Devnull\Data\CommitTranslation;

it('keeps translated rich content separate from original excerpts and links', function () {
    $commit = Commit::fromArray([
        'id' => 'translated',
        'body' => 'Bonjour https://original.test',
        'translation' => [
            'language' => 'en',
            'source_language' => 'fr',
            'source_name' => 'French',
            'available' => true,
            'translated' => [
                'body_segments' => [
                    ['type' => 'text', 'text' => 'Hello https://translated.test '],
                    ['type' => 'code', 'code' => 'literal()'],
                    ['type' => 'block', 'language' => 'php', 'html' => '<pre><code><span>&lt;?php</span> echo &quot;hi&quot;;</code></pre>'],
                ],
                'preview_segments' => [['type' => 'text', 'text' => 'Hello…']],
            ],
        ],
    ]);

    expect($commit->body)->toBe('Bonjour https://original.test')
        ->and($commit->plainText())->toBe('Bonjour https://original.test')
        ->and($commit->links()[0]->href)->toBe('https://original.test')
        ->and($commit->translation->sourceName)->toBe('French')
        ->and($commit->translation->content[0]->runs[1]->link->href)->toBe('https://translated.test')
        ->and($commit->translation->content[1]->code)->toBe('<?php echo "hi";')
        ->and($commit->translation->previewContent[0]->runs[0]->text)->toBe('Hello…')
        ->and($commit->withStar(true)->translation)->toBe($commit->translation);
});

it('tolerates absent translations and cache misses without inventing content', function () {
    expect(Commit::fromArray(['body' => 'original'])->translation)->toBeNull()
        ->and(CommitTranslation::fromArray([]))->toBeNull()
        ->and(CommitTranslation::fromArray(['language' => 'en', 'available' => true, 'translated' => null])->content)->toBeNull()
        ->and(Commit::fromArray(['body' => 'original', 'deleted_at' => '2026-01-01', 'translation' => ['language' => 'en']])->translation)->toBeNull();
});
