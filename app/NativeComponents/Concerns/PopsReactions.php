<?php

namespace App\NativeComponents\Concerns;

use App\Devnull\Beat;
use App\Devnull\Pop;

/**
 * A star you give, or a pastel, pops as on the web: it grows and comes back.
 *
 * EDGE eases a node from one frame's transform to the next, so the pop is
 * three frames a Beat apart (see Pop): drawn as it is with the animation on,
 * scaled up, then back. Without the first, the phone meets the animation and
 * the scaled-up icon in the same frame and has nothing to animate from. The
 * animation comes off again afterwards, as every node that has one costs the
 * list a little more to draw.
 */
trait PopsReactions
{
    /**
     * The buttons popping, "star-" or "pastel-" and the commit's id, at their step.
     *
     * @var array<string, string>
     */
    public array $popping = [];

    protected function pop(string $button): void
    {
        $this->popping[$button] = Pop::Ready;

        $this->popStep($button, Pop::Ready, Pop::Up, Beat::Step, fn () => $this->popStep($button, Pop::Up, Pop::Down, 150, fn () => $this->popStep($button, Pop::Down, null, 400)));
    }

    /**
     * Moves $button from $from to $to after $wait milliseconds, unless a tap since started it again.
     */
    private function popStep(string $button, string $from, ?string $to, int $wait, ?\Closure $then = null): void
    {
        $step = function () use ($button, $from, $to, $then): void {
            if (($this->popping[$button] ?? null) !== $from) {
                return;
            }

            if ($to === null) {
                unset($this->popping[$button]);

                return;
            }

            $this->popping[$button] = $to;

            if ($then !== null) {
                $then();
            }
        };

        Beat::dispatch($wait)->finished($step)->failed($step);
    }
}
