{{--
    Laid out like the web's organization page: the avatar as a rounded square, the name, the handle marked
    Organization, what GitHub says about it, then the web's note that organization pages are new. devnull's error
    isn't printed: the page says itself when it didn't load, and offers the page on the web.
--}}
<native:scroll-view class="w-full h-full bg-theme-surface">
    <native:column class="w-full">
        @include('native.partials.refresh-failed')

        @if ($organization)
            <native:column ref="organization-header" class="w-full px-4 pt-6 pb-4 gap-4">
                <native:stack :width="80" :height="80" class="rounded-lg bg-theme-wash" center>
                    <native:text font="semibold" class="text-theme-ink-muted" :font-size="32">{{ mb_strtoupper(mb_substr($organization->name, 0, 1)) }}</native:text>
                    @if ($organization->avatarUrl)
                        <native:image :src="\App\Devnull\ImageCache::src($organization->avatarUrl)" :width="80" :height="80" :fit="2" class="rounded-lg" />
                    @endif
                </native:stack>

                <native:column class="w-full gap-1">
                    <native:text font="semibold" class="text-2xl tracking-tight text-theme-ink">{{ $organization->name }}</native:text>
                    <native:row class="w-full gap-2 items-center">
                        <native:text font="mono" class="text-[13px] text-theme-ink-muted flex-shrink" :max-lines="1">{{ '@'.$organization->username }}</native:text>
                        <native:column ref="organization-badge" class="px-2 py-[2] rounded bg-theme-wash flex-shrink-0">
                            <native:text class="text-xs text-theme-ink-muted">Organization</native:text>
                        </native:column>
                    </native:row>
                </native:column>

                @if ($organization->bio)
                    @include('native.partials.github-text', ['text' => $organization->bio, 'ref' => 'organization-bio', 'classes' => 'w-full text-[15px] leading-relaxed text-theme-ink', 'maxLines' => null])
                @endif

                <native:column ref="organization-facts" class="w-full gap-[6]">
                    @if ($organization->location)
                        @include('native.partials.profile-fact', ['key' => 'location', 'icon' => \App\Icons\Lucide::MapPin, 'label' => $organization->location])
                    @endif
                    @if ($organization->websiteLabel())
                        @include('native.partials.profile-fact', ['key' => 'website', 'icon' => \App\Icons\Lucide::Link, 'label' => $organization->websiteLabel(), 'press' => 'openWebsite'])
                    @endif
                    @include('native.partials.profile-fact', ['key' => 'github', 'icon' => \App\Icons\Lucide::Github, 'label' => 'github.com/'.$organization->username, 'press' => 'openGitHub'])
                </native:column>
            </native:column>
            <native:column class="w-full h-[1] bg-theme-hairline" />

            @include('native.partials.empty', [
                'command' => '$ git log --grep=@'.$organization->username,
                'message' => "Organization pages are new. Soon you'll be able to follow @{$organization->username} and read what the community commits about it, right here.",
            ])
        @elseif ($error)
            @include('native.partials.empty', [
                'command' => '$ git log --grep=@'.$handle,
                'message' => "This organization's page didn't load here.",
                'link' => 'Open it on dev/null',
                'press' => 'openOnWeb',
            ])
        @endif
    </native:column>
</native:scroll-view>
