<?php

namespace App\Devnull;

use Carbon\CarbonImmutable;

/**
 * How long a Remotes conversation or org stays muted, as devnull takes it on
 * `PUT .../mute` and the web's Mute dialog offers it. Off unmutes.
 *
 * until() works out what devnull will answer, so a row can show it muted
 * before devnull has: forever is the far end of the calendar, and tomorrow is
 * the coming midnight on the phone's clock, which devnull counts in the
 * timezone it has for you. Its own answer takes over once it comes.
 */
enum MuteDuration: string
{
    case Hour = 'hour';
    case Tomorrow = 'tomorrow';
    case Week = 'week';
    case Forever = 'forever';
    case Off = 'off';

    /**
     * The durations the web's dialog offers, in its order, with its words.
     *
     * @return array<string, string>
     */
    public static function choices(): array
    {
        return [
            self::Hour->value => 'For one hour',
            self::Tomorrow->value => 'Until tomorrow',
            self::Week->value => 'For one week',
            self::Forever->value => 'Forever',
        ];
    }

    public function until(): ?CarbonImmutable
    {
        return match ($this) {
            self::Hour => CarbonImmutable::now()->startOfSecond()->addHour(),
            self::Tomorrow => CarbonImmutable::now()->addDay()->startOfDay()->utc(),
            self::Week => CarbonImmutable::now()->startOfSecond()->addWeek(),
            self::Forever => CarbonImmutable::parse('9999-12-31T00:00:00Z'),
            self::Off => null,
        };
    }
}
