<?php

namespace App\NativeComponents;

use App\Devnull\Client;
use App\NativeComponents\Concerns\PicksPeople;
use App\NativeComponents\Concerns\SearchesPeople;
use App\NativeComponents\Concerns\SyncsOutbox;
use App\NativeComponents\Concerns\TalksToDevnull;
use App\NativeComponents\Concerns\WritesInBackground;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/**
 * The web's Add people, for an org you maintain: find people by name or
 * handle and pick them, up to the org's 100, then Add, and they join as
 * contributors. Share history lets them read what was pushed before they
 * joined. Someone who can't join shows why, as on the web, and so does
 * everyone else once the org is full. Once devnull has them, it's back to
 * the members, who now include them.
 */
class AddOrgMembers extends NativeComponent
{
    use PicksPeople;
    use SearchesPeople;
    use SyncsOutbox;
    use TalksToDevnull;
    use WritesInBackground;

    /**
     * The org's id, from the route.
     */
    public string $org = '';

    /**
     * How many are in the org already, as the members screen knew.
     */
    public int $memberCount = 1;

    public bool $sharesHistory = false;

    public bool $adding = false;

    public function mount(): void
    {
        $members = $this->data('members');
        $this->memberCount = is_int($members) && $members > 0 ? $members : 1;
    }

    public function navTitle(): string
    {
        return 'Add people';
    }

    public function updatedQuery(): void
    {
        $this->searchPeople('org', $this->org);
    }

    public function setSharesHistory(bool $sharesHistory): void
    {
        $this->sharesHistory = $sharesHistory;
    }

    public function add(): void
    {
        if ($this->picked === [] || $this->adding) {
            return;
        }

        $this->adding = true;
        $this->pickError = null;

        $this->writeInBackground('POST', Client::orgMembersPath($this->org), ['members' => $this->pickedUsernames(), 'share_history' => $this->sharesHistory], function (): void {
            $this->back();
        }, function (string $message): void {
            $this->pickError = $message;
            $this->adding = false;
        });
    }

    public function render(): View
    {
        return view('native.add-org-members', [
            'term' => $this->term(),
            'total' => $this->total(),
            'isFull' => $this->isFull(),
            'myColour' => $this->myColour(),
            'rows' => $this->candidateRows(),
        ]);
    }

    protected function alreadyIn(): int
    {
        return $this->memberCount;
    }
}
