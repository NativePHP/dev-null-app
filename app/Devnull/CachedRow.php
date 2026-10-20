<?php

namespace App\Devnull;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\Element;

/**
 * A row RowCache built once and draws again while nothing it shows has changed.
 *
 * It isn't a node of its own: it sends its row's node in its place, with the
 * row's id, so the phone gets exactly the frame the row would have made. The
 * row's node is kept too, and sent again as long as the row sits under the same
 * keyed parent, since a node's id comes from its parent's key path and its
 * callbacks' ids from their expressions alone.
 *
 * A kept node is only sent again under the same callback registry, which keeps
 * every expression it has seen (CallbackRegistry::reset() keeps them), and only
 * when none of its ids is already in the frame. Otherwise, and for a row with no
 * keyed parent, whose ids would come from the frame's running count, the row
 * makes its node afresh.
 */
final class CachedRow extends Element
{
    protected string $type = 'cached_row';

    /**
     * @var array<string, mixed>|null
     */
    private ?array $node = null;

    private string $nodePlace = '';

    private ?CallbackRegistry $nodeRegistry = null;

    /**
     * @var array<int, true>
     */
    private array $nodeIds = [];

    public function __construct(private readonly Element $row) {}

    /**
     * @param  array<int, true>  $emittedIds
     * @param  array<int, string>  $lastNodeHashes
     * @return array<string, mixed>
     */
    public function toArray(
        CallbackRegistry $registry,
        int &$nextId = 1,
        string $parentKeyPath = '',
        int $indexInParent = 0,
        array &$emittedIds = [],
        array &$lastNodeHashes = []
    ): array {
        if ($parentKeyPath === '') {
            return $this->row->toArray($registry, $nextId, $parentKeyPath, $indexInParent, $emittedIds, $lastNodeHashes);
        }

        $place = $this->row->key !== null ? $parentKeyPath : $parentKeyPath."\0".$indexInParent;

        if ($this->node !== null
            && $this->nodePlace === $place
            && $this->nodeRegistry === $registry
            && array_intersect_key($this->nodeIds, $emittedIds) === []) {
            $emittedIds += $this->nodeIds;

            return $this->node;
        }

        $node = $this->row->toArray($registry, $nextId, $parentKeyPath, $indexInParent, $emittedIds, $lastNodeHashes);
        $ids = [];

        if (self::collectFullIds($node, $ids)) {
            $this->node = $node;
            $this->nodePlace = $place;
            $this->nodeRegistry = $registry;
            $this->nodeIds = $ids;
        } else {
            $this->node = null;
        }

        return $node;
    }

    /**
     * Collects a node's ids, and its children's. False when any of them is a
     * reuse marker, which only stands for a node the phone got in an earlier frame.
     *
     * @param  array<string, mixed>  $node
     * @param  array<int, true>  $ids
     */
    private static function collectFullIds(array $node, array &$ids): bool
    {
        if ((($node['flags'] ?? 0) & 1) !== 0) {
            return false;
        }

        $ids[$node['id']] = true;

        foreach ($node['children'] ?? [] as $child) {
            if (! self::collectFullIds($child, $ids)) {
                return false;
            }
        }

        return true;
    }
}
