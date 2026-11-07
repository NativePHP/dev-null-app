<?php

use App\Devnull\Account;
use App\Devnull\Client;
use App\Devnull\TranslationLanguage;
use App\NativeComponents\ThisDevice;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Events\Async\AsyncTaskFinished;
use Native\Mobile\Support\AsyncTaskTransport;
use Native\Mobile\Testing\Native;

it('defaults automatic display on and preserves translation preferences across partial settings answers', function () {
    $account = app(Account::class);
    expect($account->automaticTranslation())->toBeTrue()
        ->and($account->translationLanguage())->toBeNull();

    $languages = [['code' => 'pt', 'name' => 'Portuguese', 'native_name' => 'Português']];
    $account->rememberTranslationSettings([
        'translation_language' => 'pt',
        'automatic_translation' => false,
        'translation_languages' => [...$languages, null, ['code' => 7]],
    ]);
    $account->rememberTranslationSettings(['timezone' => 'Europe/Lisbon']);

    expect($account->translationLanguage())->toBe('pt')
        ->and($account->automaticTranslation())->toBeFalse()
        ->and($account->translationLanguages())->toBe($languages);

    $account->rememberTranslationSettings(['translation_language' => null, 'automatic_translation' => true]);
    expect($account->translationLanguage())->toBeNull()
        ->and($account->automaticTranslation())->toBeTrue();
});

it('clears account translation preferences on signout', function () {
    $account = app(Account::class);
    $account->rememberTranslationSettings(['translation_language' => 'fr', 'automatic_translation' => false]);
    $account->forget();

    expect($account->translationLanguage())->toBeNull()
        ->and($account->automaticTranslation())->toBeTrue()
        ->and($account->translationLanguages())->toBe([]);
});

it('uses the phone primary language or English and sends it on signed requests', function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
    Native::fakeBridge()->respondTo('Device.GetInfo', ['info' => json_encode(['language' => 'pt-BR'])]);
    app()->forgetInstance(TranslationLanguage::class);
    $client = app(Client::class);

    expect($client->signedGet('log/recent')->headers['Accept-Language'])->toBe('pt')
        ->and($client->signedWrite('POST', Client::translationPath('a/b'), ['language' => 'pt'])->url)->toEndWith('/commits/a%2Fb/translation');

    app(Account::class)->rememberTranslationSettings(['translation_language' => 'fr']);
    expect($client->signedGet('log/recent')->headers['Accept-Language'])->toBe('fr');

    app(Account::class)->rememberTranslationSettings(['translation_language' => null, 'translation_languages' => [['code' => 'en', 'name' => 'English', 'native_name' => 'English']]]);
    expect($client->signedGet('log/recent')->headers['Accept-Language'])->toBe('en');
});

it('searches English and native language names and saves sent preferences despite a me-only response', function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
    $languages = [
        ['code' => 'en', 'name' => 'English', 'native_name' => 'English'],
        ['code' => 'de', 'name' => 'German', 'native_name' => 'Deutsch'],
    ];
    serveApi([
        'GET /api/v1/me' => [currentUserJson()],
        'GET /api/v1/me/settings' => [['data' => ['translation_language' => null, 'automatic_translation' => true, 'translation_languages' => $languages]]],
        'PUT /api/v1/me/translation-language' => [currentUserJson()],
        'PUT /api/v1/me/automatic-translation' => [currentUserJson()],
    ]);
    $screen = Native::test(ThisDevice::class);
    $screen->set('translationLanguageQuery', 'Deutsch');
    expect($screen->instance()->translationLanguages())->toBe([$languages[1]]);
    $screen->set('translationLanguageQuery', 'German');
    expect($screen->instance()->translationLanguages())->toBe([$languages[1]]);

    $screen->call('chooseTranslationLanguage', 'de')->call('saveTranslationLanguage')->call('setAutomaticTranslation', false);
    expect(app(Account::class)->translationLanguage())->toBe('de')
        ->and(app(Account::class)->automaticTranslation())->toBeFalse()
        ->and($screen->get('savedTranslationLanguage'))->toBe('de');
    Http::assertSent(fn ($request): bool => $request->method() === 'PUT' && str_ends_with($request->url(), '/me/translation-language') && $request['translation_language'] === 'de');
    Http::assertSent(fn ($request): bool => $request->method() === 'PUT' && str_ends_with($request->url(), '/me/automatic-translation') && $request['enabled'] === false);
});

