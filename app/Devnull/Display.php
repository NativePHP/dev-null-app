<?php

namespace App\Devnull;

use App\Devnull\Data\Commit;
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
     * The row under the reply bar's field that marks a restored draft (see partials/stash-label).
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
     * middle of the reply's avatar, and the radius of the elbow's corner.
     */
    public const int ElbowLevel = 22;

    public const int ElbowRadius = 12;

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
     * the app's time zone, since PHP can't see the device's.
     */
    public static function dayLabel(CarbonInterface $date, ?CarbonInterface $now = null): string
    {
        $now = CarbonImmutable::instance($now ?? CarbonImmutable::now());
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
        $timezone = ($now ?? CarbonImmutable::now())->getTimezone();

        return CarbonImmutable::instance($date)->setTimezone($timezone)->toDateString();
    }

    /**
     * The time of day under a group of messages, as the web prints it, in the same time zone as dayLabel().
     */
    public static function clock(CarbonInterface $date, ?CarbonInterface $now = null): string
    {
        return CarbonImmutable::instance($date)->setTimezone(($now ?? CarbonImmutable::now())->getTimezone())->format('H:i');
    }

    /**
     * The date the way `git log` prints it, offset included, since the device
     * time zone isn't visible to PHP.
     */
    public static function gitDate(?CarbonInterface $date): string
    {
        return $date?->format('D M j H:i:s Y O') ?? '';
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
     * Enough 4pt dashes, with 3pt gaps, to run a dashed line down a commit's
     * row, which is clipped where the row ends. PHP can't know how tall the
     * row is drawn, so this errs long from the text, media and actions in it.
     */
    public static function dashesFor(Commit $commit): int
    {
        $text = $commit->isFolded() ? Commit::PreviewLength : mb_strlen($commit->body);
        $lines = (int) ceil($text / 32) + substr_count($commit->isFolded() ? '' : $commit->body, "\n");
        $height = 110 + $lines * 26
            + ($commit->images === [] ? 0 : 210)
            + ($commit->poll === null ? 0 : 50 + 46 * count($commit->poll->options))
            + ($commit->isQuote() ? 230 : 0)
            + ($commit->linkPreview === null ? 0 : 80);

        return min(250, (int) ceil($height / 7));
    }

    /**
     * The dashes of a thread's elbow, as the web's from #121: down the line at
     * $from, round a 12pt corner, and across to the reply's avatar at $to,
     * level with its middle. They keep the 4pt dash and 3pt gap all the way
     * round, with one on the middle of the bend so the corner reads the same
     * from either side, and the ones on the bend turned to follow it. The last
     * one may run past 1pt short of the avatar, where the elbow is cut off, as
     * a dashed border would be.
     *
     * @return list<array{x: float, y: float, rotate: float}> each dash's middle, and how far it's turned from upright, in degrees
     */
    public static function elbowDashes(float $from, float $to): array
    {
        $line = $from + 0.5;
        $down = self::ElbowLevel - self::ElbowRadius;
        $bend = M_PI * self::ElbowRadius / 2;
        $length = $down + $bend + ($to - 1) - ($line + self::ElbowRadius);

        $dashes = [];

        for ($middle = fmod($down + $bend / 2, 7); $middle - 2 < $length; $middle += 7) {
            if ($middle < 2) {
                continue;
            }

            $turn = min(M_PI / 2, max(0, ($middle - $down) / self::ElbowRadius));

            $dashes[] = match (true) {
                $middle <= $down => ['x' => $line, 'y' => round($middle, 2), 'rotate' => 0.0],
                $middle >= $down + $bend => ['x' => round($line + self::ElbowRadius + $middle - $down - $bend, 2), 'y' => (float) self::ElbowLevel, 'rotate' => -90.0],
                default => [
                    'x' => round($line + self::ElbowRadius - self::ElbowRadius * cos($turn), 2),
                    'y' => round($down + self::ElbowRadius * sin($turn), 2),
                    'rotate' => round(-rad2deg($turn), 2),
                ],
            };
        }

        return $dashes;
    }

    /**
     * How far a commit page has to scroll past its last reply to clear the
     * reply bar. A pushed screen is drawn under its bottom bar and the home
     * indicator, with no inset for either, so the page pads its own end: the
     * bar as the draft grows to its five lines, and the indicator below it.
     * With the keyboard up the bar sits on the keyboard instead, which only
     * leaves a little more room. A restored draft adds the row that marks it.
     */
    public static function replyBarClearance(string $draft, bool $restored = false): int
    {
        $lines = 0;

        foreach (explode("\n", $draft) as $line) {
            $lines += max(1, (int) ceil(mb_strlen($line) / self::ReplyBarCharactersPerLine));
        }

        return self::ReplyBarHeight
            + (min(self::ReplyBarMaxLines, $lines) - 1) * self::ReplyBarLineHeight
            + ($restored ? self::ReplyBarStashLabelHeight : 0)
            + self::HomeIndicatorHeight;
    }

    public static function count(int $count, string $singular, string $plural): string
    {
        return $count === 1 ? "1 {$singular}" : "{$count} {$plural}";
    }
}
