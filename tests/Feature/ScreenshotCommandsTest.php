<?php

use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

beforeEach(function () {
    Sleep::fake();

    $this->path = storage_path('app/screenshots/test-branch');

    File::deleteDirectory($this->path);
});

afterEach(function () {
    File::deleteDirectory($this->path);
});

/**
 * The command as one string, with adb's directory dropped so it matches wherever the SDK is.
 */
function commandLine(PendingProcess $process): string
{
    $command = (array) $process->command;
    $command[0] = basename($command[0]);

    return implode(' ', $command);
}

/**
 * Fakes a booted simulator in dark mode and an emulator in light mode. Patterns in
 * $answers win over the defaults. Returns every command run, in order.
 *
 * @param  array<string, string|int>  $answers
 * @return Collection<int, string>
 */
function fakeTools(array $answers = []): Collection
{
    $ran = collect();

    $answers += [
        'xcrun simctl list devices booted --json' => json_encode(['devices' => ['iOS-26-0' => [['udid' => 'SIM-1', 'state' => 'Booted']]]]),
        'xcrun simctl ui SIM-1 appearance' => "dark\n",
        'adb devices' => "List of devices attached\nemulator-5554\tdevice\n\n",
        'adb -s emulator-5554 shell cmd uimode night' => "Night mode: no\n",
    ];

    Process::fake(function (PendingProcess $process) use ($answers, $ran) {
        $ran->push($command = commandLine($process));

        foreach ($answers as $pattern => $answer) {
            if (Str::is($pattern, $command)) {
                return is_int($answer) ? Process::result(exitCode: $answer) : $answer;
            }
        }

        return '';
    });

    return $ran;
}

it('screenshots both platforms in light and dark mode, then puts each appearance back', function () {
    $ran = fakeTools();

    $this->artisan('screenshots:take before --branch=test-branch')
        ->expectsOutput('Saved ios-light-before.png')
        ->expectsOutput('Saved ios-dark-before.png')
        ->expectsOutput('Saved android-light-before.png')
        ->expectsOutput('Saved android-dark-before.png')
        ->assertSuccessful();

    expect($ran->all())->toBe([
        'xcrun simctl list devices booted --json',
        'adb devices',
        'xcrun simctl ui SIM-1 appearance',
        'xcrun simctl ui SIM-1 appearance light',
        "xcrun simctl io SIM-1 screenshot {$this->path}/ios-light-before.png",
        'xcrun simctl ui SIM-1 appearance dark',
        "xcrun simctl io SIM-1 screenshot {$this->path}/ios-dark-before.png",
        'xcrun simctl ui SIM-1 appearance dark',
        'adb -s emulator-5554 shell cmd uimode night',
        'adb -s emulator-5554 shell cmd uimode night no',
        'adb -s emulator-5554 shell screencap -p /sdcard/devnull-screenshot.png',
        "adb -s emulator-5554 pull /sdcard/devnull-screenshot.png {$this->path}/android-light-before.png",
        'adb -s emulator-5554 shell rm /sdcard/devnull-screenshot.png',
        'adb -s emulator-5554 shell cmd uimode night yes',
        'adb -s emulator-5554 shell screencap -p /sdcard/devnull-screenshot.png',
        "adb -s emulator-5554 pull /sdcard/devnull-screenshot.png {$this->path}/android-dark-before.png",
        'adb -s emulator-5554 shell rm /sdcard/devnull-screenshot.png',
        'adb -s emulator-5554 shell cmd uimode night no',
    ]);

    Sleep::assertSleptTimes(4);
});

it('names screenshots after the screen and only touches the platforms asked for', function () {
    $ran = fakeTools();

    $this->artisan('screenshots:take after --screen="Commit detail" --platform=ios --branch=test-branch')
        ->expectsOutput('Saved commit-detail-ios-light-after.png')
        ->expectsOutput('Saved commit-detail-ios-dark-after.png')
        ->doesntExpectOutputToContain('android')
        ->assertSuccessful();

    expect($ran->filter(fn (string $command): bool => str_starts_with($command, 'adb')))->toBeEmpty();
});

it('says which platform had nothing running', function () {
    fakeTools(['adb devices' => "List of devices attached\n\n"]);

    $this->artisan('screenshots:take before --branch=test-branch')
        ->expectsOutput('Nothing was running on android, so there are no android screenshots.')
        ->assertSuccessful();
});

