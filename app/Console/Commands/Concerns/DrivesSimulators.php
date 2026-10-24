<?php

namespace App\Console\Commands\Concerns;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

/**
 * Finds the running iOS simulator and Android device, switches them between light
 * and dark mode, and works out where the current branch's screenshots are kept.
 *
 * Screenshots go in storage/app/screenshots/{branch}, which git ignores and which
 * config/nativephp.php keeps out of the app bundle.
 */
trait DrivesSimulators
{
    /**
     * The booted iOS simulator's UDID and the connected Android device's serial,
     * limited to the platforms asked for with --platform.
     *
     * @return array{ios?: string, android?: string}
     */
    protected function devices(): array
    {
        $platforms = $this->platforms();

        return array_filter([
            'ios' => in_array('ios', $platforms, true) ? $this->bootedSimulator() : null,
            'android' => in_array('android', $platforms, true) ? $this->connectedAndroidDevice() : null,
        ]);
    }

    /**
     * @return list<string>
     */
    protected function platforms(): array
    {
        return $this->option('platform') ?: ['ios', 'android'];
    }

    protected function bootedSimulator(): ?string
    {
        $output = $this->quietly(['xcrun', 'simctl', 'list', 'devices', 'booted', '--json']);
        $udids = collect(json_decode($output ?? '', true)['devices'] ?? [])
            ->flatten(1)
            ->where('state', 'Booted')
            ->pluck('udid');

        if ($udids->count() > 1) {
            $this->warn("More than one iOS simulator is booted, so using {$udids->first()}.");
        }

        return $udids->first();
    }

    /**
     * An emulator if there is one. A phone works too, but its dark mode is switched
     * system-wide while this runs, so say when it's a phone.
     */
    protected function connectedAndroidDevice(): ?string
    {
        preg_match_all('/^(\S+)\tdevice$/m', $this->quietly([$this->adb(), 'devices']) ?? '', $matches);

        $serials = collect($matches[1])->sortBy(fn (string $serial): bool => ! str_starts_with($serial, 'emulator-'))->values();

        if ($serials->count() > 1) {
            $this->warn("More than one Android device is connected, so using {$serials->first()}.");
        }

        if ($serials->isNotEmpty() && ! str_starts_with($serials->first(), 'emulator-')) {
            $this->warn("Using the Android phone {$serials->first()}. Its dark mode will be switched while this runs.");
        }

        return $serials->first();
    }

    /**
     * The device's current appearance, in a form setAppearance() takes back.
     */
    protected function appearanceOf(string $platform, string $device): string
    {
        if ($platform === 'ios') {
            return trim((string) $this->quietly(['xcrun', 'simctl', 'ui', $device, 'appearance'])) ?: 'light';
        }

        preg_match('/Night mode: (\w+)/', (string) $this->quietly($this->adbOn($device, 'shell', 'cmd', 'uimode', 'night')), $match);

        return $match[1] ?? 'no';
    }

    /**
     * @param  string  $appearance  light, dark, or a value from appearanceOf()
     */
    protected function setAppearance(string $platform, string $device, string $appearance): void
    {
        if ($platform === 'ios') {
            Process::run(['xcrun', 'simctl', 'ui', $device, 'appearance', $appearance])->throw();

            return;
        }

        $night = ['light' => 'no', 'dark' => 'yes'][$appearance] ?? $appearance;

        Process::run($this->adbOn($device, 'shell', 'cmd', 'uimode', 'night', $night))->throw();
    }

    protected function stage(): ?string
    {
        $stage = $this->argument('stage');

        if (! in_array($stage, ['before', 'after'], true)) {
            $this->error('The stage has to be "before" or "after".');

            return null;
        }

        return $stage;
    }

    /**
     * The file name for one capture, like log-ios-dark-after.png.
     */
    protected function captureName(string $platform, string $appearance, string $stage, string $extension): string
    {
        $screen = Str::slug((string) $this->option('screen'));

        return ltrim("{$screen}-{$platform}-{$appearance}-{$stage}.{$extension}", '-');
    }

    protected function branch(): ?string
    {
        $branch = $this->option('branch') ?: trim((string) $this->quietly(['git', 'rev-parse', '--abbrev-ref', 'HEAD']));

        if (in_array($branch, ['', 'HEAD', 'main'], true)) {
            $this->error('Switch to the branch for your pull request first, or pass --branch.');

            return null;
        }

        return Str::slug(str_replace('/', '-', $branch));
    }

    protected function screenshotsPath(string $branch, string $file = ''): string
    {
        return storage_path(rtrim("app/screenshots/{$branch}/{$file}", '/'));
    }

    /**
     * @return list<string>
     */
    protected function adbOn(string $device, string ...$arguments): array
    {
        return [$this->adb(), '-s', $device, ...$arguments];
    }

    /**
     * platform-tools often isn't on the PATH, so look in the SDK first.
     */
    protected function adb(): string
    {
        $sdks = array_filter([getenv('ANDROID_HOME'), getenv('ANDROID_SDK_ROOT'), getenv('HOME').'/Library/Android/sdk']);

        foreach ($sdks as $sdk) {
            if (is_executable("{$sdk}/platform-tools/adb")) {
                return "{$sdk}/platform-tools/adb";
            }
        }

        return 'adb';
    }

    /**
     * Runs a command whose failure only means a tool or device isn't there.
     *
     * @param  list<string>  $command
     */
    protected function quietly(array $command): ?string
    {
        try {
            $result = Process::run($command);
        } catch (Throwable) {
            return null;
        }

        return $result->successful() ? $result->output() : null;
    }
}
