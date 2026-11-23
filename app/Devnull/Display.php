<?php

namespace App\Devnull;

use App\Devnull\Data\CommitBody;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Native\Mobile\Platform;

/**
 * How the machine prints things: relative times and short hashes, as the web does.
 */
final class Display
{
    /**
     * How much a line of draft adds to a composer bar's field.
     */
    public const int ReplyBarLineHeight = 21;

    public const int ReplyBarMaxLines = 5;

    /**
     * The photos on a reply's draft, above the field in the reply bar (see partials/composer-photos), and the
     * description of one under them while it's open.
     */
    public const int ReplyBarPhotosHeight = 88;

    public const int ReplyBarDescriptionHeight = 100;

    /**
     * The people to mention above a field (see partials/mention-suggestions): a row's height, and how many rows show
     * before the rest scroll inside the list.
     */
    public const int MentionRowHeight = 44;

    public const int MentionRowsShown = 6;

    /**
     * A bar holding partials/composer-box with one line of draft, as drawn on an iPhone: the bar's padding, then the
     * field over the row of tools and the send button.
     */
    public const int ComposerBoxBarHeight = 94;

    /**
     * The fade over the foot of a screen, just above its composer bar (see partials/composer-fade). On iOS the glass
     * under the bar fades in over the same height (see composerBarGlass()).
     */
    public const int ComposerFadeHeight = 28;

    /**
     * How much of the surface, in percent, tints the glass under a composer bar on iOS (see composerSurface()).
     */
    public const int ComposerGlassTint = 40;

    /**
     * How opaque, in percent, the glass under a composer bar gets on iOS once it has faded in (glass/N, see
     * composerBarGlass()). Full glass over a dark screen reads as a heavy slab.
     */
    public const int ComposerGlassOpacity = 80;

    /**
     * About how many characters fit on a line of the box's field, which runs the width of the box.
     */
    public const int ComposerBoxCharactersPerLine = 46;

    /**
     * The strip under the home indicator on Face ID iPhones. EDGE doesn't
     * hand pushed screens the real inset (it's 0 inside a tab), so this errs
     * long on phones without one.
     */
    public const int HomeIndicatorHeight = 34;

