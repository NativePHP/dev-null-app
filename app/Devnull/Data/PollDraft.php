<?php

namespace App\Devnull\Data;

/**
 * The poll in a new commit or a quote you're writing, as the web's PollEditor
 * keeps it: two to four options of up to 50 characters, and how long it runs.
 */
final class PollDraft
{
    public const int MinOptions = 2;

    public const int MaxOptions = 4;

    public const int MaxOptionLength = 50;

    /**
     * The web's poll lengths, in the minutes devnull takes.
     *
     * @var array<string, int>
     */
    public const array Lengths = ['1 hour' => 60, '1 day' => 1440, '3 days' => 4320, '7 days' => 10080];

    public const string DefaultLength = '1 day';

    /**
     * A new poll's options: two empty ones, as the web starts it.
     *
     * @return list<string>
     */
    public static function start(): array
    {
        return array_fill(0, self::MinOptions, '');
    }

    /**
     * Whether every option has something in it, which the web waits for before it commits.
     *
     * @param  list<string>  $options
     */
    public static function isComplete(array $options): bool
    {
        return count($options) >= self::MinOptions && array_all($options, fn (string $option): bool => trim($option) !== '');
    }

    /**
     * The poll as devnull takes it with a commit.
     *
     * @param  list<string>  $options
     * @return array{options: list<string>, duration: int}
     */
    public static function payload(array $options, string $length): array
    {
        return [
            'options' => array_map(trim(...), $options),
            'duration' => self::Lengths[$length] ?? self::Lengths[self::DefaultLength],
        ];
    }

    /**
     * The poll as it reads once committed, for the preview: its options, with
     * no votes. Its clock starts once the commit is published.
     *
     * @param  list<string>  $options
     */
    public static function preview(array $options): Poll
    {
        $labels = array_values(array_filter(array_map(trim(...), $options), fn (string $option): bool => $option !== ''));

        return new Poll('draft', array_map(fn (string $label, int $index): PollOption => new PollOption('draft-'.$index, $label), $labels, array_keys($labels)));
    }

    /**
     * The line under the preview's options: "Open for 1 day once published".
     */
    public static function runs(string $length): string
    {
        return 'Open for '.(array_key_exists($length, self::Lengths) ? $length : self::DefaultLength).' once published';
    }
}
