<?php

namespace App\Devnull;

use Carbon\CarbonImmutable;
use Native\Mobile\Facades\Device;

/** The saved language, or the phone's primary language, with English as the fallback. */
final class TranslationLanguage
{
    /** @var array{language: string, at: int}|null */
    private ?array $phone = null;

    /** Chooses a supported account or phone language without requesting settings per row. */
    public function yours(): string
    {
        $account = app(Account::class);
        $language = $account->translationLanguage() ?? $this->phone();
        $known = array_column($account->translationLanguages(), 'code');

        return $known === [] || in_array($language, $known, true) ? $language : 'en';
    }

    /** Keeps native device info for a minute and extracts its BCP47 primary language. */
    private function phone(): string
    {
        $now = CarbonImmutable::now()->getTimestamp();

        if ($this->phone === null || $now - $this->phone['at'] >= 60) {
            $info = json_decode((string) Device::getInfo(), true);
            $locale = is_array($info) ? ($info['language'] ?? null) : null;
            $language = is_string($locale) && preg_match('/^([a-z]{2,3})(?:[-_]|$)/i', $locale, $matches) === 1 ? strtolower($matches[1]) : 'en';
            $this->phone = ['language' => $language, 'at' => $now];
        }

        return $this->phone['language'];
    }
}
