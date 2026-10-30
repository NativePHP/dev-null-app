<?php

use App\NativeComponents\Call;
use Devnull\Calls\Events\Connected;
use Devnull\Calls\Events\DescriptionReady;
use Devnull\Calls\Events\Ended;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;

const CallOffer = "v=0\r\no=- 1 2 IN IP4 127.0.0.1\r\na=fingerprint:sha-256 AA:BB:CC\r\n";
const CallAnswer = "v=0\r\na=fingerprint:sha-256 11:22:33\r\n";

/**
 * The code the web shows for CallOffer and CallAnswer, worked out by its own verificationCode() in a browser.
 */
const CallCode = '8955 d47a';

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    $this->bridge = signIn();
    $this->travelTo('2026-10-19 15:30:00');
});

/**
 * The relay credentials devnull hands out for a call with Ada.
 *
 * @return array<string, array{0: mixed, 1?: int}>
 */
function callRelays(): array
{
    return ['GET /api/v1/remotes/@ada/call/ice-servers' => [[
        'ice_servers' => [['urls' => ['turn:turn.example.test:3478'], 'username' => 'u', 'credential' => 'c']],
        'is_relay_only' => true,
    ]]];
}

function callingAda(): TestableComponent
{
    return Native::visit('/call/ada', ['name' => 'Ada Lovelace']);
}

function ringingFromAda(array $data = []): TestableComponent
{
    return Native::visit('/call/ada', ['name' => 'Ada Lovelace', 'offer' => CallOffer, ...$data]);
}

it('rings them with this phone\'s offer once it has one, through the relay devnull hands out', function () {
    serveApi([...callRelays(), 'POST /api/v1/remotes/@ada/call' => [null, 204]]);

    $screen = callingAda()->assertSee('Ada Lovelace')->assertSee('Ringing… 0:00');

    $this->bridge->assertCalled('Calls.Start', fn (array $params): bool => $params['ice_servers'][0]['urls'] === ['turn:turn.example.test:3478']
        && $params['is_relay_only'] === true
        && $params['offer'] === null);
    expect(sentTo('POST', 'remotes/@ada/call'))->toBe([]);

    $screen->emitNative(DescriptionReady::class, ['sdp' => CallOffer]);

    expect(sentTo('POST', 'remotes/@ada/call'))->toBe([json_encode(['sdp' => CallOffer])]);
});

it('says why devnull won\'t let you call, in its words', function () {
    serveApi(['GET /api/v1/remotes/@ada/call/ice-servers' => [['message' => "Calls aren't available right now."], 403]]);

    callingAda()->assertSee("Calls aren't available right now.")->assertSee('Close');

    $this->bridge->assertNotCalled('Calls.Start');
});

it('hangs up when devnull won\'t ring them', function () {
    serveApi([...callRelays(), 'POST /api/v1/remotes/@ada/call' => [['message' => 'Say hi to @ada before calling them.'], 403]]);

    callingAda()
        ->emitNative(DescriptionReady::class, ['sdp' => CallOffer])
        ->assertSee('Say hi to @ada before calling them.');

    $this->bridge->assertCalled('Calls.End');
});

it('connects once they answer, and shows the same code the web does', function () {
    serveApi([...callRelays(), 'POST /api/v1/remotes/@ada/call' => [null, 204], 'GET /api/v1/remotes/@ada/call' => [['call' => ['answer' => CallAnswer]]], 'DELETE /api/v1/remotes/@ada/call' => [null, 204]]);

    $screen = callingAda()
        ->emitNative(DescriptionReady::class, ['sdp' => CallOffer])
        ->firePoll('check')
        ->assertSee('Connecting…');

    $this->bridge->assertCalled('Calls.Answer', fn (array $params): bool => $params['sdp'] === CallAnswer);
    // Picked up, so devnull forgets it without logging anything.
    expect(sentTo('DELETE', 'remotes/@ada/call'))->toHaveCount(1);

    $this->travel(42)->seconds();

    $screen->emitNative(Connected::class)
        ->assertSee('0:00')
        ->assertSee('fp '.CallCode);
});

