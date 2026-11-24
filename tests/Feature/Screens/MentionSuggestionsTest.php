<?php

use App\Devnull\Data\User;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Testing\Native;

/**
 * Renders partials/mention-suggestions on its own, with the options a test sets, for the people in $mentionPeople.
 */
class MentionSuggestionsFixture extends NativeComponent
{
    /** @var array{field: string, start: int, query: string, text: string} */
    public ?array $mention = ['field' => 'body', 'start' => 0, 'query' => 'ada', 'text' => '@ada'];

    /** @var list<User> */
    public array $mentionPeople = [];

    /** @var list<User> */
    public array $mentionOrganizations = [];

    /** @var array<string, mixed> */
    public array $options = [];

    public function mount(int $rows = 8, array $options = []): void
    {
        $this->mentionPeople = array_map(fn (int $n): User => User::fromArray(userJson(['id' => "u{$n}", 'handle' => "ada{$n}", 'name' => "Ada {$n}"])), range(1, $rows));
        $this->options = $options;
    }

    public function pickMention(string $field, string $username): void {}

    public function render(): View
    {
        return view('fixtures::mention-suggestions', ['options' => $this->options]);
    }
}

beforeEach(fn () => view()->addNamespace('fixtures', base_path('tests/Fixtures/views')));

it('scrolls more than six rows inside the list by default', function () {
    $screen = Native::test(MentionSuggestionsFixture::class, ['rows' => 8]);

    expect(childRefs($screen, 'mention-suggestions'))->toBe(['mention-scroll'])
        ->and(nodeByRef($screen, 'mention-scroll')['type'])->toBe('scroll_view');
});

it('draws every row with no scroll view inside when the caller scrolls the list itself', function () {
    $screen = Native::test(MentionSuggestionsFixture::class, ['rows' => 8, 'options' => ['scrolls' => false]]);

    expect(rendersRef($screen, 'mention-scroll'))->toBeFalse()
        ->and(childRefs($screen, 'mention-suggestions'))->toBe(array_map(fn (int $n): string => "mention-ada{$n}", range(1, 8)))
        ->and(nodeByRef($screen, 'mention-ada8')['children'][0]['layout']['height'] ?? null)->toBeNull();
});
