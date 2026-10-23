<?php

use App\Devnull\NotificationFilters;
use App\Devnull\ShownTabs;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->filters = app(NotificationFilters::class);
});

it('sends the categories the web\'s way: all six as all, none as none, and anything between comma-separated in its order', function (array $categories, string $type) {
    expect(NotificationFilters::typeOf($categories))->toBe($type);
})->with([
    'all' => [['replies', 'quotes', 'stars', 'mentions', 'follows', 'moderation'], 'all'],
    'none' => [[], 'none'],
    'one' => [['mentions'], 'mentions'],
    'several' => [['mentions', 'replies'], 'replies,mentions'],
]);

it('puts a selection in the web\'s order, without repeats and without anything that isn\'t one of its categories', function (array|string $sent, array $kept) {
    expect(NotificationFilters::normalize($sent))->toBe($kept);
})->with([
    'reordered' => [['moderation', 'replies', 'stars'], ['replies', 'stars', 'moderation']],
    'repeated' => [['stars', 'stars'], ['stars']],
    'the comma-separated form' => ['follows,stars,follows', ['stars', 'follows']],
    'all, the web\'s word for every one of them' => ['all', ['replies', 'quotes', 'stars', 'mentions', 'follows', 'moderation']],
    'none, the web\'s word for not one of them' => ['none', []],
    'unknown words dropped' => [['stars', 'nonsense', '0'], ['stars']],
    'nothing known left' => [['nonsense'], []],
]);

it('keeps each person their own selection, with all of them on until they pick', function () {
    $this->filters->remember('u1', ['stars', 'replies']);
    $this->filters->remember('u2', ['mentions']);
    $this->filters->remember(null, ['follows']);

    expect($this->filters->enabled('u1'))->toBe(['replies', 'stars'])
        ->and($this->filters->type('u1'))->toBe('replies,stars')
        ->and($this->filters->enabled('u2'))->toBe(['mentions'])
        ->and($this->filters->type('u2'))->toBe('mentions')
        ->and($this->filters->enabled('u3'))->toBe(NotificationFilters::Categories)
        ->and($this->filters->type('u3'))->toBe('all')
        ->and($this->filters->enabled(null))->toBe(NotificationFilters::Categories);
});

it('keeps nothing selected as none, and every category as all', function () {
    $this->filters->remember('u1', []);
    $this->filters->remember('u2', NotificationFilters::Categories);

    expect($this->filters->enabled('u1'))->toBe([])
        ->and($this->filters->type('u1'))->toBe('none')
        ->and($this->filters->type('u2'))->toBe('all');
});

it('opens on the one filter an app that kept a single one had on, and on all of them when it kept All', function (string $kept, array $enabled) {
    app(ShownTabs::class)->remember('u1', 'notifications', $kept);

    expect($this->filters->enabled('u1'))->toBe($enabled);
})->with([
    'a single category' => ['mentions', ['mentions']],
    'all' => ['all', NotificationFilters::Categories],
]);

it('keeps nothing a new selection would not keep, and nothing foreign the cache holds', function (mixed $held, array $enabled) {
    Cache::forever('devnull.notification-filters.u1', $held);

    expect($this->filters->enabled('u1'))->toBe($enabled);
})->with([
    'a category that isn\'t one of them, beside one that is' => [['replies', 'nonsense'], NotificationFilters::Categories],
    'a plain string' => ['replies', NotificationFilters::Categories],
    'someone\'s shape of it' => [['mentions' => true], NotificationFilters::Categories],
    'a number' => [3, NotificationFilters::Categories],
    'a list kept inside the list' => [['stars', ['mentions']], NotificationFilters::Categories],
    'a number kept among them' => [['stars', 2], NotificationFilters::Categories],
    'nothing kept but numbers' => [[1, 2], NotificationFilters::Categories],
    'nothing kept but null' => [[null], NotificationFilters::Categories],
    'an object' => [(object) ['replies' => true], NotificationFilters::Categories],
]);

it('has the single filter a new selection replaces, once one is kept', function () {
    app(ShownTabs::class)->remember('u1', 'notifications', 'mentions');

    expect($this->filters->enabled('u1'))->toBe(['mentions']);

    $this->filters->remember('u1', ['quotes', 'stars']);

    expect($this->filters->enabled('u1'))->toBe(['quotes', 'stars'])
        ->and($this->filters->type('u1'))->toBe('quotes,stars');
});
