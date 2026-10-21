<?php

namespace App\Devnull;

use App\Devnull\Data\Commit;
use App\Devnull\Data\CommitBody;
use App\Icons\Lucide;

/**
 * The web's `/feature` and `/bug` commands, which turn a new commit's draft
 * into a public issue on nunomaduro/dev-null.sh, opened by devnull's GitHub
 * App and crediting you. The wording, and when each is suggested, are the
 * web's (resources/js/lib/issue-commands.ts).
 */
enum IssueCommand: string
{
    case Feature = 'feature';
    case Bug = 'bug';

    /**
     * Where the issue goes, as the web says it under the command.
     */
    public const string Repository = 'nunomaduro/dev-null.sh';

    /**
     * Issues still need three literal characters and do not discount URLs.
     */
    public static function fits(string $body): bool
    {
        $length = mb_strlen(CommitBody::normalize($body));

        return $length >= 3 && $length <= Commit::MaxLength;
    }

    /**
     * What you type, and what devnull takes.
     */
    public function command(): string
    {
        return '/'.$this->value;
    }

    public function description(): string
    {
        return match ($this) {
            self::Feature => 'Request a feature',
            self::Bug => 'Report a bug',
        };
    }

    /**
     * The web's Markdown template. devnull takes a first `# heading` as the issue's title.
     */
    public function placeholder(): string
    {
        return match ($this) {
            self::Feature => "# Feature title\n\n## Problem\nWhat is missing?\n\n## Proposal\nHow should it work?",
            self::Bug => "# Bug title\n\n## Steps\n1. How to reproduce it\n\n## Expected vs. actual\nWhat should happen, and what happens instead?",
        };
    }

    /**
     * The question under a plain draft that mentions a feature or a bug.
     */
    public function hint(): string
    {
        return match ($this) {
            self::Feature => 'Have an idea for /dev/null?',
            self::Bug => 'Found a bug in /dev/null?',
        };
    }

    public function icon(): Lucide
    {
        return match ($this) {
            self::Feature => Lucide::Lightbulb,
            self::Bug => Lucide::Bug,
        };
    }

    /**
     * The commands a draft could still become while it's only a slash and the start of a name.
     *
     * @return list<self>
     */
    public static function suggested(string $body): array
    {
        if (preg_match('/^\/[a-z]*$/', $body) !== 1) {
            return [];
        }

        return array_values(array_filter(self::cases(), fn (self $command): bool => str_starts_with($command->command(), $body)));
    }

    /**
     * A complete command followed by a space or a new line turns the draft into an issue, without the command.
     *
     * @return array{0: self, 1: string}|null
     */
    public static function typed(string $body): ?array
    {
        if (preg_match('/^\/(feature|bug)\s/u', $body, $match) !== 1) {
            return null;
        }

        return [self::from($match[1]), substr($body, strlen($match[0]))];
    }

    /**
     * The command a plain draft seems to be about, when it mentions a bug or a feature, so it can be suggested.
     */
    public static function hinted(string $body): ?self
    {
        if (str_starts_with($body, '/') || preg_match('/\b(bugs?|features?)\b/i', $body, $match) !== 1) {
            return null;
        }

        return str_starts_with(strtolower($match[1]), 'bug') ? self::Bug : self::Feature;
    }
}
