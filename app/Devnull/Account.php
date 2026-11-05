<?php

namespace App\Devnull;

use App\Devnull\Data\Pasteis;
use App\Devnull\Data\Profile;
use App\Devnull\Data\Remotes;
use App\Devnull\Data\User;
use Illuminate\Support\Facades\Cache;

/**
 * The devnull user this device has been verified as.
 *
 * Nothing here is secret: the key that proves it lives in the Keychain.
 */
final class Account
{
    private const string CacheKey = 'devnull.account';

    private const string UnreadKey = 'devnull.unread-notifications';

    private const string PasteisKey = 'devnull.pasteis';

    private const string RemotesKey = 'devnull.remotes';

    private const string ProfileKey = 'devnull.profile';

    private const string PinWarningKey = 'devnull.pin-warning-dismissed';

    /**
     * What's kept while devnull has said you have Remotes off, as against not having said yet.
     */
    private const string RemotesOff = 'off';

    private const string TimezoneKey = 'devnull.timezone';

    private const string TermsKey = 'devnull.terms-to-accept';

    private const string TranslationKey = 'devnull.translation-preferences';

    public function user(): ?User
    {
        $attributes = Cache::get(self::CacheKey);

        return is_array($attributes) ? User::fromArray($attributes) : null;
    }

    public function remember(User $user): void
    {
        if ($this->user()?->id !== $user->id) {
            app(ResponseCache::class)->forgetGeneratedTranslations();
        }

        Cache::forever(self::CacheKey, $user->toArray());
    }

    /**
     * Your own profile as devnull last sent it (GET /users/{you}), kept for as
     * long as you're signed in rather than as long as the response cache keeps
     * things, so your profile still shows when devnull can't be reached. Your
     * commits and replies aren't kept here.
     *
     * @return array<string, mixed>|null
     */
    public function profile(): ?array
    {
        $json = Cache::get(self::ProfileKey);

        return is_array($json) ? $json : null;
    }

    /**
     * Keeps your profile. An answer with nobody in it, like a maintenance page
     * read as JSON, leaves the one kept alone.
     *
     * @param  array<string, mixed>  $json
     */
    public function rememberProfile(array $json): void
    {
        if (Profile::fromArray($json)->user->username === '') {
            return;
        }

        Cache::forever(self::ProfileKey, $json);
    }

    /**
     * How many notifications were unread when devnull last said, for the tab badge.
     */
    public function unreadNotifications(): int
    {
        $count = Cache::get(self::UnreadKey);

        return is_int($count) ? $count : 0;
    }

    public function rememberUnreadNotifications(int $count): void
    {
        Cache::forever(self::UnreadKey, max(0, $count));
    }

    /**
     * Your pastéis for today while pastéis mode is on, as devnull last said or
     * as this phone has counted them since, or null while the mode is off.
     */
    public function pasteis(): ?Pasteis
    {
        $kept = Cache::get(self::PasteisKey);

        return is_array($kept) ? Pasteis::fromKept($kept) : null;
    }

    /**
     * Remembers your pastéis, or with null that pastéis mode is off.
     */
    public function rememberPasteis(?Pasteis $pasteis): void
    {
        if ($pasteis === null) {
            Cache::forget(self::PasteisKey);

            return;
        }

        Cache::forever(self::PasteisKey, $pasteis->toArray());
    }

    /**
     * Your Remotes as devnull last said, or null while you have them off. Until devnull has said, they're on, as
     * they are for everyone to start with.
     */
    public function remotes(): ?Remotes
    {
        $kept = Cache::get(self::RemotesKey);

        return match (true) {
            $kept === self::RemotesOff => null,
            is_array($kept) => Remotes::fromArray($kept),
            default => new Remotes,
        };
    }

    /**
     * Remembers your Remotes, or with null that you have them off.
     */
    public function rememberRemotes(?Remotes $remotes): void
    {
        Cache::forever(self::RemotesKey, $remotes?->toArray() ?? self::RemotesOff);
    }

    /**
     * How many messages and requests were waiting when devnull last said, for the Remotes tab's badge.
     */
    public function unreadRemotes(): int
    {
        return $this->remotes()->unreadCount ?? 0;
    }

