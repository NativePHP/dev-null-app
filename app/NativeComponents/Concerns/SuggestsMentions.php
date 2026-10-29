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
 * only goes in while the draft still has the token where the caret found it,
 * and letters typed onto it since the caret was reported count as part of it.
 * As on the web, the caret then goes after "@handle " (`$draftCaret`, which
 * the field reads when it takes the new draft), wherever in the draft it was.
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
     * The "@handle" the caret is after: where its "@" is, in code points, what follows it, and the field's text when
     * the caret was there. Null while there's none.
     *
     * @var array{start: int, query: string, text: string}|null
     */
    public ?array $mention = null;

    /**
     * Where the caret goes once the field takes a picked mention, in code points. Null when there's nothing to place,
     * which leaves the caret where the field puts it.
     */
    public ?int $draftCaret = null;

    /**
     * The field's caret moved, or its text changed under it. Only a bare caret right after an "@handle" suggests, and
     * only in an org: a thread with one person has no one else to mention.
     */
    public function caretMoved(string $text, int $selectionStart, int $selectionEnd): void
    {
        // The field has the draft as it is now, so a caret placed for a pick has been placed.
        if ($text === $this->draft) {
            $this->draftCaret = null;
        }

        if (! $this->isOrg() || $selectionStart !== $selectionEnd || ! preg_match(self::MentionBeforeCaret, mb_substr($text, 0, $selectionStart), $match)) {
            $this->mention = null;

            return;
        }

        $this->mention = ['start' => $selectionStart - mb_strlen($match[1]) - 1, 'query' => $match[1], 'text' => $text];
    }

    /**
     * Puts "@username" in place of the "@handle" being typed, with a space after it unless one follows already, and
     * leaves the rest of the draft as it was. The caret goes after that space, as on the web.
     */
    public function pickMention(string $username): void
    {
        $mention = $this->mention;
        $this->mention = null;

        $end = $mention === null ? null : self::mentionEnd($mention, $this->draft);

        if ($mention === null || $end === null) {
            return;
        }

        $before = mb_substr($this->draft, 0, $mention['start']).'@'.$username;
        $after = mb_substr($this->draft, $end);
        $spaced = preg_match('/^\s/u', $after) ? '' : ' ';

        if (mb_strlen($before.$spaced.$after) > Message::MaxLength) {
            $spaced = '';
        }

        if (mb_strlen($before.$spaced.$after) > Message::MaxLength) {
            return;
        }

        $this->draft = $before.$spaced.$after;
        $this->draftCaret = mb_strlen($before) + (preg_match('/^\s/u', $spaced.$after) ? 1 : 0);
    }

    /**
     * Where the "@handle" the caret was after ends in the draft as it is now, in code points. The caret's reports
     * trail the typing a little, so letters added to the handle since count, while text after the caret stays
     * where it was. Null once the draft no longer has the handle where the caret found it.
     *
     * @param  array{start: int, query: string, text: string}  $mention
     */
    private static function mentionEnd(array $mention, string $draft): ?int
    {
        $caret = $mention['start'] + mb_strlen($mention['query']) + 1;
        $before = mb_substr($mention['text'], 0, $caret);
        $after = mb_substr($mention['text'], $caret);
        $added = mb_strlen($draft) - $caret - mb_strlen($after);

        if ($added >= 0 && str_starts_with($draft, $before) && str_ends_with($draft, $after)) {
            return preg_match('/^[A-Za-z0-9-]*$/', mb_substr($draft, $caret, $added)) ? $caret + $added : null;
        }

        return mb_substr($draft, $mention['start'], $caret - $mention['start']) === '@'.$mention['query'] ? $caret : null;
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