it('prefers an emulator to a phone, and warns when it has to use the phone', function () {
    $ran = fakeTools(['adb devices' => "List of devices attached\nZT322TK27H\tdevice\nemulator-5556\tdevice\n"]);

    $this->artisan('screenshots:take before --platform=android --branch=test-branch')
        ->expectsOutput('More than one Android device is connected, so using emulator-5556.')
        ->assertSuccessful();

    expect($ran)->toContain('adb -s emulator-5556 shell cmd uimode night yes')
        ->not->toContain('adb -s ZT322TK27H shell cmd uimode night yes');

    fakeTools(['adb devices' => "List of devices attached\nZT322TK27H\tdevice\n"]);

    $this->artisan('screenshots:take before --platform=android --branch=test-branch')
        ->expectsOutput('Using the Android phone ZT322TK27H. Its dark mode will be switched while this runs.')
        ->assertSuccessful();
});

it('fails when nothing is running', function () {
    fakeTools([
        'xcrun simctl list devices booted --json' => '{"devices":{}}',
        'adb devices' => 1,
    ]);

    $this->artisan('screenshots:take before --branch=test-branch')
        ->expectsOutputToContain('There is no booted iOS simulator or connected Android device.')
        ->assertFailed();
});

it('only takes before or after shots', function () {
    fakeTools();

    $this->artisan('screenshots:take during --branch=test-branch')
        ->expectsOutput('The stage has to be "before" or "after".')
        ->assertFailed();
});

it('needs a pull request branch', function () {
    fakeTools(['git rev-parse --abbrev-ref HEAD' => "main\n"]);

    $this->artisan('screenshots:take before')
        ->expectsOutput('Switch to the branch for your pull request first, or pass --branch.')
        ->assertFailed();
});

it('keeps screenshots for a branch with a slash in its name', function () {
    $ran = fakeTools(['git rev-parse --abbrev-ref HEAD' => "feat/dark-log\n"]);

    $this->artisan('screenshots:take before --platform=ios')->assertSuccessful();

    expect($ran)->toContain('xcrun simctl io SIM-1 screenshot '.storage_path('app/screenshots/feat-dark-log/ios-light-before.png'));

    File::deleteDirectory(storage_path('app/screenshots/feat-dark-log'));
});

it('puts the appearance back when a screenshot fails', function () {
    $ran = fakeTools(['xcrun simctl io SIM-1 screenshot *' => 1]);

    expect(fn () => Artisan::call('screenshots:take before --platform=ios --branch=test-branch'))
        ->toThrow(ProcessFailedException::class);

    expect($ran->last())->toBe('xcrun simctl ui SIM-1 appearance dark');
});

it('records each platform for the given time in the appearances asked for', function () {
    $ran = fakeTools();

    $this->artisan('screenshots:record after --appearance=dark --seconds=5 --branch=test-branch')
        ->expectsOutput('Recording ios in dark mode for 5 seconds. Start the animation now.')
        ->expectsOutput('Saved ios-dark-after.mp4')
        ->expectsOutput('Recording android in dark mode for 5 seconds. Start the animation now.')
        ->expectsOutput('Saved android-dark-after.mp4')
        ->assertSuccessful();

    expect($ran)
        ->toContain("xcrun simctl io SIM-1 recordVideo --codec=h264 --force {$this->path}/ios-dark-after.mp4")
        ->toContain('adb -s emulator-5554 shell screenrecord --time-limit 5 --bit-rate 4000000 /sdcard/devnull-recording.mp4')
        ->toContain("adb -s emulator-5554 pull /sdcard/devnull-recording.mp4 {$this->path}/android-dark-after.mp4")
        ->not->toContain('xcrun simctl ui SIM-1 appearance light')
        ->and($ran->last())->toBe('adb -s emulator-5554 shell cmd uimode night no');
});

/**
 * Leaves an empty file for each name in the test branch's screenshots folder.
 */
function screenshots(string ...$names): void
{
    File::ensureDirectoryExists(storage_path('app/screenshots/test-branch'));

    foreach ($names as $name) {
        File::put(storage_path("app/screenshots/test-branch/{$name}"), $name);
    }
}

