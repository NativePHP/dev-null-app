<?php

namespace App\NativeComponents\Concerns;

use App\Devnull\Account;
use App\Devnull\Client;
use App\Devnull\Data\Commit;
use App\Devnull\Data\CommitTranslation;
use App\Devnull\ResponseCache;
use App\Devnull\SignedRequest;
use App\Devnull\Toasts;
use App\Devnull\TranslationLanguage;

trait TranslatesCommits
{
    /** @var array<string, string> */
    public array $translationDisplay = [];

    /** @var array<string, string> */
    public array $translating = [];

    /** @var array<string, string> */
    protected array $readingTranslations = [];

    /** @var array<string, float> */
    protected array $translationsReadAt = [];

    /** Keeps the original selected even when a refresh discovers a cached translation. */
    public function showOriginal(string $id): void
    {
        $this->translationDisplay[$id] = 'original';
    }

    /** Mirrors the card's eligibility checks for calls dispatched from native callbacks. */
    public function canTranslate(Commit $commit): bool
    {
        return $commit->canTranslateBy(app(Account::class)->user());
    }

    /** Whether this card currently displays the cached translation rather than its original. */
    public function isTranslated(Commit $commit): bool
    {
        return $this->canTranslate($commit) && $commit->translation->isDisplayed(
            app(TranslationLanguage::class)->yours(), app(Account::class)->automaticTranslation(), $this->translationDisplay[$commit->id] ?? null,
        );
    }

    /** A display copy only: the original body, excerpts and action payloads never change. */
    public function displayedCommit(Commit $commit): Commit
    {
        return $this->isTranslated($commit) ? $commit->displayingTranslation() : $commit;
    }

    /** Generates only after an explicit tap, or reveals content already cached in this language. */
    public function translateCommit(string $id): void
    {
        $commit = $this->shownCommit($id);

        if ($commit === null || ! $this->canTranslate($commit) || isset($this->translating[$id])) {
            return;
        }

        $language = app(TranslationLanguage::class)->yours();
        $this->translationDisplay[$id] = 'translated';

        if ($commit->translation?->language === $language && $commit->translation->content !== null) {
            return;
        }

        $token = bin2hex(random_bytes(8));
        $this->translating[$id] = $token;
        $body = $commit->body;
        $accountId = app(Account::class)->user()?->id;

        $key = $this->translationRequestKey($id, $body, $language);
        $this->sendInBackground(fn (Client $client): SignedRequest => $client->signedWrite('POST', Client::translationPath($id), ['language' => $language]), function (array $json) use ($id, $body, $language, $token, $key, $accountId): void {
            if (($this->translating[$id] ?? null) !== $token) {
                return;
            }

            unset($this->translating[$id]);
            $this->translationRevisions[$key] = ($this->translationRevisions[$key] ?? 0) + 1;
            $this->takeTranslation($id, $body, $language, $json, $accountId, generated: true);
        }, function (string $message) use ($id, $token): void {
            if (($this->translating[$id] ?? null) !== $token) {
                return;
            }

            unset($this->translating[$id]);
            Toasts::failed($message);
        }, timeout: 90, requestTimeout: 80);
    }

    /** @var array<string, int> Successful explicit generations supersede older cache reads. */
    protected array $translationRevisions = [];

    /** Reads shared cache only. Scrolling or refreshing must never generate a translation. */
    protected function refreshShownTranslations(): void
    {
        $language = app(TranslationLanguage::class)->yours();

        foreach ($this->everyShownCommit() as $commit) {
            if (! $this->canTranslate($commit) || ($commit->translation?->language === $language && $commit->translation->content !== null)) {
                continue;
            }

            $key = $this->translationRequestKey($commit->id, $commit->body, $language);

            if (isset($this->readingTranslations[$key]) || microtime(true) - ($this->translationsReadAt[$key] ?? 0) < 30) {
                continue;
            }

            $revision = $this->translationRevisions[$key] ?? 0;
            $token = bin2hex(random_bytes(8));
            $this->readingTranslations[$key] = $token;
            $id = $commit->id;
            $body = $commit->body;
            $accountId = app(Account::class)->user()?->id;
            $this->sendInBackground(fn (Client $client): SignedRequest => $client->signedGet(Client::translationPath($id), ['language' => $language]), function (array $json) use ($id, $body, $language, $key, $token, $revision, $accountId): void {
                if (($this->readingTranslations[$key] ?? null) !== $token) {
                    return;
                }

                unset($this->readingTranslations[$key]);
                $this->translationsReadAt[$key] = microtime(true);
                if (($this->translationRevisions[$key] ?? 0) === $revision) {
                    $this->takeTranslation($id, $body, $language, $json, $accountId);
                }
            }, function () use ($key, $token): void {
                if (($this->readingTranslations[$key] ?? null) === $token) {
                    unset($this->readingTranslations[$key]);
                }
            });
        }
    }

    /** Drops callbacks NativePHP discarded under another screen, allowing resume to request again. */
    protected function forgetTranslationRequests(): void
    {
        $this->translating = [];
        $this->readingTranslations = [];
        $this->translationsReadAt = [];
        $this->translationRevisions = [];
        $this->refreshSettings();
    }

    /** Identifies a translation independently of stale body edits or a changed target language. */
    private function translationRequestKey(string $id, string $body, string $language): string
    {
        return hash('sha256', $id."\0".$body."\0".$language);
    }

    /**
     * Applies matching body/language results to shown and cached copies without altering display overrides.
     *
     * @param  array<string, mixed>  $json
     */
    private function takeTranslation(string $id, string $body, string $language, array $json, ?string $accountId, bool $generated = false): void
    {
        if ($accountId !== app(Account::class)->user()?->id || $language !== app(TranslationLanguage::class)->yours() || ! array_key_exists('translation', $json)) {
            return;
        }

        $data = is_array($json['translation']) ? $json['translation'] : null;
        $translation = $data === null ? null : CommitTranslation::fromArray($data);

        if ($translation !== null && $translation->language !== $language) {
            return;
        }

        if ($generated && $translation?->content !== null && $this->shownCommit($id)?->body === $body) {
            app(ResponseCache::class)->rememberGeneratedTranslation($id, $body, $data);
        }

        $this->changeShownCommit($id, fn (Commit $shown): Commit => $shown->body === $body ? $shown->withTranslation($translation) : $shown);
        app(ResponseCache::class)->patchCommit($id, fn (array $kept): array => ($kept['body'] ?? null) === $body ? [...$kept, 'translation' => $data] : $kept);
    }
}
