<?php

use App\Devnull\CachedRow;
use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;

/**
 * A frame as EDGE's runloop makes it with subtree reuse on: the hashes of what
 * the phone holds go in, and only the ids the frame drew are kept for the next.
 *
 * @param  array<int, string>  $hashes
 * @return array<string, mixed>
 */
function cachedRowFrame(Element $list, CallbackRegistry $registry, array &$hashes): array
{
    $nextId = 1;
    $emitted = [];
    $frame = $list->toArray($registry, $nextId, '', 0, $emitted, $hashes);
    $hashes = array_intersect_key($hashes, $emitted);

    return $frame;
}

/**
 * Each node of a frame, by id, and whether it went as a reuse marker.
 *
 * @param  array<string, mixed>  $node
 * @return array<int, bool>
 */
function cachedRowMarkers(array $node): array
{
    $markers = [$node['id'] => (($node['flags'] ?? 0) & 1) === 1];

    foreach ($node['children'] ?? [] as $child) {
        $markers += cachedRowMarkers($child);
    }

    return $markers;
}

function keptRow(string $key, string $body, string $time = '5m'): CachedRow
{
    return new CachedRow(Column::make(Text::make($body), Text::make($time))->key($key));
}

it('sends a kept row the phone already holds as a reuse marker', function () {
    $registry = new CallbackRegistry;
    $hashes = [];
    $rows = [keptRow('c1', 'First'), keptRow('c2', 'Second')];

    $first = cachedRowFrame(Column::make(...$rows)->key('list'), $registry, $hashes);
    expect(array_filter(cachedRowMarkers($first)))->toBe([]);

    // A row goes on top: the list goes again, the two rows under it as markers.
    $second = cachedRowFrame(Column::make(keptRow('c3', 'Third'), ...$rows)->key('list'), $registry, $hashes);
    [$new, $c1, $c2] = $second['children'];

    expect($second['flags'] ?? 0)->toBe(0)
        ->and($new['flags'] ?? 0)->toBe(0)
        ->and($c1)->toBe(['id' => $first['children'][0]['id'], 'type' => 'column', 'flags' => 1, '_hash' => $first['children'][0]['_hash']])
        ->and($c2['flags'])->toBe(1);
});

it('sends only the parts of a row that changed', function () {
    $registry = new CallbackRegistry;
    $hashes = [];

    $first = cachedRowFrame(Column::make(keptRow('c1', 'First', '5m'))->key('list'), $registry, $hashes);
    $second = cachedRowFrame(Column::make(keptRow('c1', 'First', '6m'))->key('list'), $registry, $hashes);
    [$body, $time] = $second['children'][0]['children'];

    expect($second['children'][0]['flags'] ?? 0)->toBe(0)
        ->and($body['flags'])->toBe(1)
        ->and($body['id'])->toBe($first['children'][0]['children'][0]['id'])
        ->and($time['flags'] ?? 0)->toBe(0)
        ->and($time['props'] ?? null)->not->toBeNull();
});

it('sends a kept row in full when the phone has lost it, and as a marker the frame after', function () {
    $registry = new CallbackRegistry;
    $hashes = [];
    $list = Column::make(keptRow('c1', 'First'), keptRow('c2', 'Second'))->key('list');

    $first = cachedRowFrame($list, $registry, $hashes);

    // EDGE drops every hash when the phone may have lost its tree, as on its heartbeat.
    $hashes = [];
    expect(cachedRowFrame($list, $registry, $hashes))->toBe($first);

    $third = cachedRowFrame($list, $registry, $hashes);
    expect($third['flags'])->toBe(1);
});

it('sends every frame in full with subtree reuse off', function () {
    $registry = new CallbackRegistry;
    $list = Column::make(keptRow('c1', 'First'))->key('list');

    $throwaway = [];
    $first = cachedRowFrame($list, $registry, $throwaway);
    $throwaway = [];

    expect(cachedRowFrame($list, $registry, $throwaway))->toBe($first)
        ->and(array_filter(cachedRowMarkers($first)))->toBe([]);
});