it('commits the screenshots to a new orphan branch and prints the table', function () {
    screenshots('ios-light-before.png', 'ios-light-after.png', 'android-dark-after.png', 'ios-dark-after.mp4', 'notes.txt');

    $ran = fakeTools([
        'git remote get-url origin' => "git@github.com:someone/dev-null-app.git\n",
        'git ls-remote *' => '',
        'git hash-object *' => "blob\n",
        'git write-tree' => "tree1\n",
        'git commit-tree *' => "abc123\n",
    ]);

    expect(Artisan::call('screenshots:publish --branch=test-branch'))->toBe(0);

    $raw = 'https://raw.githubusercontent.com/someone/dev-null-app/abc123';

    expect(Artisan::output())
        ->toContain('Pushed to someone/dev-null-app@test-branch-screenshots.')
        ->toContain(implode("\n", [
            '| | iOS light | iOS dark | Android light | Android dark |',
            '|---|---|---|---|---|',
            "| Before | <img src=\"{$raw}/ios-light-before.png\" width=\"200\"> |  |  |  |",
            "| After | <img src=\"{$raw}/ios-light-after.png\" width=\"200\"> |  |  | <img src=\"{$raw}/android-dark-after.png\" width=\"200\"> |",
            '',
            'Recordings: [iOS dark, after](https://github.com/someone/dev-null-app/raw/abc123/ios-dark-after.mp4)',
            '',
            "Other files: [notes.txt]({$raw}/notes.txt)",
        ]));

    expect($ran)
        ->toContain('git update-index --add --cacheinfo 100644,blob,ios-light-before.png')
        ->toContain('git commit-tree tree1 -m Screenshots for test-branch')
        ->toContain('git push origin abc123:refs/heads/test-branch-screenshots')
        ->not->toContain('git fetch --no-tags origin refs/heads/test-branch-screenshots');

    Process::assertRan(fn (PendingProcess $process): bool => commandLine($process) === 'git write-tree'
        && str_contains($process->environment['GIT_INDEX_FILE'], 'screenshots-index-'));
});

it('adds to the orphan branch when it already exists', function () {
    screenshots('log-ios-dark-after.png');

    $ran = fakeTools([
        'git remote get-url origin' => "https://github.com/NativePHP/dev-null-app.git\n",
        'git ls-remote *' => "parent1\trefs/heads/test-branch-screenshots\n",
        'git hash-object *' => "blob\n",
        'git write-tree' => "tree2\n",
        'git rev-parse parent1^{tree}' => "tree1\n",
        'git commit-tree *' => "def456\n",
    ]);

    expect(Artisan::call('screenshots:publish --branch=test-branch'))->toBe(0)
        ->and(Artisan::output())->toContain("**log**\n\n| | iOS light");

    expect($ran)
        ->toContain('git fetch --no-tags origin refs/heads/test-branch-screenshots')
        ->toContain('git read-tree parent1')
        ->toContain('git commit-tree tree2 -p parent1 -m Screenshots for test-branch')
        ->toContain('git push origin def456:refs/heads/test-branch-screenshots');
});

it('skips the commit when the screenshots are already there', function () {
    screenshots('ios-dark-after.png');

    $ran = fakeTools([
        'git remote get-url origin' => "git@github.com:someone/dev-null-app.git\n",
        'git ls-remote *' => "parent1\trefs/heads/test-branch-screenshots\n",
        'git write-tree' => "tree1\n",
        'git rev-parse parent1^{tree}' => "tree1\n",
    ]);

    Artisan::call('screenshots:publish --branch=test-branch');

    expect($ran->filter(fn (string $command): bool => str_starts_with($command, 'git commit-tree')))->toBeEmpty()
        ->and(Artisan::output())->toContain('someone/dev-null-app/parent1/ios-dark-after.png');
});

it('suggests a fork remote when the push is refused', function () {
    screenshots('ios-dark-after.png');

    fakeTools([
        'git remote get-url origin' => "git@github.com:NativePHP/dev-null-app.git\n",
        'git commit-tree *' => "abc123\n",
        'git push *' => 1,
    ]);

    $this->artisan('screenshots:publish --branch=test-branch')
        ->expectsOutputToContain("Couldn't push to origin. If you work from a fork, pass --remote with your fork's remote.")
        ->assertFailed();
});

it('needs screenshots and a GitHub remote to publish', function () {
    fakeTools(['git remote get-url origin' => "git@gitlab.com:someone/dev-null-app.git\n"]);

    $this->artisan('screenshots:publish --branch=test-branch')
        ->expectsOutputToContain('There are no screenshots for this branch yet.')
        ->assertFailed();

    screenshots('ios-dark-after.png');

    $this->artisan('screenshots:publish --branch=test-branch')
        ->expectsOutput("The origin remote isn't a GitHub repository.")
        ->assertFailed();
});
