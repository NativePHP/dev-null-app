<?php

namespace App\Devnull;

use App\Devnull\Data\CommitBody;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * How the machine prints things: relative times and short hashes, as the web does.
 */
final class Display
{
    /**
     * The commit page's reply bar with one line of draft: its hairline, the
     * field and the padding around them, as drawn on an iPhone.
     */
    public const int ReplyBarHeight = 53;

    public const int ReplyBarLineHeight = 21;

    public const int ReplyBarMaxLines = 5;

    public const int ReplyBarCharactersPerLine = 34;

    /**
     * The row under the reply bar's field that marks a restored draft (see partials/stash-label) and counts its photos.
     */
    public const int ReplyBarStashLabelHeight = 28;

    /**
     * The strip under the home indicator on Face ID iPhones. EDGE doesn't
     * hand pushed screens the real inset (it's 0 inside a tab), so this errs
     * long on phones without one.
     */
    public const int HomeIndicatorHeight = 34;

    /**
     * How far below the top of a reply's row its elbow comes level with the
     * middle of the reply's avatar.
     */
    public const int ElbowLevel = 22;

    public static function relativeTime(?CarbonInterface $date, ?CarbonInterface $now = null): string
    {
        if ($date === null) {
            return '';
        }

        $now ??= CarbonImmutable::now();
        $seconds = (int) round($now->getTimestamp() - $date->getTimestamp());

        return match (true) {
            $seconds < 60 => 'now',
            $seconds < 3600 => intdiv($seconds, 60).'m',
            $seconds < 86400 => intdiv($seconds, 3600).'h',
            $seconds < 604800 => intdiv($seconds, 86400).'d',
            $date->year === $now->year => $date->format('M j'),
            default => $date->format('M j, Y'),
        };
    }

    /**
     * How long ago something happened, in words, as the web's profile settings
     * say when your profile was last synced: "just now", "5 minutes ago",
     * "1 hour ago", "yesterday", "3 days ago".
     */
    public static function ago(CarbonInterface $date, ?CarbonInterface $now = null): string
    {
        $now ??= CarbonImmutable::now();
        $seconds = (int) round($now->getTimestamp() - $date->getTimestamp());

        [$value, $unit] = match (true) {
            $seconds < 60 => [0, ''],
            $seconds < 3600 => [intdiv($seconds, 60), 'minute'],
            $seconds < 86400 => [intdiv($seconds, 3600), 'hour'],
            default => [intdiv($seconds, 86400), 'day'],
        };

        return match (true) {
            $unit === '' => 'just now',
            $unit === 'day' && $value === 1 => 'yesterday',
            default => self::count($value, $unit, $unit.'s').' ago',
        };
    }

    /**
     * The label of a day marker in a feed, worded like the web: "Today", "Yesterday",
     * then "Tue, Oct 13", with the year once it isn't this year. Days are counted in
     * the phone's timezone, as the web counts them in the browser's (see Timezone).
     */
    public static function dayLabel(CarbonInterface $date, ?CarbonInterface $now = null): string
    {
        $now = CarbonImmutable::instance($now ?? self::now());
        $day = CarbonImmutable::instance($date)->setTimezone($now->getTimezone());
        $daysAgo = (int) round(($now->startOfDay()->getTimestamp() - $day->startOfDay()->getTimestamp()) / 86400);

        return match (true) {
            $daysAgo === 0 => 'Today',
            $daysAgo === 1 => 'Yesterday',
            $day->year === $now->year => $day->format('D, M j'),
            default => $day->format('D, M j, Y'),
        };
    }

    /**
     * The calendar day a date falls on, in the same time zone as dayLabel().
     */
    public static function dayKey(CarbonInterface $date, ?CarbonInterface $now = null): string
    {
        $timezone = ($now ?? self::now())->getTimezone();

        return CarbonImmutable::instance($date)->setTimezone($timezone)->toDateString();
    }

