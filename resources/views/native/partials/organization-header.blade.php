{{--
    The organization's header, which the pager draws above its tabs (see organization.blade.php), laid out like the
    web's: the avatar as a rounded square ringed in its lane, Share and Follow, the name, the handle marked
    Organization, what GitHub says about it, how many follow it and its admins. It scrolls up with the tab you're on
    until the tabs pin under the nav bar.
--}}
@php($lane = \App\Devnull\Lane::token($user->lane()))
<native:column class="w-full bg-theme-surface">
    <native:column ref="organization-header" class="w-full px-4 pt-6 pb-4 gap-4">
        <native:row class="w-full items-start justify-between">
            <native:stack :width="92" :height="92" class="rounded-xl bg-theme-{{ $lane }}" center>
                <native:stack :width="88" :height="88" class="rounded-lg bg-theme-surface" center>
                    <native:stack :width="80" :height="80" class="rounded-lg bg-theme-wash" center>
                        <native:text font="semibold" class="text-theme-ink-muted" :font-size="32">{{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}</native:text>
                        @if ($user->avatarUrl)
                            <native:image :src="\App\Devnull\ImageCache::src($user->avatarUrl)" :width="80" :height="80" :fit="2" class="rounded-lg" />
                        @endif
                    </native:stack>
                </native:stack>
            </native:stack>

            <native:row class="gap-2 items-center">
                <native:button ref="share" variant="secondary" size="sm" :ios-icon="\App\Icons\Lucide::Share->ios()" :android-icon="\App\Icons\Lucide::Share2->android()" @press="share" a11y-label="Share {{ $user->handle() }}" />
                @if ($organization->isFollowing)
                    <native:button ref="follow" variant="secondary" size="sm" @press="toggleFollow" a11y-hint="Unfollows {{ $user->handle() }}">Unfollow</native:button>
                @else
                    <native:button ref="follow" :variant="$myColour" size="sm" @press="toggleFollow">Follow</native:button>
                @endif
            </native:row>
        </native:row>

        <native:column class="w-full gap-1">
            <native:text font="semibold" class="text-2xl tracking-tight text-theme-ink">{{ $user->name }}</native:text>
            <native:row ref="organization-handle" class="w-full gap-2 items-center">
                <native:column :width="8" :height="8" class="rounded-full bg-theme-{{ $lane }} flex-shrink-0" />
                <native:text font="mono" class="text-[13px] text-theme-ink-muted flex-shrink" :max-lines="1">{{ $user->handle() }}</native:text>
                <native:column ref="organization-badge" class="px-2 py-[2] rounded bg-theme-wash flex-shrink-0">
                    <native:text class="text-xs text-theme-ink-muted">Organization</native:text>
                </native:column>
            </native:row>
        </native:column>

        @if ($user->bio)
            @include('native.partials.github-text', ['text' => $user->bio, 'ref' => 'organization-bio', 'classes' => 'w-full text-[15px] leading-relaxed text-theme-ink', 'maxLines' => null])
        @endif

        {{-- What GitHub says about it, links in Commit Blue, each on its own line, as on a profile. --}}
        <native:column ref="organization-facts" class="w-full gap-[6]">
            @if ($user->location)
                @include('native.partials.profile-fact', ['key' => 'location', 'icon' => \App\Icons\Lucide::MapPin, 'label' => $user->location])
            @endif
            @if ($user->websiteLabel())
                @include('native.partials.profile-fact', ['key' => 'website', 'icon' => \App\Icons\Lucide::Link, 'label' => $user->websiteLabel(), 'press' => 'openWebsite'])
            @endif
            @include('native.partials.profile-fact', ['key' => 'github', 'icon' => \App\Icons\Lucide::Github, 'label' => 'github.com/'.$user->username, 'press' => 'openGitHub'])
        </native:column>

        <native:row ref="followers" class="w-full gap-1 items-center">
            <native:text font="mono-medium" class="text-[13px] text-theme-ink">{{ \App\Devnull\Display::compactCount($organization->followersCount) }}</native:text>
            <native:text class="text-sm text-theme-ink-muted">{{ $organization->followersCount === 1 ? 'follower' : 'followers' }}</native:text>
        </native:row>

        {{--
            Its admins, first one first, each opening their profile. The web wraps them onto as many lines as they need;
            EDGE's rows don't wrap, so here they go three to a line under the label, a long handle trailing off.
        --}}
        @if ($organization->admins !== [])
            <native:column ref="admins" class="w-full gap-2">
                <native:text class="text-sm text-theme-ink-muted">Admins</native:text>
                @foreach (array_chunk($organization->admins, 3) as $line)
                    <native:row native:key="admins-{{ $line[0]->username }}" class="w-full gap-2 items-center">
                        @foreach ($line as $admin)
                            <native:pressable native:key="admin-{{ $admin->username }}" ref="admin-{{ $admin->username }}" @press="openAdmin('{{ $admin->username }}')" class="flex-1" a11y-label="{{ $admin->name }}'s profile">
                                <native:row class="w-full gap-[6] items-center">
                                    @include('native.partials.avatar', ['user' => $admin, 'size' => 20])
                                    <native:text class="flex-1 text-sm text-theme-ink" :max-lines="1">{{ $admin->handle() }}</native:text>
                                </native:row>
                            </native:pressable>
                        @endforeach
                        {{-- A short last line keeps its admins in the same columns as the lines above. --}}
                        @for ($gap = count($line); $gap < 3; $gap++)
                            <native:column native:key="admins-gap-{{ $gap }}" class="flex-1" />
                        @endfor
                    </native:row>
                @endforeach
            </native:column>
        @endif
    </native:column>
    <native:column class="w-full h-[1] bg-theme-hairline" />
</native:column>
