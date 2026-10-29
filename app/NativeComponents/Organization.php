<?php

namespace App\NativeComponents;

use App\Devnull\Data\User;
use App\Devnull\SiteLink;
use App\NativeComponents\Concerns\RefreshesInBackground;
use App\NativeComponents\Concerns\TalksToDevnull;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Browser;

/**
 * A GitHub organization someone mentioned, as its page on the web shows it:
 * what GitHub says about it, then that organization pages are new. It opens
 * from a mention of one, beneath the tab it was tapped in. When devnull
 * can't send it, the page on the web is a tap away.
 *
 * devnull sends an organization with the fields a user has, so it's kept as a
 * User and drawn with the profile's pieces. It opens on the one kept last
 * time, then asks devnull again behind it.
 */
class Organization extends NativeComponent
{
    use RefreshesInBackground;
    use TalksToDevnull;

    public ?User $organization = null;

    public function mount(): void
    {
        $this->showKept();
        $this->revalidate();
    }

    public function navTitle(): string
    {
        return $this->organization->name ?? $this->handle();
    }

    public function openWebsite(): void
    {
        $url = $this->organization?->websiteUrl();

        if (SiteLink::isWebUrl($url)) {
            Browser::inApp($url);
        }
    }

    public function openOnWeb(): void
    {
        Browser::inApp(rtrim((string) config('devnull.url'), '/').'/@'.rawurlencode($this->handle()));
    }

    public function openGitHub(): void
    {
        Browser::inApp('https://github.com/'.rawurlencode($this->handle()));
    }

    public function openGitHubMention(string $username): void
    {
        Browser::inApp('https://github.com/'.rawurlencode($username));
    }

    public function render(): View
    {
        return view('native.organization', ['handle' => $this->handle()]);
    }

    protected function revalidate(): void
    {
        $this->refresh($this->path(), [], $this->take(...));
    }

    protected function showsContent(): bool
    {
        return $this->organization !== null;
    }

    private function showKept(): void
    {
        if (($json = $this->cached($this->path())) !== null) {
            $this->take($json);
        }
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function take(array $json): void
    {
        $this->organization = User::fromArray(is_array($json['data'] ?? null) ? $json['data'] : []);
    }

    private function path(): string
    {
        return 'organizations/'.rawurlencode($this->handle());
    }

    private function handle(): string
    {
        return (string) $this->param('handle');
    }
}
