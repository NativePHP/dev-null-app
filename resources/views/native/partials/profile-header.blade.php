{{--
    The profile's header, which the pager draws above its tabs (see profile.blade.php): the avatar ringed in their
    lane, Follow (or, on your own, Scheduled commits and Edit on GitHub), who they are, what GitHub says about them,
    their counts and, in pastéis mode, the pastéis they've been given, then their activity, streaks and achievements.
    It scrolls up with the tab you're on until the tabs pin under the nav bar.
--}}
<native:column class="w-full bg-theme-surface">
    <native:column ref="profile-header" class="w-full px-4 pt-6 pb-4 gap-4">
        <native:row class="w-full items-start justify-between">
            {{--
                The avatar wears the author's lane as a ring, offset from the surface. While they stream it's red, with
                the LIVE badge 10pt below it, and a tap opens the stream sheet, as on the web.
            --}}
            @if ($user->isLive())
                <native:pressable ref="profile-live-stream" @press="openLiveStream('{{ $user->username }}', false)" :width="92" :height="92" a11y-label="{{ $user->name }} is live">
                    <native:stack :width="92" :height="92" class="rounded-full bg-theme-live">
                        <native:stack :width="88" :height="88" class="rounded-full bg-theme-surface">
                            @include('native.partials.avatar', ['user' => $user, 'size' => 80])
                        </native:stack>
                    </native:stack>
                    <native:row class="absolute left-0 right-0 top-[78] justify-center">
                        @include('native.partials.live-badge', ['size' => 'lg'])
                    </native:row>
                </native:pressable>
            @else
                <native:stack :width="92" :height="92" class="rounded-full bg-theme-{{ \App\Devnull\Lane::token($user->lane()) }}">
                    <native:stack :width="88" :height="88" class="rounded-full bg-theme-surface">
                        @include('native.partials.avatar', ['user' => $user, 'size' => 80])
                    </native:stack>
                </native:stack>
            @endif

            @if ($sketched)
                {{-- Follow waits for devnull to say whether you already do. --}}
            @elseif ($isMe)
                {{-- Your scheduled commits, which only you see, beside Edit on GitHub. --}}
                <native:row class="gap-2 items-center">
                    <native:button ref="profile-scheduled" variant="secondary" size="sm" :ios-icon="\App\Icons\Lucide::CalendarDays->ios()" :android-icon="\App\Icons\Lucide::CalendarDays->android()" @press="openScheduledCommits" a11y-label="Scheduled commits" />
                    <native:button ref="edit-on-github" variant="secondary" size="sm" :ios-icon-trailing="\App\Icons\Lucide::ArrowUpRight->ios()" :android-icon-trailing="\App\Icons\Lucide::ArrowUpRight->android()" @press="editOnGitHub">Edit on GitHub</native:button>
                </native:row>
            @else
                {{-- The web's Remote, beside Follow, when you can push to them. --}}
                <native:row class="gap-2 items-center">
                    @if ($canRemote)
                        <native:button ref="remote" variant="secondary" size="sm" :ios-icon="\App\Icons\Lucide::MessagesSquare->ios()" :android-icon="\App\Icons\Lucide::MessagesSquare->android()" @press="openRemote" a11y-label="Remote {{ $user->handle() }}">Remote</native:button>
                    @endif
                    @if ($profile->isFollowing)
                        <native:button ref="follow" variant="secondary" size="sm" @press="toggleFollow" a11y-hint="Unfollows {{ $user->handle() }}">Unfollow</native:button>
                    @else
                        <native:button ref="follow" :variant="$myColour" size="sm" @press="toggleFollow">Follow</native:button>
                    @endif
                </native:row>
            @endif
        </native:row>

        <native:column class="w-full gap-1">
            <native:text font="semibold" class="text-2xl tracking-tight text-theme-ink">{{ $user->name }}</native:text>
            {{--
                The handle, then what the web marks beside it: Follows you, Muted, and the Unix rank in the
                owner's lane. EDGE's rows don't wrap on iOS, so the handle gives way first.
            --}}
            <native:row ref="profile-handle" class="w-full gap-2 items-center">
                <native:column :width="8" :height="8" class="rounded-full bg-theme-{{ \App\Devnull\Lane::token($user->lane()) }} flex-shrink-0" />
                <native:text font="mono" class="text-[13px] text-theme-ink-muted flex-shrink" :max-lines="1">{{ $user->handle() }}</native:text>
                @if ($profile->followsYou && ! $sketched)
                    <native:column class="px-2 py-[2] rounded bg-theme-wash flex-shrink-0">
                        <native:text class="text-xs text-theme-ink-muted">Follows you</native:text>
                    </native:column>
                @endif
                @if ($profile->isMuting && ! $sketched)
                    <native:column ref="muted" class="px-2 py-[2] rounded bg-theme-wash flex-shrink-0">
                        <native:text class="text-xs text-theme-ink-muted">Muted</native:text>
                    </native:column>
                @endif
                @if ($profile->achievements && ! $sketched)
                    <native:column ref="rank" class="px-2 py-[2] rounded-full border border-theme-{{ \App\Devnull\Lane::token($user->lane()) }}/25 bg-theme-{{ \App\Devnull\Lane::token($user->lane()) }}/10 flex-shrink-0" a11y-label="Level {{ $profile->achievements->level }}, {{ $profile->achievements->rank }}">
                        <native:text font="mono-medium" class="text-[11px] text-theme-{{ \App\Devnull\Lane::token($user->lane()) }}">{{ $profile->achievements->label() }}</native:text>
                    </native:column>
                @endif
            </native:row>
        </native:column>

        @if ($user->bio)
            @include('native.partials.github-text', ['text' => $user->bio, 'ref' => 'profile-bio', 'classes' => 'w-full text-[15px] leading-relaxed text-theme-ink', 'maxLines' => null])
        @endif

        {{--
            What GitHub says about them, as the web lists it under the bio, links in Commit Blue. The web wraps
            them onto as many lines as it needs; EDGE's rows don't wrap on iOS, so here each has its own line.
        --}}
        <native:column ref="profile-facts" class="w-full gap-[6]">
            @if ($user->company)
                @include('native.partials.profile-fact', ['key' => 'company', 'icon' => \App\Icons\Lucide::Building2, 'label' => $user->company, 'gitHubMentions' => true])
            @endif
            @if ($user->location)
                @include('native.partials.profile-fact', ['key' => 'location', 'icon' => \App\Icons\Lucide::MapPin, 'label' => $user->location])
            @endif
            @if ($user->websiteLabel())
                @include('native.partials.profile-fact', ['key' => 'website', 'icon' => \App\Icons\Lucide::Link, 'label' => $user->websiteLabel(), 'press' => 'openWebsite'])
            @endif
            @include('native.partials.profile-fact', ['key' => 'github', 'icon' => \App\Icons\Lucide::Github, 'label' => 'github.com/'.$user->username, 'press' => 'openGitHubProfile'])
            @if ($user->createdAt)
                @include('native.partials.profile-fact', ['key' => 'joined', 'icon' => \App\Icons\Lucide::CalendarDays, 'label' => 'Joined '.$user->createdAt->format('F Y')])
            @endif
        </native:column>

        @if (! $sketched)
        {{-- Following and followers open their lists, as the web links them. --}}
        <native:row ref="counts" class="w-full gap-4">
            @foreach ([[$profile->commitsCount, 'commit', 'commits', null], [$profile->followingCount, 'following', 'following', 'following'], [$profile->followersCount, 'follower', 'followers', 'followers']] as [$count, $singular, $plural, $list])
                @if ($list === null)
                    <native:row class="gap-1 items-center">
                        <native:text font="mono-medium" class="text-[13px] text-theme-ink">{{ \App\Devnull\Display::compactCount($count) }}</native:text>
                        <native:text class="text-sm text-theme-ink-muted">{{ $count === 1 ? $singular : $plural }}</native:text>
                    </native:row>
                @else
                    <native:pressable ref="count-{{ $list }}" @press="openConnections('{{ $list }}')" a11y-label="{{ $count }} {{ $count === 1 ? $singular : $plural }}">
                        <native:row class="gap-1 items-center">
                            <native:text font="mono-medium" class="text-[13px] text-theme-ink">{{ \App\Devnull\Display::compactCount($count) }}</native:text>
                            <native:text class="text-sm text-theme-ink-muted">{{ $count === 1 ? $singular : $plural }}</native:text>
                        </native:row>
                    </native:pressable>
                @endif
            @endforeach
        </native:row>
        @endif

        {{-- In pastéis mode, as on the web: how many pastéis they have been given. --}}
        @if (! $sketched && ($pasteis ?? null) !== null && $profile->pasteisReceived !== null)
            <native:row ref="pasteis-received" class="w-full gap-[6] items-center" a11y-label="{{ $profile->pasteisReceived }} pastéis received">
                <native:image :src="\App\Devnull\Data\Pasteis::Image" :width="20" :height="20" :fit="1" />
                <native:text font="mono-medium" class="text-sm text-theme-ink">{{ \App\Devnull\Display::compactCount($profile->pasteisReceived) }}</native:text>
                <native:text class="text-sm text-theme-ink-muted">pastéis received</native:text>
            </native:row>
        @endif
    </native:column>
    <native:column class="w-full h-[1] bg-theme-hairline" />

    @if (! $sketched)
        @include('native.partials.activity', ['activity' => $profile->activity, 'lane' => $user->lane()])
        @include('native.partials.streaks', ['streaks' => $profile->streaks, 'lane' => $user->lane()])
        @if ($profile->achievements)
            @include('native.partials.achievements', ['achievements' => $profile->achievements, 'lane' => $user->lane()])
        @endif
    @endif
</native:column>
