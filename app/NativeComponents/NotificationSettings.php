<?php

namespace App\NativeComponents;

use App\Devnull\NotificationFilter;
use App\NativeComponents\Concerns\TalksToDevnull;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/**
 * Which kinds of notification the Notifications tab shows, one switch each,
 * as the web's filters choose them. A switch saves as soon as it flips, as a
 * filter does on the web, and the list follows when you go back to it.
 *
 * The web keeps the choice in a cookie in your browser and devnull's API
 * keeps none, so the app keeps it on this phone, per user.
 */
class NotificationSettings extends NativeComponent
{
    use TalksToDevnull;

    /** @var list<string> */
    public array $kinds = [];

    public function mount(): void
    {
        $this->kinds = app(NotificationFilter::class)->enabled($this->me()?->id);
    }

    public function navTitle(): string
    {
        return 'Notification settings';
    }

    public function setKind(string $kind, bool $on): void
    {
        if (! array_key_exists($kind, NotificationFilter::Kinds)) {
            return;
        }

        $this->kinds = app(NotificationFilter::class)->set($this->me()?->id, $kind, $on);
    }

    public function render(): View
    {
        return view('native.notification-settings', [
            'options' => NotificationFilter::Kinds,
        ]);
    }
}
