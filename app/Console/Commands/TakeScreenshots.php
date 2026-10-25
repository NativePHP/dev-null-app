<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\DrivesSimulators;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;

#[Signature('screenshots:take
    {stage : before or after}
    {--screen= : A short name for the screen, like log or settings, when a PR shows more than one}
    {--platform=* : ios or android (default: whichever is running)}
    {--branch= : The PR branch the screenshots are for (default: the current branch)}
    {--settle=2 : Seconds to wait after switching appearance before taking a screenshot}')]
#[Description('Screenshot the running app on the iOS simulator and Android device, in light and dark mode')]
class TakeScreenshots extends Command
{
    use DrivesSimulators;

    public function handle(): int
    {
        $stage = $this->stage();
        $branch = $this->branch();

        if ($stage === null || $branch === null) {
            return self::FAILURE;
        }

        $devices = $this->devices();

        if ($devices === []) {
            $this->error('There is no booted iOS simulator or connected Android device. Start the app with php artisan native:run first.');

            return self::FAILURE;
        }

        File::ensureDirectoryExists($this->screenshotsPath($branch));

        foreach ($devices as $platform => $device) {
            $original = $this->appearanceOf($platform, $device);

            try {
                foreach (['light', 'dark'] as $appearance) {
                    $this->setAppearance($platform, $device, $appearance);

                    Sleep::for((int) $this->option('settle'))->seconds();

                    $file = $this->captureName($platform, $appearance, $stage, 'png');

                    $this->screenshot($platform, $device, $this->screenshotsPath($branch, $file));

                    $this->line("Saved {$file}");
                }
            } finally {
                $this->setAppearance($platform, $device, $original);
            }
        }

        foreach (array_diff($this->platforms(), array_keys($devices)) as $missing) {
            $this->warn("Nothing was running on {$missing}, so there are no {$missing} screenshots.");
        }

        return self::SUCCESS;
    }

    protected function screenshot(string $platform, string $device, string $path): void
    {
        if ($platform === 'ios') {
            Process::run(['xcrun', 'simctl', 'io', $device, 'screenshot', $path])->throw();

            return;
        }

        $onDevice = '/sdcard/devnull-screenshot.png';

        Process::run($this->adbOn($device, 'shell', 'screencap', '-p', $onDevice))->throw();
        Process::run($this->adbOn($device, 'pull', $onDevice, $path))->throw();
        Process::run($this->adbOn($device, 'shell', 'rm', $onDevice));
    }
}