it('renders a bounded searchable language picker and hides unsupported settings on older servers', function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
    serveApi(['GET /api/v1/me' => [currentUserJson()], 'GET /api/v1/me/settings' => [['data' => ['timezone' => null]]]]);
    Native::test(ThisDevice::class)->assertDontSee('Choose translation language');
    $languages = array_map(fn (int $index): array => ['code' => 'l'.$index, 'name' => 'Language '.$index, 'native_name' => 'Native '.$index], range(1, 125));
    app(Account::class)->rememberTranslationSettings(['translation_languages' => $languages]);
    $screen = Native::test(ThisDevice::class)->tap('choose-translation-language')->assertSee('Translation language')->assertSee('75 more. Keep typing to find yours.');
    expect($screen->instance()->translationLanguages())->toHaveCount(50);
    $screen->input('translation-language-search', 'Native 125')->assertSee('Language 125');
    expect($screen->instance()->translationLanguages())->toHaveCount(1);
    $screen->tap('translation-language-l125')->assertSet('chosenTranslationLanguage', 'l125')->assertSet('pickingTranslationLanguage', false);
});

it('restores automatic display and leaves the language retryable when saves fail', function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
    serveApi([
        'GET /api/v1/me' => [currentUserJson()],
        'GET /api/v1/me/settings' => [['data' => ['translation_languages' => [['code' => 'de', 'name' => 'German', 'native_name' => 'Deutsch']]]]],
        'PUT /api/v1/me/automatic-translation' => [['message' => 'Try again.'], 503],
        'PUT /api/v1/me/translation-language' => [['message' => 'Try again.'], 503],
    ]);
    $screen = Native::test(ThisDevice::class)->call('setAutomaticTranslation', false)->assertSet('automaticTranslation', true)->assertSee('Try again.');
    $screen->call('chooseTranslationLanguage', 'de')->call('saveTranslationLanguage')->assertSet('savingTranslationLanguage', false)->assertSet('savedTranslationLanguage', null)->assertSet('chosenTranslationLanguage', 'de');
    expect(app(Account::class)->translationLanguage())->toBeNull();
});

it('recovers dropped preference writes on Account resume and ignores obsolete callbacks', function (string $setting) {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
    $languages = [
        ['code' => 'de', 'name' => 'German', 'native_name' => 'Deutsch'],
        ['code' => 'fr', 'name' => 'French', 'native_name' => 'Français'],
    ];
    serveApi([
        'GET /api/v1/me' => [currentUserJson()],
        'GET /api/v1/me/settings' => [['data' => ['translation_language' => null, 'automatic_translation' => true, 'translation_languages' => $languages]]],
    ]);
    holdRefreshes();
    $screen = Native::test(ThisDevice::class);
    finishRefreshes($screen);
    if ($setting === 'language') {
        $screen->call('chooseTranslationLanguage', 'de')->call('saveTranslationLanguage')->assertSet('savingTranslationLanguage', true);
    } else {
        $screen->call('setAutomaticTranslation', false)->assertSet('savingAutomaticTranslation', true);
    }
    foreach ($screen->bridge()->callsTo('AsyncTask.Dispatch') as $call) {
        $payload = AsyncTaskTransport::readPayload($call['params']['id']);
        $work = $payload === null ? [] : unserialize($payload);
        if (($work['args'][2] ?? null) === 'PUT') {
            $obsoleteId = $call['params']['id'];
        }
    }
    // Its completion was dropped off-screen; the fresh server state may also reflect a later settings change.
    serveApi([
        'GET /api/v1/me' => [currentUserJson()],
        'GET /api/v1/me/settings' => [['data' => ['translation_language' => 'fr', 'automatic_translation' => true, 'translation_languages' => $languages]]],
    ]);
    $screen->call('onResume')->assertSet('savingTranslationLanguage', false)->assertSet('savingAutomaticTranslation', false);
    finishRefreshes($screen);
    $screen->assertSet('savedTranslationLanguage', 'fr')->assertSet('chosenTranslationLanguage', 'fr')->assertSet('automaticTranslation', true);
    $screen->emitNative(AsyncTaskFinished::class, ['id' => $obsoleteId, 'result' => ['status' => 200, 'from_devnull' => true, 'json' => currentUserJson()]]);
    expect(app(Account::class)->translationLanguage())->toBe('fr')
        ->and(app(Account::class)->automaticTranslation())->toBeTrue();
    $screen->assertSet('savedTranslationLanguage', 'fr')->assertSet('automaticTranslation', true);
})->with(['language', 'automatic']);
