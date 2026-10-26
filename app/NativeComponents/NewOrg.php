<?php

namespace App\NativeComponents;

use App\Devnull\Client;
use App\Devnull\Data\User;
use App\Devnull\Exceptions\ApiException;
use App\Devnull\Exceptions\KeychainUnavailable;
use App\Devnull\Exceptions\Unauthenticated;
use App\NativeComponents\Concerns\OpensInTab;
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
    use SearchesPeople;
    use SyncsOutbox;
    use TalksToDevnull;

    /**
     * The most people an org holds, you included, as on the web.
     */
    public const int MaxMembers = 100;

    public const int NameMaxLength = 60;

    public string $name = '';

    /** @var list<User> */
    public array $picked = [];

    public bool $creating = false;

    /**
     * Why devnull wouldn't create it, in its words, beside the button as on the web.
     */
    public ?string $createError = null;

    public function navTitle(): string
    {
        return 'New org';
    }

    public function updatedQuery(): void
    {
        $this->searchPeople('org');
    }

    /**
     * Picks someone, or takes them back out. Someone who can't join, or anyone once the org is full, can't be picked.
     */
    public function toggle(string $username): void
    {
        $this->createError = null;

        if ($this->isPicked($username)) {
            $this->picked = array_values(array_filter($this->picked, fn (User $person): bool => $person->username !== $username));

            return;
        }

        $candidate = $this->candidate($username);

        if ($candidate === null || $candidate['reason'] !== null || $this->isFull()) {
            return;
        }

        $this->picked = [...$this->picked, $candidate['user']];
    }

    public function create(): void
    {
        if ($this->picked === [] || $this->creating) {
            return;
        }

        $this->creating = true;
        $this->createError = null;

        try {
            $id = app(Client::class)->createOrg(
                trim($this->name) === '' ? null : trim($this->name),
                array_map(fn (User $person): string => $person->username, $this->picked),
            );
        } catch (Unauthenticated $exception) {
            $this->signOut($exception);

            return;
        } catch (ApiException $exception) {
            $this->createError = $exception->firstError();
            $this->creating = false;

            return;
        } catch (KeychainUnavailable $exception) {
            $this->createError = $exception->getMessage();
            $this->creating = false;

            return;
        }

        $this->replace($this->tabRoot().'/orgs/'.rawurlencode($id));
    }

    public function render(): View
    {
        $isFull = $this->isFull();

        return view('native.new-org', [
            'term' => $this->term(),
            'total' => 1 + count($this->picked),
            'isFull' => $isFull,
            'myColour' => $this->myColour(),
            'rows' => array_map(fn (array $candidate): array => [
                'user' => $candidate['user'],
                'isPicked' => $this->isPicked($candidate['user']->username),
                'reason' => $candidate['reason'] ?? ($isFull && ! $this->isPicked($candidate['user']->username) ? 'the org is full ('.self::MaxMembers.')' : null),
            ], $this->candidates),
        ]);
    }

    private function isPicked(string $username): bool
    {
        foreach ($this->picked as $person) {
            if ($person->username === $username) {
                return true;
            }
        }

        return false;
    }

    private function isFull(): bool
    {
        return 1 + count($this->picked) >= self::MaxMembers;
    }
}
