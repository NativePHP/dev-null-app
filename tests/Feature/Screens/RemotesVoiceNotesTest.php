<?php

use App\Devnull\VoiceNotes;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Microphone\MicrophoneCancelled;
use Native\Mobile\Events\Microphone\MicrophoneRecorded;
use Native\Mobile\Testing\Native;
use Native\Mobile\Testing\TestableComponent;
use NativePHP\MediaPlayer\Events\PlaybackEnded;
use NativePHP\MediaPlayer\Events\PlaybackError;

const VoiceViewerId = '0199e5c4-0000-7000-8000-000000000001';

const AdaVoiceUrl = 'http://devnull.test/api/v1/remotes/voices/01a18436-9e52-7015-be72-4153d1cbabb4?expires=1&user=u&signature=abc';

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn()
        ->respondTo('Microphone.Start', [])
        ->respondTo('Microphone.GetStatus', ['status' => 'recording'])
        ->respondTo('MediaPlayer.Play', ['success' => true]);
    $this->travelTo('2026-10-19 15:30:00');
});

afterEach(function () {
    VoiceNotes::clear();
});

/**
 * Devnull answering a thread with Ada: its first page, a push (which it echoes back, with the voice note it was sent)
 * and Ada's voice note, served as $voiceType.
 *
 * @param  list<array<string, mixed>>  $messages
 */
function serveVoiceThread(array $messages, int $pushStatus = 201, string $voiceType = 'audio/mp4', int $voiceStatus = 200): void
{
    serveApi(function (Request $request) use ($messages, $pushStatus, $voiceType, $voiceStatus) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        $query = (string) parse_url($request->url(), PHP_URL_QUERY);

        return match (true) {
            $request->method() === 'GET' && $path === '/api/v1/remotes/@ada/messages' => Http::response(str_contains($query, 'after=') ? threadJson([]) : threadJson($messages, ['read_through_id' => 1])),
            $request->method() === 'POST' && $path === '/api/v1/remotes/@ada/messages' => $pushStatus >= 300
                ? Http::response(['message' => 'The voice note is not one.'], $pushStatus)
                : Http::response(['data' => echoedVoiceNote($request)], $pushStatus),
            $path === '/api/v1/remotes/voices/01a18436-9e52-7015-be72-4153d1cbabb4' => Http::response('ftypisom opus', $voiceStatus, ['Content-Type' => $voiceType]),
            $path === '/api/v1/me' => Http::response(currentUserJson()),
            default => Http::response(['usage' => []], $request->method() === 'GET' ? 200 : 204),
        };
    });
}

/**
 * The message devnull stores for a pushed voice note, as it lists it.
 *
 * @return array<string, mixed>
 */
function echoedVoiceNote(Request $request): array
{
    $payload = json_decode(multipartParts($request)[0]['contents'], true);

    return remoteMessageJson([
        'id' => 99,
        'user_id' => VoiceViewerId,
        'client_id' => $payload['client_id'],
        'body' => '',
        'voice' => ['id' => 'v99', 'duration_ms' => 2500, 'waveform' => $payload['voice_waveform'], 'url' => 'http://devnull.test/api/v1/remotes/voices/v99?signature=abc'],
    ]);
}

/**
 * A message from Ada holding a voice note of $milliseconds, as devnull lists it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function voiceMessageJson(int $id = 2, int $milliseconds = 6000, array $overrides = []): array
{
    return remoteMessageJson([
        'id' => $id,
        'client_id' => '9f0f6f3e-0000-4000-8000-00000000000'.$id,
        'body' => '',
        'voice' => ['id' => '01a18436-9e52-7015-be72-4153d1cbabb4', 'duration_ms' => $milliseconds, 'waveform' => range(2, 96, 2), 'url' => AdaVoiceUrl],
        ...$overrides,
    ]);
}

/**
 * A recording as the microphone leaves one in its temporary folder: 16 kHz mono 16-bit WAV of $milliseconds, a
 * tone whose loudness rises from silence to $peak, with iOS's padding chunk before its samples.
 */
