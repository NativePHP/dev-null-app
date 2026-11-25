<?php

namespace App\Devnull\Data;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * A profile's commit activity, scaled to how long they've been active: hourly
 * buckets for a day, daily ones for a week or a month, and a year of days.
 *
 * The web draws the year as a 53-week grid that scrolls sideways on a phone.
 * Here every scale is a strip, so the year sums each week into one cell.
 */
final readonly class Activity
{
    public const array Windows = [
        'day' => 'in the last 24 hours',
        'week' => 'in the last 7 days',
        'month' => 'in the last 30 days',
        'year' => 'in the last year',
    ];

    /**
     * @param  list<array{date: string, count: int}>  $buckets
     */
    public function __construct(public string $scale = 'year', public array $buckets = []) {}

    /**
     * Reads `{scale, buckets}`. An older server sent a bare list, which reads as no activity.
     */
    public static function fromArray(mixed $data): self
    {
        if (! is_array($data) || ! is_array($data['buckets'] ?? null)) {
            return new self;
        }

        $buckets = [];

        foreach ($data['buckets'] as $bucket) {
            if (is_array($bucket) && is_string($bucket['date'] ?? null) && is_int($bucket['count'] ?? null)) {
                $buckets[] = ['date' => $bucket['date'], 'count' => max(0, $bucket['count'])];
            }
        }

        return new self(in_array($data['scale'] ?? null, array_keys(self::Windows), true) ? $data['scale'] : 'year', $buckets);
    }

    public function total(): int
    {
        return array_sum(array_column($this->buckets, 'count'));
    }

    /**
     * The web says "No commits yet" over an empty strip, except for a whole year.
     */
    public function isEmpty(): bool
    {
        return $this->total() === 0 && $this->scale !== 'year';
    }

    public function window(): string
    {
        return self::Windows[$this->scale];
    }

    /**
     * The cells to draw, oldest first, each with the label that starts there.
     *
     * @return list<ActivityCell>
     */
    public function cells(): array
    {
        $periods = $this->scale === 'year' ? $this->weeks() : $this->buckets;
        $max = max([1, ...array_column($periods, 'count')]);
        $cells = [];

        foreach ($periods as $index => $period) {
            $level = $period['count'] === 0 ? 0 : max(1, (int) ceil($period['count'] / $max * 4));

            $cells[] = new ActivityCell($period['count'], $level, $this->label($periods, $index));
        }

        return $cells;
    }

    /**
     * The labels under the strip, each spanning the cells up to the next one.
     *
     * @return list<array{label: ?string, span: int}>
     */
    public function labels(): array
    {
        $groups = [];

        foreach ($this->cells() as $cell) {
            if ($cell->label !== null || $groups === []) {
                $groups[] = ['label' => $cell->label, 'span' => 0];
            }

            $groups[count($groups) - 1]['span']++;
        }

        return $groups;
    }

    /**
     * The web labels every six hours, every day, every Monday, or each new
     * month. Labels too close to the end, or to the next one, are left off.
     *
     * @param  list<array{date: string, count: int}>  $periods
     */
    private function label(array $periods, int $index): ?string
    {
        $day = self::parse($periods[$index]['date']);
        $length = count($periods);

        if ($day === null) {
            return null;
        }

        return match ($this->scale) {
            'day' => $day->hour % 6 === 0 && $index < $length - 2 ? $day->format('H:i') : null,
            'week' => $day->format('D j'),
            'month' => $day->isMonday() && $index < $length - 3 ? $day->format('M j') : null,
            default => $this->startsMonth($periods, $index) && $index < $length - 2 ? $day->format('M') : null,
        };
    }

    /**
     * @param  list<array{date: string, count: int}>  $periods
     */
    private function startsMonth(array $periods, int $index): bool
    {
        $month = fn (int $at): ?int => isset($periods[$at]) ? self::parse($periods[$at]['date'])?->month : null;

        if ($index > 0) {
            return $month($index - 1) !== $month($index);
        }

        // The first week only gets a label when the next month isn't about to start.
        return $month(1) === $month(0) && $month(2) === $month(0);
    }

    /**
     * The year's days, a week to a bucket. The server starts the year on a Sunday.
     *
     * @return list<array{date: string, count: int}>
     */
    private function weeks(): array
    {
        return array_values(array_map(fn (array $week): array => [
            'date' => $week[0]['date'],
            'count' => array_sum(array_column($week, 'count')),
        ], array_chunk($this->buckets, 7)));
    }

    private static function parse(string $date): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($date);
        } catch (Throwable) {
            return null;
        }
    }
}
