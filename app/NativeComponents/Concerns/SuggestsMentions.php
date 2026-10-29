<?php

namespace App\NativeComponents\Concerns;

use App\Devnull\Data\Message;
use App\Devnull\Data\User;

/**
 * The web's mention suggestions in an org thread's message field (see
 * resources/js/components/mention-textarea.tsx on the web): while the caret
 * sits right after an "@handle" being typed, the org's members who match it
 * show above the field, and tapping one puts "@handle " in its place.
 *
 * As on the web, it never asks devnull: the thread already lists the org's
 * members, so they're filtered here. A member matches when their handle or
 * name contains what's typed, ranked exact handle, then handle prefix, then
 * name prefix, then the rest, people you follow first within each, then by
 * handle, six at most. A bare "@" lists them all that way, under "Members".
 * Never you, and never anyone the thread says not to suggest (someone you've
 * muted or blocked), when devnull says so.
 *
 * EDGE reports the caret with the field's text (@selectionChange), which is
 * where the token comes from. The draft is bound with a debounce, so a pick
 * only goes in while the draft still has the token where the caret found it.
 */
trait SuggestsMentions
{
    /**
     * The "@handle" being typed right before the caret, as the web finds it, so an "@" in an email address or a URL
     * doesn't count. Keep in step with the web's mentionBeforeCaretPattern.
     */
    public const string MentionBeforeCaret = '/(?:^|[^\p{L}\p{N}_.\/@-])@([A-Za-z0-9][A-Za-z0-9-]{0,38}|)$/u';

    public const int MaxMentionSuggestions = 6;

    /**
     * The "@handle" the caret is after: where its "@" is, in code points, and what follows it. Null while there's none.
     *
     * @var array{start: int, query: string}|null
     */
    public ?array $mention = null;

    /**
     * The field's caret moved, or its text changed under it. Only a bare caret right after an "@handle" suggests, and
     * only in an org: a thread with one person has no one else to mention.
     */
    public function caretMoved(string $text, int $selectionStart, int $selectionEnd): void
    {
        if (! $this->isOrg() || $selectionStart !== $selectionEnd || ! preg_match(self::MentionBeforeCaret, mb_substr($text, 0, $selectionStart), $match)) {
            $this->mention = null;

            return;
        }

        $this->mention = ['start' => $selectionStart - mb_strlen($match[1]) - 1, 'query' => $match[1]];
    }

    /**
     * Puts "@username" in place of the "@handle" being typed, with a space after it unless one follows already, and
     * leaves the rest of the draft as it was.
     */
    public function pickMention(string $username): void
    {
        $mention = $this->mention;
        $this->mention = null;

        if ($mention === null) {
            return;
        }

        $token = '@'.$mention['query'];
        $end = $mention['start'] + mb_strlen($token);

        if (mb_substr($this->draft, $mention['start'], mb_strlen($token)) !== $token) {
            return;
        }

        $before = mb_substr($this->draft, 0, $mention['start']);
        $after = mb_substr($this->draft, $end);
        $next = $before.'@'.$username.(preg_match('/^\s/u', $after) ? '' : ' ').$after;

        if (mb_strlen($next) > Message::MaxLength) {
            $next = $before.'@'.$username.$after;
        }

        if (mb_strlen($next) <= Message::MaxLength) {
            $this->draft = $next;
        }
    }

    /**
     * The members to suggest for the "@handle" being typed, best first.
     *
     * @param  list<array{user: User, suggests_mentions?: bool, is_followed?: bool}>  $members
     * @return list<User>
     */
    protected function mentionSuggestions(array $members, ?User $viewer): array
    {
        if ($this->mention === null) {
            return [];
        }

        $query = mb_strtolower($this->mention['query']);
        $ranked = [];

        foreach ($members as $member) {
            $user = $member['user'];
            $username = mb_strtolower($user->username);
            $name = mb_strtolower($user->name);

            if ($user->id === $viewer?->id || ($member['suggests_mentions'] ?? true) === false) {
                continue;
            }

            if (! str_contains($username, $query) && ! str_contains($name, $query)) {
                continue;
            }

            $ranked[] = [
                'user' => $user,
                'tier' => match (true) {
                    $username === $query => 0,
                    str_starts_with($username, $query) => 1,
                    str_starts_with($name, $query) => 2,
                    default => 3,
                },
                'followed' => ($member['is_followed'] ?? false) === true,
            ];
        }

        usort($ranked, fn (array $a, array $b): int => [$a['tier'], ! $a['followed'], $a['user']->username] <=> [$b['tier'], ! $b['followed'], $b['user']->username]);

        return array_column(array_slice($ranked, 0, self::MaxMentionSuggestions), 'user');
    }
}
