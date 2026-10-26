<?php

namespace App\NativeComponents;

use App\NativeComponents\Concerns\OpensInTab;
use App\NativeComponents\Concerns\SearchesPeople;
use App\NativeComponents\Concerns\SyncsOutbox;
use App\NativeComponents\Concerns\TalksToDevnull;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/**
 * The web's New remote: find a developer by name or handle to message, and
 * your conversation with them opens in this one's place. Someone you can't
 * message shows why, as the web's picker does, and can't be picked.
 */
class NewRemote extends NativeComponent
{
    use OpensInTab;
    use SearchesPeople;
    use SyncsOutbox;
    use TalksToDevnull;

    public function navTitle(): string
    {
        return 'New remote';
    }

    public function updatedQuery(): void
    {
        $this->searchPeople('remote');
    }

    public function pick(string $username): void
    {
        $candidate = $this->candidate($username);

        if ($candidate === null || $candidate['reason'] !== null) {
            return;
        }

        $this->replace($this->tabRoot().'/conversations/'.rawurlencode($username), ['user' => $candidate['user']]);
    }

    public function render(): View
    {
        return view('native.new-remote', [
            'term' => $this->term(),
        ]);
    }
}
