<?php

namespace App\Devnull;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use Native\Mobile\Facades\Device;

/**
 * Which timezone times are shown in, as the web picks them.
 *
 * The web shows the time of a commit in the timezone you chose in your
 * settings, or in your browser's while you haven't chosen one, and you pick
 * a time to schedule a commit in your browser's. Here the phone stands in for
 * the browser.
 *
 * The phone's timezone comes from NativePHP's device info. Builds from before
 * it carried the timezone don't send it, so then it's read the way the C
 * library reads it: from where /etc/localtime links to, which on iOS ends in
 * zoneinfo/ and the zone's name. When neither tells, as on Android with those
 * builds, the phone's timezone is unknown and the one you chose, or UTC,
 * stands in for it.
 *
 * It's bound once (see AppServiceProvider) and keeps the phone's timezone for
 * a minute, since a list asks for it once a row and NativePHP's device info
 * does more than read the zone.
 */
final class Timezone
{
    /**
     * How long the phone's timezone is kept before it's asked for again, so a
     * phone that lands somewhere new shows it within a minute.
     */
    public const int PhoneSeconds = 60;

    /**
     * @var array{name: ?string, at: int}|null
     */
    private ?array $phone = null;

    public function __construct(private readonly string $localtime = '/etc/localtime') {}

    /**
     * The phone's timezone, like `Europe/Lisbon`, or null when it can't be told.
     */
    public function phone(): ?string
    {
        $now = CarbonImmutable::now()->getTimestamp();

        if ($this->phone === null || $now - $this->phone['at'] >= self::PhoneSeconds) {
            $this->phone = ['name' => $this->fromDeviceInfo() ?? $this->fromLocaltime(), 'at' => $now];
        }

        return $this->phone['name'];
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

    /**
     * The timezone NativePHP's device info says the phone is in, or null off
     * a phone and on builds whose device info doesn't carry it.
     */
    private function fromDeviceInfo(): ?string
    {
        $info = json_decode((string) Device::getInfo(), true);
        $name = is_array($info) ? ($info['timezone'] ?? null) : null;

        return self::isKnown($name) ? $name : null;
    }

    /**
     * The timezone /etc/localtime links to, through any links on the way.
     */
    private function fromLocaltime(): ?string
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
}
