<?php

use App\NativeComponents\Guild;
use App\NativeComponents\GuildForm;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

/**
 * The directory's first page, with one guild on it.
 *
 * @return array<string, mixed>
 */
function directoryWithOneGuild(): array
{
    return ['data' => [guildJson()], 'links' => ['next' => null], 'meta' => ['current_page' => 1]];
}

/**
 * The guild writes devnull was sent, as "METHOD path".
 *
 * @return list<string>
 */
function guildWritesSent(): array
{
    return Http::recorded()
        ->map(fn (array $pair): Request => $pair[0])
        ->filter(fn (Request $request): bool => $request->method() !== 'GET')
        ->map(fn (Request $request): string => $request->method().' '.parse_url($request->url(), PHP_URL_PATH))
        ->values()
        ->all();
}

/**
 * A photo bigger than devnull takes for a guild icon, still a real PNG.
 */
function oversizedPhoto(): string
{
    $path = testPhoto();
    file_put_contents($path, str_repeat("\0", GuildForm::IconMaxBytes + 1), FILE_APPEND);

    return $path;
}

it('offers Create a guild under the directory intro', function () {
    fakeApi(['GET /api/v1/guilds' => [directoryWithOneGuild()]]);

    finishRefreshes(Native::visit('/guilds'))
        ->assertSee('Start a public group.')
        ->tap('create-guild')
        ->assertNavigatedTo('/guilds/guilds/_new');
});

it('hides Create a guild when guilds are unavailable', function () {
    fakeApi(['GET /api/v1/guilds' => [['message' => 'Not Found'], 404]]);

    finishRefreshes(Native::visit('/guilds'))->assertDontSee('Start a public group.');
});

it('shows the hashtag the name makes', function () {
    Native::visit('/guilds/guilds/_new')
        ->assertNavTitle('Create a guild')
        ->assertSee('Your hashtag appears here')
        ->set('name', 'Rust Lang!')
        ->assertSee('#rustlang');
});

it('refuses a name whose hashtag does not start with a letter', function () {
    fakeApi([]);

    Native::visit('/guilds/guilds/_new')
        ->set('name', '🦀 123')
        ->set('description', 'Crabs.')
        ->call('save')
        ->assertSet('formError', 'Use a name whose hashtag starts with a letter.');

    expect(guildWritesSent())->toBe([]);
});

it('refuses a name over 60 characters', function () {
    fakeApi([]);

    Native::visit('/guilds/guilds/_new')
        ->set('name', str_repeat('a', 61))
        ->set('description', 'Long.')
        ->call('save')
        ->assertSet('formError', 'The name must not be greater than 60 characters.');

    expect(guildWritesSent())->toBe([]);
});

it('creates the guild and opens it in the form’s place', function () {
    fakeApi(['POST /api/v1/guilds' => [['data' => guildJson(['handle' => 'rustlang'])], 201]]);

    Native::visit('/guilds/guilds/_new')
        ->set('name', 'Rust Lang')
        ->set('description', 'All about Rust.')
        ->tap('save-guild')
        ->assertReplacedWith('/guilds/guilds/rustlang');

    expect(json_decode(sentTo('POST', 'guilds')[0], true))->toBe(['name' => 'Rust Lang', 'description' => 'All about Rust.', 'emoji' => '💻']);
});

it('toasts and closes when the guild waits for review', function () {
    fakeApi(['POST /api/v1/guilds' => [['message' => 'Your guild is waiting for review. It goes live once an admin approves it.'], 202]]);

    Native::visit('/guilds/guilds/_new')
        ->set('name', 'Rust')
        ->set('description', 'All about Rust.')
        ->tap('save-guild')
        ->assertNativeCalled('ToastKit.Show', fn (array $params): bool => $params['message'] === 'Your guild is waiting for review. It goes live once an admin approves it.')
        ->assertWentBack();
});

it("shows devnull's reason beside Create guild, and keeps what you wrote", function () {
    $reason = 'A guild already uses this hashtag. Choose a different display name.';
    fakeApi(['POST /api/v1/guilds' => [['message' => $reason, 'errors' => ['handle' => [$reason]]], 422]]);

    Native::visit('/guilds/guilds/_new')
        ->set('name', 'Rust')
        ->set('description', 'All about Rust.')
        ->tap('save-guild')
        ->assertSet('formError', $reason)
        ->assertSet('saving', false)
        ->assertSet('name', 'Rust')
        ->assertSee($reason)
        ->assertNoNavigation();
});

it('sends once while a save is in flight', function () {
    fakeApi([]);

    Native::visit('/guilds/guilds/_new')
        ->set('name', 'Rust')
        ->set('description', 'All about Rust.')
        ->set('saving', true)
        ->call('save');

    expect(guildWritesSent())->toBe([]);
});

it('refuses a picked image over 2 MB', function () {
    $path = oversizedPhoto();

    photosPicked(Native::visit('/guilds/guilds/_new'), [$path])
        ->assertSet('formError', 'Choose an image up to 2 MB.')
        ->assertSet('icon', null);

    expect(file_exists($path))->toBeFalse();
});

