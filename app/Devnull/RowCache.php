<?php

namespace App\Devnull;

use Native\Mobile\Edge\NativeElementCollector;
use WeakMap;

/**
 * The rows a list drew last frame, kept so the next frame only builds the rows
 * that changed. EDGE builds every frame from scratch, so without it a list's
 * every render costs more with every page it loads: a few pages into the log,
 * each one took most of a second on an iPhone, whether anything had changed or
 * not.
 *
 * A row is kept against everything it shows, hashed: the partial's data and
 * anything else the caller passes in $inputs, which has to cover what the
 * partial reads from the screen or works out from the time or the device. A row
 * the frame doesn't draw is let go at the start of the next one.
 *
 * Only for partials that draw exactly one root element. Objects in a partial's
 * data have to be readonly, like App\Devnull\Data's: each is hashed once and
 * the hash kept for as long as the object lives.
 */
final class RowCache
{
    /**
     * @var array<string, array{0: string, 1: CachedRow}>
     */
    private array $rows = [];

    /**
     * @var array<string, true>
     */
    private array $drawn = [];

    /**
     * @var WeakMap<object, string>
     */
    private WeakMap $fingerprints;

    public function __construct()
    {
        $this->fingerprints = new WeakMap;
    }

    /**
     * Starts a frame, letting go of the rows the last one didn't draw.
     */
    public function startFrame(): void
    {
        $this->rows = array_intersect_key($this->rows, $this->drawn);
        $this->drawn = [];
    }

    /**
     * Draws a partial as @include would, or the row it drew last time with the same inputs.
     *
     * @param  array<array-key, mixed>  $inputs
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $scope  The including view's variables, which an include passes on.
     */
    public function include(string $key, array $inputs, string $view, array $data, array $scope): void
    {
        $render = fn () => view()->make($view, $data, $scope)->render();

        // The same row twice in a frame is drawn again, so the two never share a node.
        if (isset($this->drawn[$key])) {
            $render();

            return;
        }

        $this->drawn[$key] = true;
        $hash = hash('xxh3', serialize([$view, array_map($this->fingerprint(...), $data), $inputs]));
        [$keptHash, $row] = $this->rows[$key] ?? [null, null];

        if ($keptHash !== $hash || $row === null) {
            $row = new CachedRow(NativeElementCollector::capture($render));
            $this->rows[$key] = [$hash, $row];
        }

        NativeElementCollector::attachElement($row);
    }

    /**
     * A data object's hash in its place, worked out once per object, since
     * serializing each commit every frame was most of what a kept row cost.
     */
    private function fingerprint(mixed $value): mixed
    {
        if (! is_object($value)) {
            return $value;
        }

        return $this->fingerprints[$value] ??= hash('xxh3', serialize($value));
    }
}
