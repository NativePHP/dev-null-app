<?php

namespace App\NativeComponents\Concerns;

use App\Devnull\Account;
use App\Devnull\Client;

trait ManagesTranslationSettings
{
    public ?string $chosenTranslationLanguage = null;

    public ?string $savedTranslationLanguage = null;

    public bool $automaticTranslation = true;

    public bool $pickingTranslationLanguage = false;

    public bool $listingTranslationLanguages = false;

    public string $translationLanguageQuery = '';

    public bool $savingTranslationLanguage = false;

    public bool $savingAutomaticTranslation = false;

    public ?string $translationSettingsError = null;

    /** Invalidates writes whose scoped callbacks were dropped while Account was covered. */
    protected int $translationSettingsRevision = 0;

    /** Restores pending controls on resume; fresh settings reconcile writes that succeeded off-screen. */
    protected function recoverTranslationSettings(): void
    {
        $this->translationSettingsRevision++;

        if ($this->savingTranslationLanguage) {
            $this->chosenTranslationLanguage = $this->savedTranslationLanguage;
        }

        $this->savingTranslationLanguage = false;
        $this->savingAutomaticTranslation = false;
        $this->automaticTranslation = app(Account::class)->automaticTranslation();
    }

    /** Seeds settings from this account while a quiet settings refresh is in flight. */
    protected function loadTranslationSettings(): void
    {
        $account = app(Account::class);
        $this->savedTranslationLanguage = $this->chosenTranslationLanguage = $account->translationLanguage();
        $this->automaticTranslation = $account->automaticTranslation();
    }

    /**
     * Takes only fields present in a partial settings answer, retaining an unsaved language selection.
     *
     * @param  array<string, mixed>  $settings
     */
    protected function takeTranslationSettings(array $settings): void
    {
        $account = app(Account::class);
        $unchanged = $this->chosenTranslationLanguage === $this->savedTranslationLanguage;
        $account->rememberTranslationSettings($settings);
        $this->savedTranslationLanguage = $account->translationLanguage();

        if ($unchanged) {
            $this->chosenTranslationLanguage = $this->savedTranslationLanguage;
        }

        if (! $this->savingAutomaticTranslation) {
            $this->automaticTranslation = $account->automaticTranslation();
        }
    }

    /** @return list<array{code: string, name: string, native_name: string}> */
    public function translationLanguages(): array
    {
        return array_slice($this->matchingTranslationLanguages(), 0, 50);
    }

    /** The matches omitted to keep a large supported-language corpus within EDGE's frame budget. */
    public function translationLanguagesMore(): int
    {
        return max(0, count($this->matchingTranslationLanguages()) - 50);
    }

    /** @return list<array{code: string, name: string, native_name: string}> */
    private function matchingTranslationLanguages(): array
    {
        $query = mb_strtolower(trim($this->translationLanguageQuery));

        return array_values(array_filter(app(Account::class)->translationLanguages(), fn (array $language): bool => $query === ''
            || str_contains(mb_strtolower(implode(' ', $language)), $query)));
    }

    /** Human-readable selection using both English and native names. */
    public function translationLanguageName(): string
    {
        foreach (app(Account::class)->translationLanguages() as $language) {
            if ($language['code'] === $this->chosenTranslationLanguage) {
                return $language['name'].' · '.$language['native_name'];
            }
        }

        return $this->chosenTranslationLanguage ?? 'Use phone language';
    }

    /** Opens a fresh searchable picker; rows remain mounted until native dismissal completes. */
    public function pickTranslationLanguage(): void
    {
        $this->translationLanguageQuery = '';
        $this->listingTranslationLanguages = true;
        $this->pickingTranslationLanguage = true;
    }

    /** Selects a supported language, or the phone default, without saving until explicitly requested. */
    public function chooseTranslationLanguage(?string $language): void
    {
        if ($language !== null && ! in_array($language, array_column(app(Account::class)->translationLanguages(), 'code'), true)) {
            return;
        }

        $this->chosenTranslationLanguage = $language;
        $this->pickingTranslationLanguage = false;
        $this->translationSettingsError = null;
    }

    /** Releases the picker list after the native sheet has finished dismissing. */
    public function translationLanguageSheetClosed(): void
    {
        $this->pickingTranslationLanguage = false;
        $this->listingTranslationLanguages = false;
    }

    /** Persists the submitted language because the write response contains me, not settings. */
    public function saveTranslationLanguage(): void
    {
        if ($this->savingTranslationLanguage || app(Account::class)->translationLanguages() === [] || $this->current === null || $this->chosenTranslationLanguage === $this->savedTranslationLanguage) {
            return;
        }

        $revision = $this->translationSettingsRevision;
        $language = $this->chosenTranslationLanguage;
        $this->savingTranslationLanguage = true;
        $this->translationSettingsError = null;
        $this->writeInBackground('PUT', Client::TranslationLanguagePath, ['translation_language' => $language], function (array $json) use ($language, $revision): void {
            if ($revision !== $this->translationSettingsRevision) {
                return;
            }

            $this->savingTranslationLanguage = false;
            $this->takeMe($json);
            app(Account::class)->rememberTranslationSettings(['translation_language' => $language]);
            $this->savedTranslationLanguage = $language;
        }, function (string $message) use ($revision): void {
            if ($revision !== $this->translationSettingsRevision) {
                return;
            }

            $this->savingTranslationLanguage = false;
            $this->translationSettingsError = $message;
        });
    }

    /** Saves an explicit switch change and restores the last saved value if devnull refuses it. */
    public function setAutomaticTranslation(bool $enabled): void
    {
        if ($this->savingAutomaticTranslation || app(Account::class)->translationLanguages() === [] || $this->current === null) {
            return;
        }

        $revision = $this->translationSettingsRevision;
        $this->automaticTranslation = $enabled;
        $this->savingAutomaticTranslation = true;
        $this->translationSettingsError = null;
        $this->writeInBackground('PUT', Client::AutomaticTranslationPath, ['enabled' => $enabled], function (array $json) use ($enabled, $revision): void {
            if ($revision !== $this->translationSettingsRevision) {
                return;
            }

            $this->savingAutomaticTranslation = false;
            $this->takeMe($json);
            app(Account::class)->rememberTranslationSettings(['automatic_translation' => $enabled]);
        }, function (string $message) use ($revision): void {
            if ($revision !== $this->translationSettingsRevision) {
                return;
            }

            $this->savingAutomaticTranslation = false;
            $this->automaticTranslation = app(Account::class)->automaticTranslation();
            $this->translationSettingsError = $message;
        });
    }
}