    /**
     * The time of day under a group of messages, as the web prints it, in the same time zone as dayLabel().
     */
    public static function clock(CarbonInterface $date, ?CarbonInterface $now = null): string
    {
        return CarbonImmutable::instance($date)->setTimezone(($now ?? self::now())->getTimezone())->format('H:i');
    }

    /**
     * Now, in the phone's timezone, which days are counted in.
     */
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(app(Timezone::class)->here());
    }

    /**
     * When a commit was made, as the web prints it under the commit a page is
     * about: "3:04 PM · Oct 19, 2026", in your timezone (see Timezone).
     */
    public static function fullDateTime(CarbonInterface $date, ?string $timezone = null): string
    {
        return CarbonImmutable::instance($date)->setTimezone($timezone ?? app(Timezone::class)->yours())->format('g:i A · M j, Y');
    }

    /**
     * When a scheduled commit is due, as the web's Scheduled commits page
     * prints it: "Oct 16, 2026, 10:30 AM", in the phone's timezone.
     */
    public static function scheduleTime(CarbonInterface $date, ?string $timezone = null): string
    {
        return CarbonImmutable::instance($date)->setTimezone($timezone ?? app(Timezone::class)->here())->format('M j, Y, g:i A');
    }

    /**
     * The date the way `git log` prints it, offset included, since the device
     * time zone isn't visible to PHP.
     */
    public static function gitDate(?CarbonInterface $date): string
    {
        return $date?->format('D M j H:i:s Y O') ?? '';
    }

    /**
     * The web's compact en-US counts, starting at a thousand, with one decimal at most.
     */
    public static function compactCount(int $count): string
    {
        if ($count < 1000) {
            return (string) $count;
        }

        $units = ['k', 'm', 'b', 't'];
        $unit = 0;
        $value = $count / 1000;

        while (round($value, 1) >= 1000 && $unit < count($units) - 1) {
            $value /= 1000;
            $unit++;
        }

        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.').$units[$unit];
    }

    public static function shortHash(string $id): string
    {
        return substr(str_replace('-', '', $id), -7);
    }

    /**
     * An OpenSSH public key with everything after the first 10 characters of the
     * key itself masked. The type and comment stay readable.
     */
    public static function maskedKey(string $openSsh): string
    {
        $parts = explode(' ', trim($openSsh), 3);

        if (count($parts) < 2) {
            return $openSsh;
        }

        $parts[1] = substr($parts[1], 0, 10).str_repeat('•', 12);

        return implode(' ', $parts);
    }

    /**
     * How far a commit page has to scroll past its last reply to clear the
     * reply bar. A pushed screen is drawn under its bottom bar and the home
     * indicator, with no inset for either, so the page pads its own end: the
     * bar as the draft grows to its five lines, and the indicator below it.
     * With the keyboard up the bar sits on the keyboard instead, which only
     * leaves a little more room. A restored draft, or one with photos, adds
     * the row under the field ($draftRow).
     */
    public static function replyBarClearance(string $draft, bool $draftRow = false): int
    {
        return self::ReplyBarHeight
            + (min(self::ReplyBarMaxLines, self::lineCount($draft, self::ReplyBarCharactersPerLine)) - 1) * self::ReplyBarLineHeight
            + ($draftRow ? self::ReplyBarStashLabelHeight : 0)
            + (CommitBody::hasOversizedUrl($draft) ? 3 * self::ReplyBarLineHeight : 0)
            + self::HomeIndicatorHeight;
    }

    /**
     * About how many lines some text wraps to where $perLine characters fit on one, counting each of its own lines.
     */
    public static function lineCount(string $text, int $perLine): int
    {
        $lines = 0;

        foreach (explode("\n", $text) as $line) {
            $lines += max(1, (int) ceil(mb_strlen($line) / $perLine));
        }

        return $lines;
    }

    public static function count(int $count, string $singular, string $plural): string
    {
        return $count === 1 ? "1 {$singular}" : "{$count} {$plural}";
    }
}
