<?php

namespace App\Devnull;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;

/**
 * Which timezone times are shown in, as the web picks them.
 *
 * The web shows the time of a commit in the timezone you chose in your
 * settings, or in your browser's while you haven't chosen one, and you pick
 * a time to schedule a commit in your browser's. Here the phone stands in for
 * the browser.
 *
 * NativePHP doesn't tell PHP the phone's timezone, so it's read the way the C
 * library reads it: from where /etc/localtime links to, which on iOS ends in
 * zoneinfo/ and the zone's name. When that can't be read, as on Android, the
 * phone's timezone is unknown and the one you chose, or UTC, stands in for it.
 */
final readonly class Timezone
{
    public function __construct(private string $localtime = '/etc/localtime') {}

    /**
     * The phone's timezone, like `Europe/Lisbon`, or null when it can't be told.
     */
    public function phone(): ?string
    {
        $path = $this->localtime;

        for ($hops = 0; $hops < 4 && is_link($path); $hops++) {
            $target = (string) readlink($path);

            if (preg_match('#/zoneinfo/(.+)$#', $target, $matches) === 1) {
                return self::isKnown($matches[1]) ? $matches[1] : null;
            }

            $path = str_starts_with($target, '/') ? $target : dirname($path).'/'.$target;
        }

        return null;
    }

    /**
     * The timezone you chose on devnull, or null while you haven't.
     */
    public function chosen(): ?string
    {
        return app(Account::class)->timezone();
    }

    /**
     * The timezone your times show in: the one you chose, or else the
     * phone's, as the web falls back to the browser's.
     */
    public function yours(): string
    {
        return $this->chosen() ?? $this->phone() ?? 'UTC';
    }

    /**
     * Where the phone is, which a time to schedule is picked in, as the web
     * picks it in the browser's timezone.
     */
    public function here(): string
    {
        return $this->phone() ?? $this->chosen() ?? 'UTC';
    }

    /**
     * Whether PHP knows the timezone by that name, as devnull checks it.
     */
    public static function isKnown(mixed $name): bool
    {
        return is_string($name) && in_array($name, DateTimeZone::listIdentifiers(), true);
    }

    /**
     * Every timezone, as devnull takes them.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_values(DateTimeZone::listIdentifiers());
    }

    /**
     * How far a timezone is from UTC at a moment, as the web's picker prints
     * it: `GMT+01:00`, `GMT-03:00`, `GMT+05:45`.
     */
    public static function offset(string $name, ?CarbonInterface $at = null): string
    {
        return 'GMT'.CarbonImmutable::instance($at ?? CarbonImmutable::now())->setTimezone($name)->format('P');
    }

    /**
     * The city a timezone is named for, as the web's tooltip prints it:
     * `Sao Paulo` for `America/Sao_Paulo`.
     */
    public static function city(string $name): string
    {
        $city = str_contains($name, '/') ? substr($name, (int) strrpos($name, '/') + 1) : $name;

        return str_replace('_', ' ', $city);
    }
}