function testRecording(int $milliseconds = 2500, int $peak = 16384, string $format = ''): string
{
    $count = intdiv(VoiceNotes::SampleRate * $milliseconds, 1000);
    $samples = '';

    for ($index = 0; $index < $count; $index++) {
        $samples .= pack('v', (int) round($peak * $index / max(1, $count - 1)) * ($index % 2 === 0 ? 1 : -1) & 0xFFFF);
    }

    $format = $format !== '' ? $format : pack('vvVVvv', 1, 1, VoiceNotes::SampleRate, VoiceNotes::SampleRate * 2, 2, 16);
    $filler = 'FLLR'.pack('V', 12).str_repeat("\0", 12);
    $directory = sys_get_temp_dir().'/devnull-recorded-'.bin2hex(random_bytes(6));
    mkdir($directory);
    $path = $directory.'/NativePHP_1.wav';

    file_put_contents($path, 'RIFF'.pack('V', 4 + 24 + strlen($filler) + 8 + strlen($samples)).'WAVEfmt '.pack('V', 16).$format.$filler.'data'.pack('V', strlen($samples)).$samples);

    return $path;
}

/**
 * The microphone handing over what it recorded, for the recording under way.
 */
function voiceRecorded(TestableComponent $screen, string $path): TestableComponent
{
    return $screen->emitNative(MicrophoneRecorded::class, ['path' => $path, 'mimeType' => 'audio/wav', 'id' => $screen->instance()->recordingId]);
}

function voiceThread(string $platform = 'ios'): TestableComponent
{
    return finishRefreshes(Native::visit('/remotes/conversations/ada', platform: $platform));
}

/**
 * The parts of each voice note pushed to Ada.
 *
 * @return list<list<array{name: string, type: string, contents: string}>>
 */
