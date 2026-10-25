<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\DrivesSimulators;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Finder\SplFileInfo;

#[Signature('screenshots:publish
    {--remote=origin : The git remote to push to, which should be your fork if you have one}
    {--branch= : The PR branch the screenshots are for (default: the current branch)}')]
#[Description('Push the branch\'s screenshots to an orphan {branch}-screenshots branch and print the table for the PR description')]
class PublishScreenshots extends Command
{
    use DrivesSimulators;

    /**
     * The columns of the table, in order.
     */
    private const Columns = [
        'ios-light' => 'iOS light',
        'ios-dark' => 'iOS dark',
        'android-light' => 'Android light',
        'android-dark' => 'Android dark',
    ];

    public function handle(): int
    {
        $branch = $this->branch();

        if ($branch === null) {
            return self::FAILURE;
        }

        $files = is_dir($this->screenshotsPath($branch))
            ? collect(File::files($this->screenshotsPath($branch)))->sortBy(fn (SplFileInfo $file): string => $file->getFilename())->values()
            : collect();

        if ($files->isEmpty()) {
            $this->error('There are no screenshots for this branch yet. Take some with php artisan screenshots:take first.');

            return self::FAILURE;
        }

        $remote = (string) $this->option('remote');
        $repository = $this->githubRepository($remote);

        if ($repository === null) {
            $this->error("The {$remote} remote isn't a GitHub repository.");

            return self::FAILURE;
        }

        $mediaBranch = "{$branch}-screenshots";
        $commit = $this->commit($files, $remote, $mediaBranch, "Screenshots for {$branch}");

        $push = Process::run(['git', 'push', $remote, "{$commit}:refs/heads/{$mediaBranch}"]);

        if ($push->failed()) {
            $this->error("Couldn't push to {$remote}. If you work from a fork, pass --remote with your fork's remote.");
            $this->line(trim($push->errorOutput()));

            return self::FAILURE;
        }

        $this->info("Pushed to {$repository}@{$mediaBranch}. Paste this into the PR description:");
        $this->newLine();
        $this->line($this->markdown($files, $repository, $commit));

        return self::SUCCESS;
    }

    /**
     * Commits the files on top of the media branch without touching the working
     * tree, the index or HEAD: it builds the tree in a throwaway index instead.
     *
     * @param  Collection<int, SplFileInfo>  $files
     */
    protected function commit(Collection $files, string $remote, string $mediaBranch, string $message): string
    {
        $parent = Str::before(trim(Process::run(['git', 'ls-remote', '--heads', $remote, "refs/heads/{$mediaBranch}"])->throw()->output()), "\t");
        $index = sys_get_temp_dir().'/screenshots-index-'.Str::random(12);
        $git = Process::env(['GIT_INDEX_FILE' => $index]);

        try {
            if ($parent !== '') {
                Process::run(['git', 'fetch', '--no-tags', $remote, "refs/heads/{$mediaBranch}"])->throw();
                $git->run(['git', 'read-tree', $parent])->throw();
            }

            foreach ($files as $file) {
                $blob = trim(Process::run(['git', 'hash-object', '-w', $file->getPathname()])->throw()->output());

                $git->run(['git', 'update-index', '--add', '--cacheinfo', "100644,{$blob},{$file->getFilename()}"])->throw();
            }

            $tree = trim($git->run(['git', 'write-tree'])->throw()->output());
        } finally {
            File::delete($index);
        }

        if ($parent !== '' && trim(Process::run(['git', 'rev-parse', "{$parent}^{tree}"])->output()) === $tree) {
            return $parent;
        }

        $parents = $parent !== '' ? ['-p', $parent] : [];

        return trim(Process::run(['git', 'commit-tree', $tree, ...$parents, '-m', $message])->throw()->output());
    }

    /**
     * The owner/name of a GitHub remote, from either its SSH or HTTPS URL. The URL is
     * checked as git resolves it and as configured, since insteadOf rules can turn
     * either one into something that isn't a github.com address.
     */
    protected function githubRepository(string $remote): ?string
    {
        $urls = [
            $this->quietly(['git', 'remote', 'get-url', $remote]),
            $this->quietly(['git', 'config', '--get', "remote.{$remote}.url"]),
        ];

        foreach ($urls as $url) {
            if (preg_match('#github\.com[:/]([^/]+/[^/]+?)(?:\.git)?/?$#', trim((string) $url), $match)) {
                return $match[1];
            }
        }

        return null;
    }

    /**
     * A before/after table per screen, with the recordings linked underneath. GitHub
     * only plays videos uploaded through its own editor, so they are links.
     *
     * @param  Collection<int, SplFileInfo>  $files
     */
    protected function markdown(Collection $files, string $repository, string $commit): string
    {
        $pattern = '/^(?:(?<screen>.+)-)?(?<column>(?:ios|android)-(?:light|dark))-(?<stage>before|after)\.(?<extension>\w+)$/';

        [$captures, $others] = $files->partition(fn (SplFileInfo $file): bool => (bool) preg_match($pattern, $file->getFilename()));

        $sections = $captures
            ->map(function (SplFileInfo $file) use ($pattern, $repository, $commit): array {
                preg_match($pattern, $file->getFilename(), $match);

                return [...$match, 'url' => $this->url($repository, $commit, $file->getFilename())];
            })
            ->groupBy('screen')
            ->map(fn (Collection $screen, int|string $name): string => $this->section((string) $name, $screen));

        $links = $others->map(fn (SplFileInfo $file): string => "[{$file->getFilename()}]({$this->url($repository, $commit, $file->getFilename())})");

        return $sections
            ->when($links->isNotEmpty(), fn (Collection $sections): Collection => $sections->push('Other files: '.$links->implode(', ')))
            ->implode("\n\n");
    }

    /**
     * @param  Collection<int, array{column: string, stage: string, extension: string, url: string}>  $captures
     */
    protected function section(string $screen, Collection $captures): string
    {
        [$videos, $images] = $captures->partition(fn (array $capture): bool => $this->isVideo($capture['url']));

        $lines = $screen !== '' ? ["**{$screen}**", ''] : [];

        if ($images->isNotEmpty()) {
            $lines[] = '| | '.implode(' | ', self::Columns).' |';
            $lines[] = '|---'.str_repeat('|---', count(self::Columns)).'|';

            foreach (['before', 'after'] as $stage) {
                $row = $images->where('stage', $stage)->keyBy('column');

                if ($row->isNotEmpty()) {
                    $cells = collect(self::Columns)->keys()->map(fn (string $column): string => isset($row[$column]) ? "<img src=\"{$row[$column]['url']}\" width=\"200\">" : '');

                    $lines[] = '| '.ucfirst($stage).' | '.$cells->implode(' | ').' |';
                }
            }
        }

        if ($videos->isNotEmpty()) {
            $links = $videos->map(fn (array $video): string => '['.self::Columns[$video['column']].", {$video['stage']}]({$video['url']})");

            $lines[] = '';
            $lines[] = 'Recordings: '.$links->implode(', ');
        }

        return trim(implode("\n", $lines));
    }

    protected function url(string $repository, string $commit, string $file): string
    {
        return $this->isVideo($file)
            ? "https://github.com/{$repository}/raw/{$commit}/{$file}"
            : "https://raw.githubusercontent.com/{$repository}/{$commit}/{$file}";
    }

    protected function isVideo(string $file): bool
    {
        return in_array(Str::afterLast($file, '.'), ['mp4', 'mov'], true);
    }
}