it('gives up when nobody answers within 45 seconds', function () {
    serveApi([...callRelays(), 'POST /api/v1/remotes/@ada/call' => [null, 204], 'GET /api/v1/remotes/@ada/call' => [['call' => ['answer' => null]]], 'DELETE /api/v1/remotes/@ada/call' => [null, 204]]);

    $screen = callingAda()->emitNative(DescriptionReady::class, ['sdp' => CallOffer])->firePoll('check');
    expect(sentTo('DELETE', 'remotes/@ada/call'))->toBe([]);

    $this->travel(46)->seconds();
    $screen->firePoll('check')->assertSee('No answer.');

    expect(sentTo('DELETE', 'remotes/@ada/call'))->toHaveCount(1);
    $this->bridge->assertCalled('Calls.End');
});

it('cancels a call that is still ringing, so their thread logs it', function () {
    serveApi([...callRelays(), 'DELETE /api/v1/remotes/@ada/call' => [null, 204]]);

    callingAda()->tap('cancel')->assertSee('Call ended.');

    expect(sentTo('DELETE', 'remotes/@ada/call'))->toBe([json_encode(['failed' => false])]);
    $this->bridge->assertCalled('Calls.End');
});

it('rings with Accept and Decline when they call, and answers with this phone\'s answer', function () {
    serveApi([...callRelays(), 'PUT /api/v1/remotes/@ada/call' => [null, 204]]);

    $screen = ringingFromAda()->assertSee('is calling')->assertSee('Decline')->assertSee('Accept');
    $this->bridge->assertNotCalled('Calls.Start');

    $screen->tap('accept')->assertSee('Connecting…');
    $this->bridge->assertCalled('Calls.Start', fn (array $params): bool => $params['offer'] === CallOffer);

    $screen->emitNative(DescriptionReady::class, ['sdp' => CallAnswer])
        ->emitNative(Connected::class)
        ->assertSee('fp '.CallCode);

    expect(sentTo('PUT', 'remotes/@ada/call'))->toBe([json_encode(['sdp' => CallAnswer])]);
});

it('declines, which devnull logs in your thread, and closes', function () {
    serveApi(['DELETE /api/v1/remotes/@ada/call' => [null, 204]]);

    ringingFromAda()->tap('decline')->assertWentBack();

    expect(sentTo('DELETE', 'remotes/@ada/call'))->toBe([json_encode(['failed' => false])]);
});

it('buzzes while it rings, unless their thread is muted', function (bool $isMuted) {
    serveApi(['GET /api/v1/remotes/call' => [['call' => ['partner' => userJson(['handle' => 'ada']), 'sdp' => CallOffer, 'is_muted' => $isMuted]]]]);

    ringingFromAda(['is_muted' => $isMuted])->firePoll('check')->assertSee('is calling');

    expect($this->bridge->callsTo('Device.Vibrate'))->toHaveCount($isMuted ? 0 : 1);
})->with([false, true]);

it('says you missed it once they stop calling', function () {
    serveApi(['GET /api/v1/remotes/call' => [['call' => null]]]);

    ringingFromAda()->firePoll('check')->assertSee('Missed call from @ada.');
});

it('tells them answering failed when the microphone isn\'t allowed', function () {
    serveApi([...callRelays(), 'DELETE /api/v1/remotes/@ada/call' => [null, 204]]);

    ringingFromAda()->tap('accept')
        ->emitNative(Ended::class, ['reason' => 'microphone'])
        ->assertSee('Allow the microphone to call.');

    expect(sentTo('DELETE', 'remotes/@ada/call'))->toBe([json_encode(['failed' => true])]);
});

it('says how the call ended when it ends at the other end', function (string $reason, bool $wasConnected, string $said) {
    serveApi([...callRelays(), 'PUT /api/v1/remotes/@ada/call' => [null, 204]]);

    $screen = ringingFromAda()->tap('accept')->emitNative(DescriptionReady::class, ['sdp' => CallAnswer]);

    if ($wasConnected) {
        $screen->emitNative(Connected::class);
    }

    $screen->emitNative(Ended::class, ['reason' => $reason])->assertSee($said);
})->with([
    'they hung up' => ['hung_up', true, 'Call ended.'],
    'it dropped' => ['failed', true, 'Call ended.'],
    'it never connected' => ['failed', false, "Couldn't connect the call."],
]);

it('gives up connecting after 20 seconds', function () {
    serveApi([...callRelays(), 'PUT /api/v1/remotes/@ada/call' => [null, 204]]);

    $screen = ringingFromAda()->tap('accept')->emitNative(DescriptionReady::class, ['sdp' => CallAnswer]);
    $this->travel(21)->seconds();

    $screen->firePoll('check')->assertSee("Couldn't connect the call.");
    $this->bridge->assertCalled('Calls.End');
});

