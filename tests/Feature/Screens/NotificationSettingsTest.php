<?php

use App\Devnull\Account;
use App\Devnull\NotificationFilter;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    config(['devnull.url' => 'http://devnull.test']);
    signIn();
});

it("has a real switch for each of the web's kinds, all on until you turn one off", function () {
    $screen = Native::visit('/notifications/settings')
        ->assertNavTitle('Notification settings')
        ->assertSee('Show in Notifications');

    foreach (NotificationFilter::Kinds as $kind => [$label, $description]) {
        $switch = nodeByRef($screen, "kind-{$kind}-switch");

        $screen->assertSee($label)->assertSee($description);

        expect($switch['type'])->toBe('toggle')
            ->and($switch['props']['value'] ?? null)->toBeTrue()
            ->and($switch['props']['a11y_label'] ?? null)->toBe($label);
    }
});

it('saves each switch as it flips, for you on this phone, without asking devnull', function () {
    Http::fake();
    $me = app(Account::class)->user()->id;

    $screen = Native::visit('/notifications/settings')->toggle('kind-stars-switch', false);

    expect(app(NotificationFilter::class)->enabled($me))->toBe(['replies', 'quotes', 'mentions', 'follows', 'moderation'])
        ->and(nodeByRef($screen, 'kind-stars-switch')['props']['value'] ?? null)->toBeFalse();

    $screen->toggle('kind-replies-switch', false)->toggle('kind-stars-switch', true);

    expect(app(NotificationFilter::class)->enabled($me))->toBe(['quotes', 'stars', 'mentions', 'follows', 'moderation']);

    // Opened again, it shows what was saved.
    expect(nodeByRef(Native::visit('/notifications/settings'), 'kind-replies-switch')['props']['value'] ?? null)->toBeFalse();

    Http::assertNothingSent();
});

it('turns every kind off, which the list then says, and ignores a kind it does not know', function () {
    $me = app(Account::class)->user()->id;
    $screen = Native::visit('/notifications/settings');

    foreach (array_keys(NotificationFilter::Kinds) as $kind) {
        $screen->toggle("kind-{$kind}-switch", false);
    }

    $screen->call('setKind', 'reactions', true);

    expect(app(NotificationFilter::class)->enabled($me))->toBe([])
        ->and(NotificationFilter::type([]))->toBe('none');
});

it('keeps each user their own choice', function () {
    $filter = app(NotificationFilter::class);

    $filter->save('someone', ['stars']);

    expect($filter->enabled('someone-else'))->toBe(array_keys(NotificationFilter::Kinds))
        ->and($filter->enabled('someone'))->toBe(['stars'])
        ->and($filter->enabled(null))->toBe(array_keys(NotificationFilter::Kinds));

    // Unknown kinds are dropped, and the rest go back in the web's order.
    $filter->save('someone', ['follows', 'nope', 'replies', 'follows']);

    expect($filter->enabled('someone'))->toBe(['replies', 'follows'])
        ->and(NotificationFilter::type(['follows', 'replies']))->toBe('replies,follows');
});
