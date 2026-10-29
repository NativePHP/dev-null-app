<?php

namespace App\Devnull;

use App\Devnull\Data\Pasteis;

/**
 * The `code` devnull sends next to an error's message, for the errors the app
 * has to tell apart. A code never changes once released, where the wording
 * can (it now says "/dev/null"), so the app decides on the code alone, and
 * words the ones it shows itself.
 */
enum ErrorCode: string
{
    case InvalidSignature = 'invalid_signature';
    case DeviceNotApproved = 'device_not_approved';
    case InvalidGrant = 'invalid_grant';
    case UserBlocked = 'user_blocked';
    case UserUnavailable = 'user_unavailable';
    case RateLimited = 'rate_limited';
    case InvalidAppleToken = 'invalid_apple_token';
    case AppleNotLinked = 'apple_not_linked';
    case AppleLinkedElsewhere = 'apple_linked_elsewhere';
    case OwnCommit = 'own_commit';
    case CommitUnavailable = 'commit_unavailable';
    case GitHubSyncFailed = 'github_sync_failed';
    case GitHubIssueFailed = 'github_issue_failed';
    case PasteisExhausted = 'pasteis_exhausted';

    /**
     * What the app says for it, or null where devnull's own message says it
     * better, as for a rate limit, whose message names the limit hit, or for
     * your own commit, whose message names what you can't do to it, or for an
     * issue GitHub didn't open, whose message says whether to check upstream.
     * A blocked or unavailable profile has a screen of its own (see Profile).
     */
    public function message(): ?string
    {
        return match ($this) {
            self::InvalidSignature => "fatal: devnull couldn't check this phone's signature. Sign in again.",
            self::DeviceNotApproved => "This phone isn't approved on devnull. Sign in to approve it.",
            self::InvalidGrant => 'fatal: that sign-in expired or was already used. Sign in again.',
            self::InvalidAppleToken => "fatal: devnull couldn't verify that Apple sign-in. Try again.",
            self::AppleNotLinked => 'No devnull account has this Apple ID linked. Sign in on dev-null.sh, then link Apple under Account.',
            self::AppleLinkedElsewhere => 'That Apple ID is linked to another devnull account. Unlink it there first.',
            self::UserBlocked => 'fatal: you blocked this profile.',
            self::UserUnavailable => "fatal: this profile isn't available.",
            self::CommitUnavailable => "fatal: this commit isn't available.",
            self::GitHubSyncFailed => 'Could not sync from GitHub. Try again in a minute.',
            self::PasteisExhausted => Pasteis::Exhausted,
            self::RateLimited, self::OwnCommit, self::GitHubIssueFailed => null,
        };
    }
}
