<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\DrivesSimulators;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;

#[Signature('screenshots:record
    {stage : before or after}
    {--screen= : A short name for the screen, like log or settings, when a PR shows more than one}
    {--platform=* : ios or android (default: whichever is running)}
    {--appearance=* : light or dark (default: both)}
    {--seconds=8 : How long each recording lasts}
    {--branch= : The PR branch the recordings are for (default: the current branch)}
    {--settle=2 : Seconds to wait after switching appearance before recording}')]
#[Description('Record the running app on the iOS simulator and Android device, one platform and appearance at a time')]
class RecordScreens extends Command
{
    use DrivesSimulators;

    /**
     * SIGINT, which makes simctl finish writing the video. The constant itself needs pcntl.
     */
    private const Interrupt = 2;

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

        $seconds = max(1, min(180, (int) $this->option('seconds')));

        foreach ($devices as $platform => $device) {
            $original = $this->appearanceOf($platform, $device);

            try {
                foreach ($this->option('appearance') ?: ['light', 'dark'] as $appearance) {
                    $this->setAppearance($platform, $device, $appearance);

                    Sleep::for((int) $this->option('settle'))->seconds();

                    $file = $this->captureName($platform, $appearance, $stage, 'mp4');

                    $this->info("Recording {$platform} in {$appearance} mode for {$seconds} seconds. Start the animation now.");

                    $this->record($platform, $device, $seconds, $this->screenshotsPath($branch, $file));

                    $this->line("Saved {$file}");
                }
            } finally {
                $this->setAppearance($platform, $device, $original);
            }
        }

        return self::SUCCESS;
    }

    protected function record(string $platform, string $device, int $seconds, string $path): void
    {
        if ($platform === 'ios') {
            $recording = Process::timeout($seconds + 30)
                ->start(['xcrun', 'simctl', 'io', $device, 'recordVideo', '--codec=h264', '--force', $path]);

            Sleep::for($seconds)->seconds();

            $recording->signal(self::Interrupt);
            $recording->wait()->throw();

            return;
        }

        $onDevice = '/sdcard/devnull-recording.mp4';

        Process::timeout($seconds + 30)
            ->run($this->adbOn($device, 'shell', 'screenrecord', '--time-limit', (string) $seconds, '--bit-rate', '4000000', $onDevice))
            ->throw();
        Process::run($this->adbOn($device, 'pull', $onDevice, $path))->throw();
        Process::run($this->adbOn($device, 'shell', 'rm', $onDevice));
    }
}
