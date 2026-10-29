<?php

namespace App\NativeComponents;

use App\Devnull\Client;
use App\Devnull\Exceptions\ApiException;
use App\Devnull\Exceptions\KeychainUnavailable;
use App\Devnull\Exceptions\Unauthenticated;
use App\NativeComponents\Concerns\OpensInTab;
use App\NativeComponents\Concerns\PicksPeople;
use App\NativeComponents\Concerns\SearchesPeople;
use App\NativeComponents\Concerns\SyncsOutbox;
use App\NativeComponents\Concerns\TalksToDevnull;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/**
 * The web's New org: name it if you like, find people by name or handle and
 * pick them, up to the web's 100 with you, then Create org, and the new org
 * opens in this one's place. Someone who can't join shows why, as on the web,
 * and so does everyone else once the org is full.
 */
class NewOrg extends NativeComponent
{
    use OpensInTab;
    use PicksPeople;
    use SearchesPeople;
    use SyncsOutbox;
    use TalksToDevnull;

    /**
     * The most people an org holds, you included, as on the web.
     */
    public const int MaxMembers = 100;

    public const int NameMaxLength = 60;

    public string $name = '';

    public bool $creating = false;

    public function navTitle(): string
    {
        return 'New org';
    }

    public function updatedQuery(): void
    {
        $this->searchPeople('org');
    }

    public function create(): void
    {
        if ($this->picked === [] || $this->creating) {
            return;
        }

        $this->creating = true;
        $this->pickError = null;

        try {
            $id = app(Client::class)->createOrg(
                trim($this->name) === '' ? null : trim($this->name),
                $this->pickedUsernames(),
            );
        } catch (Unauthenticated $exception) {
            $this->signOut($exception);

            return;
        } catch (ApiException $exception) {
            $this->pickError = $exception->firstError();
            $this->creating = false;

            return;
        } catch (KeychainUnavailable $exception) {
            $this->pickError = $exception->getMessage();
            $this->creating = false;

            return;
        }

        $this->replace($this->tabRoot().'/orgs/'.rawurlencode($id));
    }

    public function render(): View
    {
        return view('native.new-org', [
            'term' => $this->term(),
            'total' => $this->total(),
            'isFull' => $this->isFull(),
            'myColour' => $this->myColour(),
            'rows' => $this->candidateRows(),
        ]);
    }

    /**
     * You, since you maintain it.
     */
    protected function alreadyIn(): int
    {
        return 1;
    }
}
