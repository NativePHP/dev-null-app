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
 *
 * With EDGE's subtree reuse on, the row goes out as EDGE sends any node: a part
 * of it the phone already holds, by id and content hash, is sent as a reuse
 * marker and taken from the phone's last tree. So a row that hasn't changed
 * costs the frame one marker, and a starred one only the parts the star touched.
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

            return self::send($this->node, $lastNodeHashes);
        }

        // Built against no hashes, the row's node is whole, so it can be kept and sent again in any later frame.
        $sentNothing = [];
        $node = $this->row->toArray($registry, $nextId, $parentKeyPath, $indexInParent, $emittedIds, $sentNothing);
        $ids = [];
        self::collectIds($node, $ids);

        $this->node = $node;
        $this->nodePlace = $place;
        $this->nodeRegistry = $registry;
        $this->nodeIds = $ids;

        return self::send($node, $lastNodeHashes);
    }

    /**
     * A node as this frame sends it: a reuse marker where the phone already holds
     * it, by its id and hash, as EDGE's own walk does, and otherwise in full, its
     * children each sent the same way, and its hash noted as what the phone holds
     * next. With subtree reuse off the hashes start empty every frame, so every
     * node goes in full.
     *
     * @param  array<string, mixed>  $node
     * @param  array<int, string>  $lastNodeHashes
     * @return array<string, mixed>
     */
    private static function send(array $node, array &$lastNodeHashes): array
    {
        if (($lastNodeHashes[$node['id']] ?? null) === $node['_hash']) {
            return ['id' => $node['id'], 'type' => $node['type'], 'flags' => 1, '_hash' => $node['_hash']];
        }

        $lastNodeHashes[$node['id']] = $node['_hash'];

        if (isset($node['children'])) {
            foreach ($node['children'] as $i => $child) {
                $node['children'][$i] = self::send($child, $lastNodeHashes);
            }
        }

        return $node;
    }

    /**
     * Collects a node's ids, and its children's.
     *
     * @param  array<string, mixed>  $node
     * @param  array<int, true>  $ids
     */
    private static function collectIds(array $node, array &$ids): void
    {
        $ids[$node['id']] = true;

        foreach ($node['children'] ?? [] as $child) {
            self::collectIds($child, $ids);
        }
    }
}
