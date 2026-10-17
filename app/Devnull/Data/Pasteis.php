<?php

namespace App\Devnull\Data;

use Carbon\CarbonImmutable;

/**
 * Your pastéis for today, while pastéis mode is on: how many you can still
 * give to other people's commits, and when the day's allowance comes back.
 *
 * As on the web, everyone gets ten a day, one per tap, and the allowance
 * resets at 00:00 UTC rather than 24 hours after the first one went. So once
 * that moment has passed, you have all ten again, whatever was kept before.
 */
final readonly class Pasteis
{
    public const int Daily = 10;

    /**
     * The web's pastel de nata, drawn from its SVG at three times 20pt. It's in
     * colour, so it ships as a picture rather than a tinted icon.
     */
    public const string Image = 'images/pastel-de-nata@3x.png';

    /**
     * What the web says when you have none left, word for word.
     */
    public const string Exhausted = 'you ran out of pastéis. wait until tomorrow.';

    /**
     * What the web says when a pastel didn't go, for any other reason.
     */
    public const string CouldNotGive = 'Could not award a pastel. Please try again.';

    public function __construct(public int $remaining = self::Daily, public ?CarbonImmutable $resetsAt = null) {}

    /**
     * @param  array<string, mixed>  $kept
     */
    public static function fromKept(array $kept): self
    {
        $remaining = $kept['remaining'] ?? null;

        return new self(is_int($remaining) ? max(0, min(self::Daily, $remaining)) : self::Daily, Commit::date($kept['resets_at'] ?? null));
    }

    /**
     * How many you can give now: all ten once the day they were counted for is over.
     */
    public function left(): int
    {
        return $this->today()->remaining;
    }

    /**
     * After one more goes, before devnull has said so.
     */
    public function afterGiving(): self
    {
        $today = $this->today();

        return new self(max(0, $today->remaining - 1), $today->resetsAt);
    }

    /**
     * After a pastel that didn't go is handed back.
     */
    public function afterTakingBack(): self
    {
        $today = $this->today();

        return new self(min(self::Daily, $today->remaining + 1), $today->resetsAt);
    }

    /**
     * None left until the reset, as devnull said when it turned one down.
     */
    public function exhausted(): self
    {
        return new self(0, $this->today()->resetsAt);
    }

    /**
     * What devnull said is left after a pastel went. With others still on
     * their way, the fewer of the two is the truer, unless devnull has moved
     * on to another day.
     */
    public function settledWith(int $remaining, ?CarbonImmutable $resetsAt): self
    {
        $today = $this->today();
        $sameDay = $resetsAt === null || $resetsAt->equalTo($today->resetsAt);

        return new self(max(0, $sameDay ? min($today->remaining, $remaining) : $remaining), $resetsAt ?? $today->resetsAt);
    }

    /**
     * @return array{remaining: int, resets_at: ?string}
     */
    public function toArray(): array
    {
        return ['remaining' => $this->remaining, 'resets_at' => $this->resetsAt?->toIso8601ZuluString()];
    }

    /**
     * These pastéis as of now: a fresh ten once the reset has passed.
     */
    private function today(): self
    {
        if ($this->resetsAt === null) {
            return new self($this->remaining, self::nextReset());
        }

        return CarbonImmutable::now()->greaterThanOrEqualTo($this->resetsAt) ? new self(self::Daily, self::nextReset()) : $this;
    }

    /**
     * The next 00:00 UTC, when the web gives everyone ten again.
     */
    private static function nextReset(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->addDay()->startOfDay();
    }
}