    public function rememberUnreadRemotes(int $count): void
    {
        $remotes = $this->remotes();

        if ($remotes !== null) {
            $this->rememberRemotes($remotes->withUnreadCount($count));
        }
    }

    /**
     * Whether you said not to be asked again before pinning a message for a whole org, as the web remembers it.
     */
    public function skipsPinWarning(): bool
    {
        return Cache::get(self::PinWarningKey) === true;
    }

    public function rememberSkipsPinWarning(): void
    {
        Cache::forever(self::PinWarningKey, true);
    }

    /**
     * The timezone you chose on devnull, as it last said, or null while you haven't chosen one.
     */
    public function timezone(): ?string
    {
        $timezone = Cache::get(self::TimezoneKey);

        return Timezone::isKnown($timezone) ? $timezone : null;
    }

    public function rememberTimezone(?string $timezone): void
    {
        if ($timezone === null) {
            Cache::forget(self::TimezoneKey);

            return;
        }

        Cache::forever(self::TimezoneKey, $timezone);
    }

    /** The account language preference, or null to follow this phone. */
    public function translationLanguage(): ?string
    {
        $language = Cache::get(self::TranslationKey, [])['translation_language'] ?? null;

        return is_string($language) && $language !== '' ? $language : null;
    }

    /** Cached translations display automatically unless the account explicitly opts out. */
    public function automaticTranslation(): bool
    {
        return (Cache::get(self::TranslationKey, [])['automatic_translation'] ?? true) !== false;
    }

    /**
     * Supported choices last supplied by settings; empty on older backends without translation settings.
     *
     * @return list<array{code: string, name: string, native_name: string}>
     */
    public function translationLanguages(): array
    {
        return Cache::get(self::TranslationKey, [])['translation_languages'] ?? [];
    }

    /**
     * Partial settings answers must not reset preferences they don't carry.
     *
     * @param  array<string, mixed>  $settings
     */
    public function rememberTranslationSettings(array $settings): void
    {
        $kept = Cache::get(self::TranslationKey, []);

        if (array_key_exists('translation_language', $settings)) {
            $language = $settings['translation_language'];

            if ($language !== ($kept['translation_language'] ?? null)) {
                app(ResponseCache::class)->forgetGeneratedTranslations();
            }

            $kept['translation_language'] = is_string($language) && $language !== '' ? $language : null;
        }

        if (is_bool($settings['automatic_translation'] ?? null)) {
            $kept['automatic_translation'] = $settings['automatic_translation'];
        }

        if (is_array($settings['translation_languages'] ?? null)) {
            $kept['translation_languages'] = array_values(array_filter($settings['translation_languages'], fn (mixed $language): bool => is_array($language)
                && is_string($language['code'] ?? null) && $language['code'] !== ''
                && is_string($language['name'] ?? null) && is_string($language['native_name'] ?? null)));
        }

        Cache::forever(self::TranslationKey, $kept);
    }

    /**
     * The version of the terms devnull last said you have yet to accept, so the app opens on them (see AcceptTerms),
     * or null when you're up to date.
     */
    public function termsToAccept(): ?string
    {
        $version = Cache::get(self::TermsKey);

        return is_string($version) && $version !== '' ? $version : null;
    }

    public function rememberTermsToAccept(?string $version): void
    {
        if ($version === null) {
            Cache::forget(self::TermsKey);

            return;
        }

        Cache::forever(self::TermsKey, $version);
    }

    /**
     * Signs this phone out: who it was, the badges, its pastéis and Remotes, your timezone, the terms you owe, every
     * answer, image and voice note kept for a quick start, and your unsent drafts and recordings.
     */
    public function forget(): void
    {
        app(ResponseCache::class)->flush();
        ImageCache::clear();
        VoiceNotes::clear();
        app(Stash::class)->forget($this->user()?->id);
        app(Outbox::class)->forgetAll($this->user()?->id);

        Cache::forget(self::CacheKey);
        Cache::forget(self::UnreadKey);
        Cache::forget(self::PasteisKey);
        Cache::forget(self::RemotesKey);
        Cache::forget(self::ProfileKey);
        Cache::forget(self::PinWarningKey);
        Cache::forget(self::TimezoneKey);
        Cache::forget(self::TermsKey);
        Cache::forget(self::TranslationKey);
    }
}