it('mutes the microphone and turns the speaker on during a call', function () {
    serveApi([...callRelays(), 'PUT /api/v1/remotes/@ada/call' => [null, 204]]);

    ringingFromAda()->tap('accept')
        ->emitNative(DescriptionReady::class, ['sdp' => CallAnswer])
        ->emitNative(Connected::class)
        ->tap('mute')
        ->tap('speaker');

    $this->bridge->assertCalled('Calls.SetMuted', fn (array $params): bool => $params['muted'] === true);
    $this->bridge->assertCalled('Calls.SetSpeaker', fn (array $params): bool => $params['on'] === true);
});

it('hangs up on Android\'s back during a call', function () {
    serveApi([...callRelays(), 'PUT /api/v1/remotes/@ada/call' => [null, 204]]);

    ringingFromAda()->tap('accept')
        ->emitNative(DescriptionReady::class, ['sdp' => CallAnswer])
        ->emitNative(Connected::class)
        ->pressBack()
        ->assertSee('Call ended.');

    $this->bridge->assertCalled('Calls.End');
});

it('closes a few seconds after the call ends', function () {
    serveApi(['GET /api/v1/remotes/@ada/call/ice-servers' => [['message' => 'Nope.'], 403]]);

    $screen = callingAda()->firePoll('check');
    expect($screen->navigationIntent())->toBeNull();

    $this->travel(Call::EndedSeconds + 1)->seconds();
    $screen->firePoll('check')->assertWentBack();
});

it('opens a call ringing for you from the thread you are on, once', function () {
    serveApi([
        'GET /api/v1/remotes/@ada/messages' => [threadJson([remoteMessageJson(['id' => 1, 'body' => 'hey'])])],
        'GET /api/v1/remotes/call' => [['call' => ['partner' => userJson(['handle' => 'ada', 'name' => 'Ada Lovelace']), 'sdp' => CallOffer, 'is_muted' => false]]],
    ]);

    $thread = finishRefreshes(Native::visit('/remotes/conversations/ada'))->firePoll('checkForCall')->assertNavigatedTo('/call/ada');

    expect($thread->navigationIntent()->data)->toMatchArray(['name' => 'Ada Lovelace', 'offer' => CallOffer, 'is_muted' => false]);

    // Once it has rung, it doesn't open again while devnull still has it.
    ringingFromAda();
    expect(finishRefreshes(Native::visit('/remotes/conversations/ada'))->firePoll('checkForCall')->navigationIntent())->toBeNull();
});

it('logs calls in the thread in the web\'s words, naming them where devnull leaves the author off', function () {
    $me = userJson()['id'];
    serveApi(['GET /api/v1/remotes/@ada/messages' => [threadJson([
        remoteMessageJson(['id' => 1, 'user_id' => $me, 'body' => '', 'event' => 'call_started']),
        remoteMessageJson(['id' => 2, 'body' => '', 'event' => 'call_answered']),
        remoteMessageJson(['id' => 3, 'user_id' => $me, 'body' => '', 'event' => 'call_cancelled']),
        remoteMessageJson(['id' => 4, 'body' => '', 'event' => 'call_declined']),
        remoteMessageJson(['id' => 5, 'body' => '', 'event' => 'call_failed']),
        remoteMessageJson(['id' => 6, 'user_id' => $me, 'body' => '', 'event' => 'call_missed']),
        remoteMessageJson(['id' => 7, 'body' => '', 'event' => 'call_missed']),
    ])]]);

    finishRefreshes(Native::visit('/remotes/conversations/ada'))
        ->assertSee('* you started a call')
        ->assertSee('* @ada answered the call')
        ->assertSee('* you cancelled the call')
        ->assertSee('* @ada declined the call')
        ->assertSee("* @ada couldn't answer the call")
        ->assertSee("* your call wasn't answered")
        ->assertSee('* missed call from @ada')
        ->assertDontSee('someone');
});

it('calls them from the phone button in your thread with them', function () {
    serveApi(['GET /api/v1/remotes/@ada/messages' => [threadJson([remoteMessageJson(['id' => 1, 'body' => 'hey'])])]]);

    $thread = finishRefreshes(Native::visit('/remotes/conversations/ada'))->call('call')->assertNavigatedTo('/call/ada');

    expect($thread->navigationIntent()->data)->toMatchArray(['name' => 'Ada Lovelace']);
});