    /**
     * How long ago a commit was made, as the web prints it beside the commit:
     * "now", "5m", "3h", "2d", then the date, in the same time zone as dayLabel().
     */
    public static function relativeTime(?CarbonInterface $date, ?CarbonInterface $now = null): string
    {
        if ($date === null) {
            return '';
        }

        $now = CarbonImmutable::instance($now ?? self::now());
        $day = CarbonImmutable::instance($date)->setTimezone($now->getTimezone());
        $seconds = (int) round($now->getTimestamp() - $date->getTimestamp());

        return match (true) {
            $seconds < 60 => 'now',
            $seconds < 3600 => intdiv($seconds, 60).'m',
            $seconds < 86400 => intdiv($seconds, 3600).'h',
            $seconds < 604800 => intdiv($seconds, 86400).'d',
            $day->year === $now->year => $day->format('M j'),
            default => $day->format('M j, Y'),
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
     * The day something happened, with no time: "Nov 1, 2026", in your timezone.
     */
    public static function day(CarbonInterface $date, ?string $timezone = null): string
    {
        return CarbonImmutable::instance($date)->setTimezone($timezone ?? app(Timezone::class)->yours())->format('M j, Y');
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
     * fade over its foot (partials/composer-fade), the bar
     * (partials/composer-box) as the draft grows to its five lines, and the
     * indicator below it. With the keyboard up the bar sits on the keyboard
     * instead, which only leaves a little more room. Photos on the draft add
     * their row above the field ($hasPhotos), and an open description of one
     * its box under them ($describing). A link too long for devnull adds the
     * line that says so.
     */
    public static function replyBarClearance(string $draft, bool $hasPhotos = false, bool $describing = false): int
    {
        return self::ComposerFadeHeight
            + self::ComposerBoxBarHeight
            + self::composerBoxGrowth($draft)
            + ($hasPhotos ? self::ReplyBarPhotosHeight : 0)
            + ($hasPhotos && $describing ? self::ReplyBarDescriptionHeight : 0)
            + (CommitBody::hasOversizedUrl($draft) ? 3 * self::ReplyBarLineHeight : 0)
            + self::HomeIndicatorHeight;
    }

    /**
     * How much taller a composer-box bar is than with one line, as the draft grows to its five lines. A screen pads
     * its end by this on top of ComposerBoxBarHeight to keep its last row clear of the bar.
     */
    public static function composerBoxGrowth(string $draft): int
    {
        return (min(self::ReplyBarMaxLines, self::lineCount($draft, self::ComposerBoxCharactersPerLine)) - 1) * self::ReplyBarLineHeight;
    }

    /**
     * The fade's classes (see partials/composer-fade): a gradient down from see-through to the bar's surface, where
     * the bar's rows start. EDGE takes a gradient's stops as they are, without the dark companion a theme colour gets,
     * so the stops are the surface of the appearance the phone is in now, and a screen with the fade draws again when
     * that changes (see RedrawsOnAppearanceChange).
     *
     * On Android it climbs to 70% of the surface by halfway and ends solid, so what's under it has mostly faded before
     * it reaches the bar. On iOS it ends at the glass's tint (composerSurface()), since the bar is glass there and the
     * glass fades in under it over the same height.
     */
    public static function composerFade(): string
    {
        $surface = strtolower((string) theme('surface', '#ffffff'));

        if (self::fadesInBar()) {
            return "bg-gradient-to-b from-{$surface}/0 to-{$surface}/".self::ComposerGlassTint;
        }

        return "bg-gradient-to-b from-{$surface}/0 via-{$surface}/70 to-{$surface}";
    }

    /**
     * Whether the fade goes at the top of the composer bar itself, rather than over the foot of the screen above it.
     * On iOS the screen runs on under its bottom bar, so a fade lying over the screen's foot would be under the bar,
     * and the bar has to draw it. On Android the screen stops where the bar starts, so the fade lies over the screen.
     */
    public static function fadesInBar(): bool
    {
        return Platform::isIos();
    }

    /**
     * The classes for a composer's native:bottom-bar. On iOS that's Liquid Glass: what scrolls under the bar shows
     * through it, blurred. A patched mobile-air draws the glass behind the whole bar, under the home indicator too,
     * and fades it in over the bar's top ComposerFadeHeight points, so it has no hard top edge. EDGE's own glass on
     * an element can't be faded, and stops short of the home indicator. The glass stops at ComposerGlassOpacity
     * percent (glass/N), so a little of the screen under the bar shows through. Android has no glass, so the bar
     * stays the surface there.
     */
    public static function composerBarGlass(): string
    {
        return self::fadesInBar() ? 'glass/'.self::ComposerGlassOpacity : '';
    }

    /**
     * The composer bar's own classes. On Android it's the surface. On iOS it has no background at all: mobile-air
     * paints a bar's background colour behind the whole bar, which would cover the fade at its top, so the bar's
     * rows carry the tint instead (composerSurface()).
     */
    public static function composerBar(): string
    {
        return self::fadesInBar() ? '' : 'bg-theme-surface';
    }

    /**
     * The classes for the rows of a composer bar, under its fade. On Android it's the surface. On iOS it's the
     * surface at ComposerGlassTint percent, which tints the glass behind the bar to the screen's own colour, so in
     * dark mode the bar doesn't come out lighter than the screen. A fill reaches under the home indicator, so the
     * tint runs on there with the glass.
     */
    public static function composerSurface(): string
    {
        return self::fadesInBar() ? 'bg-theme-surface/'.self::ComposerGlassTint : 'bg-theme-surface';
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