function voiceUploads(): array
{
    return collect(Http::recorded())
        ->map(fn (array $sent): Request => $sent[0])
        ->filter(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/remotes/@ada/messages'))
        ->map(fn (Request $request): array => multipartParts($request))
        ->values()
        ->all();
}

/**
 * How often the screen renders for its Blade `native:poll`s, in milliseconds.
 *
 * @return list<int>
 */
function rendersEvery(TestableComponent $screen): array
{
    return array_keys(Closure::bind(fn (): array => $this->bladePollDeadlines, $screen->instance(), NativeComponent::class)());
}

/**
 * How many of a voice note's bars are drawn played.
 */
function playedBars(TestableComponent $screen, string $ref): int
{
    $bars = findNodes(nodeByRef($screen, $ref.'-waveform'), fn (array $node): bool => str_starts_with((string) ($node['style']['bg_color'] ?? ''), '#') && ($node['layout']['width'] ?? null) == 1.5);

    return count(array_filter($bars, fn (array $node): bool => ($node['style']['opacity'] ?? 1) == 1));
}

it("offers a voice note in the +'s menu, between photos and Ping, while the draft is empty, since voice notes go on their own", function (Closure $draft, bool $offered, string $label) {
    serveVoiceThread([remoteMessageJson(['id' => 1])]);

    $screen = $draft(voiceThread());

    expect(array_slice(childRefs($screen, 'composer-tools'), 0, 3))->toBe(['attach-photos', 'record-voice-note', 'ping'])
        ->and(nodeByRef($screen, 'record-voice-note')['props']['disabled'] ?? false)->toBe(! $offered)
        ->and(nodeByRef($screen, 'record-voice-note')['props']['label'])->toBe($label);
})->with([
    'an empty draft' => [fn (TestableComponent $screen) => $screen, true, 'Record a voice note'],
    'some text' => [fn (TestableComponent $screen) => $screen->input('message-input', 'one sec'), false, 'Voice notes go on their own'],
    'a photo' => [fn (TestableComponent $screen) => photosPicked($screen, [testPhoto()]), false, 'Voice notes go on their own'],
]);

it("records 16 kHz WAV for up to a minute, with the microphone's level and the clock in the field's place", function () {
    serveVoiceThread([remoteMessageJson(['id' => 1])]);
    Native::fakeBridge()->respondTo('Microphone.GetLevel', ['level' => 0.25]);

    $screen = voiceThread()->tap('record-voice-note');

    $screen->assertNativeCalled('Microphone.Start', fn (array $params): bool => $params['format'] === 'wav'
        && $params['sampleRate'] === 16000
        && $params['maxDuration'] == 60
        && $params['id'] === $screen->instance()->recordingId);

    expect(childRefs($screen, 'message-plate'))->toBe(['voice-recorder'])
        ->and(nodeByRef($screen, 'voice-elapsed')['props']['text'] ?? null)->toBe('0:00/1:00')
        ->and(rendersEvery($screen))->toBe([200])
        ->and(nodeByRef($screen, 'push')['props']['disabled'] ?? false)->toBeFalse();

    // The level is the loudest sample since the last look, square-rooted as the web draws it, and the clock turns amber
    // in the last ten seconds.
    $this->travel(52)->seconds();
    $screen->call('poll');
    $meter = nodeByRef($screen, 'voice-meter')['children'];

    expect($meter)->toHaveCount(VoiceNotes::Bars)
        ->and($meter[VoiceNotes::Bars - 1]['layout']['height'] ?? null)->toEqual(12)
        ->and(nodeByRef($screen, 'voice-elapsed')['props']['text'])->toBe('0:52/1:00')
        ->and(strtolower(nodeByRef($screen, 'voice-elapsed')['props']['color'] ?? ''))->toBe(strtolower(config('native-ui.theme.light.pending')));
});

it('says it is opening the microphone until it records, as while the phone asks for it', function () {
    serveVoiceThread([remoteMessageJson(['id' => 1])]);
    Native::fakeBridge()->withMicrophoneStatus('idle');

    $screen = voiceThread()->tap('record-voice-note')->assertSee('opening microphone…');

    expect(nodeByRef($screen, 'push')['props']['disabled'] ?? false)->toBeTrue();

    // The clock counts from when the microphone starts, not from the tap.
    $this->travel(5)->seconds();
    $screen->bridge()->withMicrophoneStatus('recording');
    $screen->call('poll');
    $this->travel(2)->seconds();
    $screen->call('poll');

    expect(nodeByRef($screen, 'voice-elapsed')['props']['text'])->toBe('0:02/1:00')
        ->and(nodeByRef($screen, 'push')['props']['disabled'] ?? false)->toBeFalse();
});

it('stops to listen to the recording first, then pushes it staged at once and pushed once devnull has it', function () {
    serveVoiceThread([remoteMessageJson(['id' => 1])]);
    $recording = testRecording(2500);
    $bytes = file_get_contents($recording);

    $screen = voiceThread()->tap('record-voice-note')->tap('stop-voice-note');
    $screen->assertNativeCalled('Microphone.Stop')->assertSee('encoding…');

    expect(nodeByRef($screen, 'push')['props']['disabled'] ?? false)->toBeTrue();

    voiceRecorded($screen, $recording);
    $kept = $screen->instance()->recorded->url;

    // The recording itself, to play from the phone before it goes.
    expect(file_exists($recording))->toBeFalse()
        ->and(nodeByRef($screen, 'voice-draft-clock')['props']['text'])->toBe('0:02')
        ->and($screen->instance()->recorded->waveform)->toHaveCount(VoiceNotes::Bars)
        ->and($screen->instance()->recorded->waveform[VoiceNotes::Bars - 1])->toBe(71);

    $screen->tap('voice-draft-toggle')->assertNativeCalled('MediaPlayer.Play', fn (array $params): bool => $params['source'] === $kept);

    holdRefreshes()->respondTo('MediaPlayer.Play', ['success' => true]);
    $screen->tap('push')->assertSee('staged')->assertSet('recorder', null)->assertNativeCalled('MediaPlayer.Stop');
    $clientId = $screen->instance()->pending[0]->clientId;

    expect(childRefs($screen, 'message-plate'))->toBe(['message-field', 'composer-tools'])
        ->and(nodeByRef($screen, 'voice-'.$clientId.'-clock')['props']['text'])->toBe('0:02');

    finishRefreshes($screen)->assertSee('pushed')->assertSet('pending', []);

    [$payload, $voice] = voiceUploads()[0];

    expect(json_decode($payload['contents'], true))->toBe(['client_id' => $clientId, 'voice_waveform' => $screen->instance()->messages[1]->voice->waveform])
        ->and($voice)->toBe(['name' => 'voice', 'type' => 'audio/wav', 'contents' => $bytes])
        ->and(nodeByRef($screen, 'voice-99-clock')['props']['text'])->toBe('0:02')
        ->and(file_exists($kept))->toBeFalse();
});

it('pushes straight from the recording, as soon as the microphone hands it over', function () {
    serveVoiceThread([remoteMessageJson(['id' => 1, 'body' => 'say it'])]);

    $screen = voiceThread()->dragEnd('swipe-1', -200)->tap('reply-1')->tap('record-voice-note')->tap('push');
    $screen->assertNativeCalled('Microphone.Stop')->assertSet('pending', []);

    finishRefreshes(voiceRecorded($screen, testRecording()))->assertSet('pending', [])->assertSet('recorder', null);

    expect(json_decode(voiceUploads()[0][0]['contents'], true)['reply_to_id'])->toBe(1);
});

it('stops by itself at a minute, ready to listen to, as the web does', function () {
    serveVoiceThread([remoteMessageJson(['id' => 1])]);

    $screen = voiceRecorded(voiceThread()->tap('record-voice-note'), testRecording(60_000));

    expect($screen->instance()->recorder)->toBe('recorded')
        ->and(nodeByRef($screen, 'voice-draft-clock')['props']['text'])->toBe('1:00');
});

it('keeps a refused voice note on the phone, showing it, and sends it again with its retry', function () {
    serveVoiceThread([remoteMessageJson(['id' => 1])], pushStatus: 422);

    $screen = voiceRecorded(voiceThread()->tap('record-voice-note')->tap('stop-voice-note'), testRecording());
    $kept = $screen->instance()->recorded->url;

    finishRefreshes($screen->tap('push'))->assertSee('rejected ·');
    $clientId = $screen->instance()->pending[0]->clientId;

    // Back on the thread later, it's still there to play, and stays on the phone until it goes.
    $again = voiceThread()->assertSee('rejected ·');

    expect(nodeByRef($again, 'voice-'.$clientId.'-clock')['props']['text'])->toBe('0:02')
        ->and(file_exists($kept))->toBeTrue();

    serveVoiceThread([remoteMessageJson(['id' => 1])]);
    finishRefreshes($again->call('retry', $clientId))->assertSet('pending', []);

    expect(voiceUploads())->toHaveCount(2)
        ->and(voiceUploads()[1][1]['name'])->toBe('voice')
        ->and(file_exists($kept))->toBeFalse();
});

it('throws a recording away with the trash, and one that arrives after that too', function (bool $whileRecording) {
    serveVoiceThread([remoteMessageJson(['id' => 1])]);

    $screen = voiceThread()->tap('record-voice-note');
    $recording = testRecording();

    if (! $whileRecording) {
        voiceRecorded($screen->tap('stop-voice-note'), $recording);
        $recording = $screen->instance()->recorded->url;
    }

    $id = $screen->instance()->recordingId;
    $screen->tap('discard-voice-note')->assertSet('recorder', null);

    if ($whileRecording) {
        $screen->assertNativeCalled('Microphone.Stop')
            ->emitNative(MicrophoneRecorded::class, ['path' => $recording, 'mimeType' => 'audio/wav', 'id' => $id]);
    }

    expect(file_exists($recording))->toBeFalse()
        ->and(childRefs($screen, 'message-plate'))->toBe(['message-field', 'composer-tools'])
        ->and(voiceUploads())->toBe([]);
})->with(['while recording' => [true], 'once recorded' => [false]]);

it("says why a voice note can't be recorded, in the web's words", function (Closure $fail, string $message) {
    serveVoiceThread([remoteMessageJson(['id' => 1])]);

    $screen = $fail(voiceThread());

    $screen->assertNativeCalled('ToastKit.Show', fn (array $params): bool => $params['message'] === $message)
        ->assertSet('recorder', null)
        ->assertSet('pending', []);
})->with([
    'shorter than half a second' => [fn (TestableComponent $screen) => voiceRecorded($screen->tap('record-voice-note')->tap('stop-voice-note'), testRecording(400)), VoiceNotes::TooShort],
    'not the WAV it asked for' => [fn (TestableComponent $screen) => voiceRecorded($screen->tap('record-voice-note')->tap('stop-voice-note'), testRecording(format: pack('vvVVvv', 1, 2, 44100, 176400, 4, 16))), VoiceNotes::Unreadable],
    'the microphone not allowed' => [fn (TestableComponent $screen) => $screen->tap('record-voice-note')->emitNative(MicrophoneCancelled::class, ['cancelled' => true, 'reason' => 'permission_denied', 'id' => $screen->instance()->recordingId]), VoiceNotes::Denied],
    'the microphone not starting' => [function (TestableComponent $screen) {
        $screen->bridge()->respondTo('Microphone.Start', null);

        return $screen->tap('record-voice-note');
    }, VoiceNotes::CouldNotOpen],
]);

it("plays someone's voice note once it's downloaded, and pauses it where it is", function () {
    serveVoiceThread([remoteMessageJson(['id' => 1]), voiceMessageJson()]);
    holdRefreshes()->respondTo('MediaPlayer.Play', ['success' => true]);

    $screen = voiceThread();

    expect(nodeByRef($screen, 'voice-2-clock')['props']['text'])->toBe('0:06')
        ->and(nodeByRef($screen, 'voice-2-toggle')['props']['a11y_label'])->toBe('Play voice note')
        ->and(playedBars($screen, 'voice-2'))->toBe(0);

    // Nothing is downloaded until play is pressed, and a spinner shows meanwhile.
    $screen->tap('voice-2-toggle');

    expect(findNode(nodeByRef($screen, 'voice-2-toggle'), fn (array $node): bool => $node['type'] === 'activity_indicator'))->not->toBeNull();

    finishRefreshes($screen)->assertNativeCalled('MediaPlayer.Play', fn (array $params): bool => str_ends_with($params['source'], '/01a18436-9e52-7015-be72-4153d1cbabb4.mp4')
        && file_get_contents($params['source']) === 'ftypisom opus'
        && $params['rate'] == 1
        && $params['position'] == 0);

    expect(nodeByRef($screen, 'voice-2-toggle')['props']['a11y_label'])->toBe('Pause voice note')
        ->and(rendersEvery($screen))->toBe([250]);

    // A second in, the first eight bars are played.
    $this->travel(1)->seconds();
    $screen->call('poll');

    expect(nodeByRef($screen, 'voice-2-clock')['props']['text'])->toBe('0:01')
        ->and(playedBars($screen, 'voice-2'))->toBe(8);

    $screen->tap('voice-2-toggle')->assertNativeCalled('MediaPlayer.Pause');

    expect(nodeByRef($screen, 'voice-2-toggle')['props']['a11y_label'])->toBe('Play voice note')
        ->and(nodeByRef($screen, 'voice-2-clock')['props']['text'])->toBe('0:01')
        ->and(playedBars($screen, 'voice-2'))->toBe(8);

    // It carries on from there, from the copy already on the phone.
    $screen->tap('voice-2-toggle');

    $downloads = collect(Http::recorded())->filter(fn (array $sent): bool => str_contains($sent[0]->url(), '/remotes/voices/'));

    expect(array_column(Native::fakeBridge()->callsTo('MediaPlayer.Play'), 'params')[1]['position'])->toEqual(1.0)
        ->and($downloads)->toHaveCount(1);
});

it('plays a voice note from where its waveform is tapped', function () {
    serveVoiceThread([remoteMessageJson(['id' => 1]), voiceMessageJson()]);

    $screen = finishRefreshes(voiceThread()->tap('voice-2-from-24'));

    $screen->assertNativeCalled('MediaPlayer.Play', fn (array $params): bool => $params['position'] == 3.0);

    expect(nodeByRef($screen, 'voice-2-clock')['props']['text'])->toBe('0:03')
        ->and(nodeByRef($screen, 'voice-2-from-24')['props']['a11y_label'])->toBe('Play from 0:03');
});

it('steps through 1×, 1.5× and 2×, carrying on from where it is, and counts the clock at that speed', function () {
    serveVoiceThread([remoteMessageJson(['id' => 1]), voiceMessageJson()]);

    $screen = voiceThread()->tap('voice-2-speed');

    // At 1.5×, six seconds take four.
    expect(nodeByRef($screen, 'voice-2-speed')['props']['a11y_label'])->toBe('Playback speed, 1.5×')
        ->and(nodeByRef($screen, 'voice-2-clock')['props']['text'])->toBe('0:04');

    finishRefreshes($screen->tap('voice-2-toggle'))->assertNativeCalled('MediaPlayer.Play', fn (array $params): bool => $params['rate'] == 1.5);

    $this->travel(2)->seconds();
    $screen->tap('voice-2-speed')->assertNativeCalled('MediaPlayer.SetRate', fn (array $params): bool => $params['rate'] == 2);

    // Three seconds in after two at 1.5×, which reads 0:01 at 2×.
    expect(nodeByRef($screen, 'voice-2-clock')['props']['text'])->toBe('0:01')
        ->and(nodeByRef($screen, 'voice-2-speed')['props']['a11y_label'])->toBe('Playback speed, 2×');

    $screen->tap('voice-2-speed');

    expect(nodeByRef($screen, 'voice-2-speed')['props']['a11y_label'])->toBe('Playback speed, 1×')
        ->and($screen->instance()->voiceSpeeds)->toBe([]);
});

it('plays one voice note at a time, leaving the last one paused where it was', function () {
    serveVoiceThread([remoteMessageJson(['id' => 1]), voiceMessageJson(2), voiceMessageJson(3)]);

    $screen = finishRefreshes(voiceThread()->tap('voice-2-toggle'));
    $this->travel(2)->seconds();
    finishRefreshes($screen->tap('voice-3-toggle'))->assertNativeCalled('MediaPlayer.Pause');

    expect(nodeByRef($screen, 'voice-2-toggle')['props']['a11y_label'])->toBe('Play voice note')
        ->and(nodeByRef($screen, 'voice-2-clock')['props']['text'])->toBe('0:02')
        ->and(nodeByRef($screen, 'voice-3-toggle')['props']['a11y_label'])->toBe('Pause voice note');
});

it('goes back to the start once it has played', function () {
    serveVoiceThread([remoteMessageJson(['id' => 1]), voiceMessageJson()]);

    $screen = finishRefreshes(voiceThread()->tap('voice-2-toggle'));
    $this->travel(6)->seconds();
    $screen->emitNative(PlaybackEnded::class, ['source' => $screen->instance()->playbackSource]);

    expect(nodeByRef($screen, 'voice-2-toggle')['props']['a11y_label'])->toBe('Play voice note')
        ->and(nodeByRef($screen, 'voice-2-clock')['props']['text'])->toBe('0:06')
        ->and(playedBars($screen, 'voice-2'))->toBe(0);
});

it("offers the web's retry when a voice note can't be downloaded or played", function (Closure $fail) {
    serveVoiceThread([remoteMessageJson(['id' => 1]), voiceMessageJson()], voiceStatus: 404);

    $screen = $fail(voiceThread());

    expect(nodeByRef($screen, 'voice-2-toggle')['props']['a11y_label'])->toBe('Retry voice note');
})->with([
    'not served' => [fn (TestableComponent $screen) => finishRefreshes($screen->tap('voice-2-toggle'))],
    'not playable' => [function (TestableComponent $screen) {
        serveVoiceThread([remoteMessageJson(['id' => 1]), voiceMessageJson()], voiceType: 'audio/wav');
        finishRefreshes($screen->tap('voice-2-toggle'));

        return $screen->emitNative(PlaybackError::class, ['source' => $screen->instance()->playbackSource, 'message' => 'Cannot Open']);
    }],
    'not something devnull serves' => [function (TestableComponent $screen) {
        serveVoiceThread([remoteMessageJson(['id' => 1]), voiceMessageJson()], voiceType: 'text/html');

        return finishRefreshes($screen->tap('voice-2-toggle'));
    }],
]);

it('stops the voice note playing, and throws away one being recorded, when you leave the thread', function (Closure $leave) {
    serveVoiceThread([remoteMessageJson(['id' => 1]), voiceMessageJson()]);

    $screen = finishRefreshes(voiceThread()->tap('voice-2-toggle'))->tap('record-voice-note');

    $leave($screen)->assertNativeCalled('MediaPlayer.Stop')->assertNativeCalled('Microphone.Stop');
})->with([
    'back' => [fn (TestableComponent $screen) => $screen->pressBack()],
    "to Ada's profile" => [fn (TestableComponent $screen) => $screen->call('viewProfile')],
]);

it('says someone sent a voice note in the inbox, as the web does', function () {
    fakeApi(['GET /api/v1/remotes' => [remotesInboxJson([conversationJson(['last_message' => voiceMessageJson()])])]]);

    finishRefreshes(Native::visit('/remotes'))->assertSee('sent a voice note');
});

it('calls a voice note a voice note above a reply to it, and while replying to it', function () {
    serveVoiceThread([voiceMessageJson(), remoteMessageJson(['id' => 3, 'body' => 'got it', 'reply_to_id' => 2, 'reply_to' => ['id' => 2, 'user_id' => 'u2', 'body' => '', 'images_count' => 0, 'has_voice' => true]])]);

    $screen = voiceThread();

    expect(nodeByRef($screen, 'reply-ghost-3')['props']['a11y_label'])->toBe('Replying to Ada Lovelace: Voice note');

    $screen->dragEnd('swipe-2', -200)->tap('reply-2')->assertSee('Voice note');
});

it('opens the message menu on a long press on a voice note, without Copy text, and replies from it', function () {
    serveVoiceThread([voiceMessageJson()]);

    $screen = voiceThread();

    expect(nodeByRef($screen, 'bubble-2')['props']['has_context_menu'] ?? null)->toBeTrue()
        ->and(menuItems($screen, 'bubble-2'))->toBe([
            ['reply-menu-2', 'Reply', 'custom:reply'],
            ['react-menu-2', 'React', 'custom:smile-plus'],
        ]);

    $screen->tap('reply-menu-2')->assertSee('Voice note');
});
