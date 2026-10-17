<?php

namespace App\NativeComponents\Concerns;

/**
 * The web's achievements dialog, as a sheet over a profile (see
 * partials/achievements-sheet). Tapping the shelf opens it on the badges;
 * tapping a badge opens it with that badge marked in the owner's lane.
 *
 * Like the web, it has two tabs, the badges and the Unix ranks. Each opening
 * starts on the badges, and the sheet forgets the badge and the tab once iOS
 * says it's gone.
 */
trait OpensAchievements
{
    public const array AchievementsTabs = ['badges', 'ranks'];

    /**
     * Whether the sheet is up, from the tap until iOS says it's gone.
     */
    public bool $achievementsOpen = false;

    /**
     * The id of the badge the sheet was opened on, if it was.
     */
    public ?string $achievementsBadge = null;

    /**
     * 'badges' or 'ranks'.
     */
    public string $achievementsTab = 'badges';

    /**
     * The shelf, or one of its badges.
     */
    public function openAchievements(?string $badge = null): void
    {
        $achievements = $this->profile?->achievements;

        if ($achievements === null) {
            return;
        }

        $this->achievementsBadge = $badge !== null && $achievements->badgeIndex($badge) !== null ? $badge : null;
        $this->achievementsTab = 'badges';
        $this->achievementsOpen = true;
    }

    public function showAchievementsTab(string $tab): void
    {
        if (in_array($tab, self::AchievementsTabs, true)) {
            $this->achievementsTab = $tab;
        }
    }

    /**
     * iOS says the sheet has gone.
     */
    public function achievementsClosed(): void
    {
        $this->achievementsOpen = false;
        $this->achievementsBadge = null;
        $this->achievementsTab = 'badges';
    }
}
