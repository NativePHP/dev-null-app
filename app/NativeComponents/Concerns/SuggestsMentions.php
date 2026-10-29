<?php

namespace App\NativeComponents\Concerns;

use App\Devnull\Client;
use App\Devnull\Data\User;

/**
 * The web's mention suggestions in a field (see resources/js/components/mention-textarea.tsx on the web): while the
 * caret sits right after an "@handle" being typed, the people to mention show by the field, and tapping one puts
 * "@handle " in its place.
 *
 * A screen names its fields that suggest (mentionFields()), each by its property, and says whom each suggests. An org
 * thread's message field filters the org's members here, as the web does, without asking devnull: a member matches
 * when their handle or name contains what's typed, ranked exact handle, then handle prefix, then name prefix, then the
 * rest, people you follow first within each, then by handle, six at most. A bare "@" lists them all that way. Never
 * you, and never anyone the thread says not to suggest (someone you've muted or blocked). The commit composers ask
 * devnull instead (Client::mentionSuggestions()), for a new commit, a reply to one or a quote of one, as the web's
 * composers do, and list its people and organizations in devnull's order.
 *
 * EDGE reports the caret with the field's text (@selectionChange), which is where the token comes from. The fields are
 * bound with a debounce, so a pick only goes in while the field still has the token where the caret found it, and
 * letters typed onto it since the caret was reported count as part of it. As on the web, the caret then goes after
 * "@handle " (`$mentionCarets`, which the field reads when it takes its new text), wherever in the text it was.
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
     * The "@handle" the caret is after: the field it's in, where its "@" is, in code points, what follows it, and the
     * field's text when the caret was there. Null while there's none.
     *
     * @var array{field: string, start: int, query: string, text: string}|null
     */
    public ?array $mention = null;

    /**
     * The people to mention for it, best first.
     *
     * @var list<User>
     */
    public array $mentionPeople = [];

    /**
     * The organizations to mention for it, after the people, as the web lists them in a commit.
     *
     * @var list<User>
     */
    public array $mentionOrganizations = [];

    /**
     * Where the caret goes in a field once it takes a picked mention, in code points, by field. A field that isn't
     * here keeps the caret where it puts it.
     *
     * @var array<string, int>
     */
    public array $mentionCarets = [];

    /**
     * The fields here that suggest mentions right now, by property, each with the longest text it takes and whom it
     * suggests: an org's members, filtered here, or devnull's people for a new commit, a reply to one (`reply`, its
     * id) or a quote of one (`quote`).
     *
     * @return array<string, array{limit: int, members?: list<array{user: User, suggests_mentions?: bool, is_followed?: bool}>, reply?: string, quote?: string}>
     */
    abstract protected function mentionFields(): array;

    /**
     * A field's caret moved, or its text changed under it. Only a bare caret right after an "@handle" suggests.
     */
    public function caretMoved(string $field, string $text, int $selectionStart, int $selectionEnd): void
    {
        $fields = $this->mentionFields();

        if (! isset($fields[$field])) {
            return;
        }

        // The field has its text as it is now, so a caret placed for a pick has been placed.
        if ($text === $this->{$field}) {
            unset($this->mentionCarets[$field]);
        }

        if ($selectionStart !== $selectionEnd || ! preg_match(self::MentionBeforeCaret, mb_substr($text, 0, $selectionStart), $match)) {
            $this->forgetMention($field, keepCaret: true);

            return;
        }

        $mention = ['field' => $field, 'start' => $selectionStart - mb_strlen($match[1]) - 1, 'query' => $match[1], 'text' => $text];
        $moved = $this->mention === null || array_slice($this->mention, 0, 3) !== array_slice($mention, 0, 3);
        $this->mention = $mention;

        if ($moved) {
            $this->suggestMentions($fields[$field], $match[1]);
        }
    }

    /**
     * Puts "@username" in place of the "@handle" being typed in a field, with a space after it unless one follows
     * already, and leaves the rest of the text as it was. The caret goes after that space, as on the web.
     */
    public function pickMention(string $field, string $username): void
    {
        $mention = $this->mention;
        $limit = $this->mentionFields()[$field]['limit'] ?? null;
        $this->forgetMention();

        $end = $mention === null || $mention['field'] !== $field || $limit === null ? null : self::mentionEnd($mention, $this->{$field});

        if ($end === null) {
            return;
        }

        $before = mb_substr($this->{$field}, 0, $mention['start']).'@'.$username;
        $after = mb_substr($this->{$field}, $end);
        $spaced = preg_match('/^\s/u', $after) ? '' : ' ';

        if (mb_strlen($before.$spaced.$after) > $limit) {
            $spaced = '';
        }

        if (mb_strlen($before.$spaced.$after) > $limit) {
            return;
        }

        $this->{$field} = $before.$spaced.$after;
        $this->mentionCarets[$field] = mb_strlen($before) + (preg_match('/^\s/u', $spaced.$after) ? 1 : 0);
    }

    /**
     * Closes the suggestions, or only those in `$field` when it's given, and forgets where a caret was to go there
     * unless `$keepCaret`.
     */
    protected function forgetMention(?string $field = null, bool $keepCaret = false): void
    {
        if ($field !== null && ! $keepCaret) {
            unset($this->mentionCarets[$field]);
        }

        if ($field !== null && $this->mention !== null && $this->mention['field'] !== $field) {
            return;
        }

        $this->mention = null;
        $this->mentionPeople = [];
        $this->mentionOrganizations = [];
    }

    /**
     * Finds whom to suggest for what's typed after the "@". devnull's answer replaces the last one, or clears it when
     * it can't be had: suggestions are a help, so a failure says nothing.
     *
     * @param  array{limit: int, members?: list<array{user: User, suggests_mentions?: bool, is_followed?: bool}>, reply?: string, quote?: string}  $field
     */
    private function suggestMentions(array $field, string $query): void
    {
        if (isset($field['members'])) {
            $this->mentionPeople = self::rankMembers($field['members'], $query, $this->me());
            $this->mentionOrganizations = [];

            return;
        }

        $error = $this->error;
        $found = $this->attempt(fn (Client $devnull): array => $devnull->mentionSuggestions($query, $field['reply'] ?? null, $field['quote'] ?? null));
        $this->error = $error;

        $this->mentionPeople = $found['people'] ?? [];
        $this->mentionOrganizations = $found['organizations'] ?? [];
    }

    /**
     * Where the "@handle" the caret was after ends in the field's text as it is now, in code points. The caret's
     * reports trail the typing a little, so letters added to the handle since count, while text after the caret
     * stays where it was. Null once the text no longer has the handle where the caret found it.
     *
     * @param  array{field: string, start: int, query: string, text: string}  $mention
     */
    private static function mentionEnd(array $mention, string $text): ?int
    {
        $caret = $mention['start'] + mb_strlen($mention['query']) + 1;
        $before = mb_substr($mention['text'], 0, $caret);
        $after = mb_substr($mention['text'], $caret);
        $added = mb_strlen($text) - $caret - mb_strlen($after);

        if ($added >= 0 && str_starts_with($text, $before) && str_ends_with($text, $after)) {
            return preg_match('/^[A-Za-z0-9-]*$/', mb_substr($text, $caret, $added)) ? $caret + $added : null;
        }

        return mb_substr($text, $mention['start'], $caret - $mention['start']) === '@'.$mention['query'] ? $caret : null;
    }

    /**
     * An org's members to suggest for what's typed after the "@", best first.
     *
     * @param  list<array{user: User, suggests_mentions?: bool, is_followed?: bool}>  $members
     * @return list<User>
     */
    private static function rankMembers(array $members, string $query, ?User $viewer): array
    {
        $query = mb_strtolower($query);
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