it('keeps a picked image and sends it as the icon', function () {
    fakeApi(['POST /api/v1/guilds' => [['data' => guildJson()], 201]]);

    $screen = photosPicked(Native::visit('/guilds/guilds/_new'), [testPhoto()]);

    expect($screen->instance()->icon)->not->toBeNull();

    $screen->set('name', 'Rust')->set('description', 'All about Rust.')->tap('save-guild');

    expect(array_column(multipartParts(Http::recorded()[0][0]), 'name'))->toBe(['payload', 'images[]']);
});

it('takes an emoji devnull knows from the picker', function () {
    Native::visit('/guilds/guilds/_new')
        ->call('openEmojiPicker')
        ->assertSet('emojiPickerOpen', true)
        ->call('emojiPicked', '🦀')
        ->assertSet('emoji', '🦀')
        ->assertSet('emojiPickerOpen', false);
});

it('refuses an emoji devnull does not take', function () {
    Native::visit('/guilds/guilds/_new')
        ->call('emojiPicked', '🫟')
        ->assertSet('emoji', GuildForm::DefaultEmoji)
        ->assertSet('formError', 'Choose another emoji.');
});

/**
 * The guild form for editing #rust, opened from its page with what it shows.
 *
 * @param  array<string, mixed>  $data
 */
function editRust(array $data = []): TestableComponent
{
    return Native::visit('/guilds/guilds/rust/edit', [
        'name' => 'Rust',
        'description' => 'All about Rust.',
        'emoji' => '🦀',
        'iconUrl' => null,
        ...$data,
    ]);
}

it('opens filled in from the guild, with its hashtag fixed', function () {
    editRust()
        ->assertNavTitle('Edit guild')
        ->assertSet('name', 'Rust')
        ->assertSet('description', 'All about Rust.')
        ->assertSet('emoji', '🦀')
        ->assertSee('#rust')
        ->assertSee('As the moderator, you can change the display name, icon and description.')
        ->assertSee('Save changes');
});

it('refuses a name that changes the hashtag', function () {
    fakeApi([]);

    editRust()
        ->set('name', 'Go')
        ->call('save')
        ->assertSet('formError', "Guild display name doesn't match hashtag");

    expect(guildWritesSent())->toBe([]);
});

it('saves changes and goes back to the guild', function () {
    fakeApi(['PUT /api/v1/guilds/rust' => [['data' => guildJson(['name' => 'RUST'])]]]);

    editRust()
        ->set('name', 'RUST')
        ->tap('save-guild')
        ->assertWentBack();

    expect(json_decode(sentTo('PUT', 'guilds/rust')[0], true))->toBe(['name' => 'RUST', 'description' => 'All about Rust.', 'emoji' => '🦀', 'remove_icon' => false]);
});

it('removes the uploaded icon when an emoji is chosen after an image', function () {
    fakeApi(['PUT /api/v1/guilds/rust' => [['data' => guildJson()]]]);
    $path = testPhoto();

    $screen = photosPicked(editRust(['iconUrl' => 'http://devnull.test/api/v1/guilds/rust/icon']), [$path]);
    $kept = $screen->instance()->icon->path;

    $screen->call('emojiPicked', '🦀')
        ->assertSet('icon', null)
        ->assertSet('removeIcon', true)
        ->tap('save-guild');

    expect(file_exists($kept))->toBeFalse()
        ->and(json_decode(sentTo('PUT', 'guilds/rust')[0], true))->toBe(['name' => 'Rust', 'description' => 'All about Rust.', 'emoji' => '🦀', 'remove_icon' => true]);
});

it('says changes wait for review', function () {
    fakeApi(['PUT /api/v1/guilds/rust' => [['message' => 'Your changes are waiting for review. They go live once an admin approves them.'], 202]]);

    editRust()
        ->tap('save-guild')
        ->assertNativeCalled('ToastKit.Show', fn (array $params): bool => $params['message'] === 'Your changes are waiting for review. They go live once an admin approves them.')
        ->assertWentBack();
});

it('goes back to the guild when devnull says you no longer moderate it', function () {
    fakeApi(['PUT /api/v1/guilds/rust' => [['message' => 'Not Found.'], 404]]);

    editRust()
        ->tap('save-guild')
        ->assertNativeCalled('ToastKit.Show')
        ->assertWentBack();
});

it('keeps the form when devnull refuses the fields', function () {
    fakeApi(['PUT /api/v1/guilds/rust' => [['message' => 'The description field is required.', 'errors' => ['description' => ['The description field is required.']]], 422]]);

    editRust()
        ->tap('save-guild')
        ->assertSet('formError', 'The description field is required.')
        ->assertSet('saving', false)
        ->assertNoNavigation();
});

it('says nothing when the photo picker is cancelled', function () {
    photosPicked(Native::visit('/guilds/guilds/_new'), [])
        ->assertSet('formError', null)
        ->assertSet('icon', null);
});

it('opens a guild whose hashtag is #new as that guild, not the form', function () {
    fakeApi(['GET /api/v1/guilds/new' => [boardOf(['c1'], overrides: ['guild' => guildJson(['handle' => 'new', 'name' => 'New'])])]]);

    expect(Native::visit('/log/guilds/new')->instance())->toBeInstanceOf(Guild::class);
});
