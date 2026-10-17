<native:column class="w-full h-full bg-theme-background">
    @include('native.partials.error')
    @include('native.partials.refresh-failed')
    @include('native.partials.commit-sheet')

    {{-- The list stays alone in here, so the banners above coming and going never add a node beside it. --}}
    <native:column class="w-full flex-1">
    {{-- Keyed so a new key puts the profile back at the top, as the new commits pill does. --}}
    <native:list native:key="profile-{{ $listKey }}" class="w-full flex-1 bg-theme-surface" on-refresh="load" on-end-reached="loadMore" :end-reached-buffer="5">
        @if ($unavailable)
            @include('native.partials.profile-unavailable')
        @elseif (! $profile && $error === null)
            <native:column ref="loading-profile" class="w-full items-center py-16 bg-theme-surface" a11y-label="Loading profile">
                <native:activity-indicator />
            </native:column>
        @endif

        @if ($profile && ! $unavailable)
            @php($user = $profile->user)
            <native:column class="w-full bg-theme-surface">
                <native:column ref="profile-header" class="w-full px-4 pt-6 pb-4 gap-4">
                    <native:row class="w-full items-start justify-between">
                        {{-- The avatar wears the author's lane as a ring, offset from the surface. --}}
                        <native:stack :width="92" :height="92" class="rounded-full bg-theme-lane-{{ $user->lane() }}">
                            <native:stack :width="88" :height="88" class="rounded-full bg-theme-surface">
                                @include('native.partials.avatar', ['user' => $user, 'size' => 80])
                            </native:stack>
                        </native:stack>

                        @if ($sketched)
                            {{-- Follow waits for devnull to say whether you already do. --}}
                        @elseif ($isMe)
                            <native:button ref="edit-on-github" variant="secondary" size="sm" :ios-icon-trailing="\App\Icons\Lucide::ArrowUpRight->ios()" :android-icon-trailing="\App\Icons\Lucide::ArrowUpRight->android()" @press="editOnGitHub">Edit on GitHub</native:button>
                        @elseif ($profile->isFollowing)
                            <native:button ref="follow" variant="secondary" size="sm" @press="toggleFollow" a11y-hint="Unfollows {{ $user->handle() }}">Unfollow</native:button>
                        @else
                            <native:button ref="follow" variant="primary" size="sm" @press="toggleFollow">Follow</native:button>
                        @endif
                    </native:row>

                    <native:column class="w-full gap-1">
                        <native:text font="semibold" class="text-2xl tracking-tight text-theme-ink">{{ $user->name }}</native:text>
                        {{--
                            The handle, then what the web marks beside it: Follows you, Muted, and the Unix rank in the
                            owner's lane. EDGE's rows don't wrap on iOS, so the handle gives way first.
                        --}}
                        <native:row ref="profile-handle" class="w-full gap-2 items-center">
                            <native:column :width="8" :height="8" class="rounded-full bg-theme-lane-{{ $user->lane() }} flex-shrink-0" />
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
                                <native:column ref="rank" class="px-2 py-[2] rounded-full border border-theme-lane-{{ $user->lane() }}/25 bg-theme-lane-{{ $user->lane() }}/10 flex-shrink-0" a11y-label="Level {{ $profile->achievements->level }}, {{ $profile->achievements->rank }}">
                                    <native:text font="mono-medium" class="text-[11px] text-theme-lane-{{ $user->lane() }}">{{ $profile->achievements->label() }}</native:text>
                                </native:column>
                            @endif
                        </native:row>
                    </native:column>

                    @if ($user->bio)
                        <native:text class="w-full text-[15px] leading-relaxed text-theme-ink">{{ $user->bio }}</native:text>
                    @endif

                    {{--
                        What GitHub says about them, as the web lists it under the bio, links in Commit Blue. The web wraps
                        them onto as many lines as it needs; EDGE's rows don't wrap on iOS, so here each has its own line.
                    --}}
                    <native:column ref="profile-facts" class="w-full gap-[6]">
                        @if ($user->company)
                            @include('native.partials.profile-fact', ['key' => 'company', 'icon' => \App\Icons\Lucide::Building2, 'label' => $user->company])
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
                    <native:row ref="counts" class="w-full gap-4">
                        @foreach ([[$profile->commitsCount, 'commit', 'commits'], [$profile->followingCount, 'following', 'following'], [$profile->followersCount, 'follower', 'followers']] as [$count, $singular, $plural])
                            <native:row class="gap-1 items-center">
                                <native:text font="mono-medium" class="text-[13px] text-theme-ink">{{ $count }}</native:text>
                                <native:text class="text-sm text-theme-ink-muted">{{ $count === 1 ? $singular : $plural }}</native:text>
                            </native:row>
                        @endforeach
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

            @include('native.partials.page-tabs', ['tabs' => [
                ['key' => 'commits', 'label' => 'Commits', 'active' => $tab === 'commits', 'press' => "showTab('commits')"],
                ['key' => 'replies', 'label' => 'Replies', 'active' => $tab === 'replies', 'press' => "showTab('replies')"],
            ]])

            @include('native.partials.new-commits-pill', ['label' => $newCommitsLabel, 'press' => 'showNewCommits', 'shown' => $newCommits !== []])

            @if (! $commitsLoaded && $error === null)
                @foreach (range(1, 3) as $skeleton)
                    @include('native.partials.rail-loading', ['ref' => 'loading-'.$skeleton, 'label' => 'Loading commits'])
                @endforeach
            @endif

            @if ($commitsLoaded && ! $sketched && $commits === [] && $pinnedCommit === null && $error === null)
                @if ($tab === 'replies')
                    @include('native.partials.empty', ['command' => 'fatal: no replies yet', 'message' => $isMe ? 'Your replies show up here. Join a conversation from your log.' : "{$user->handle()} hasn't replied to anything yet."])
                @elseif ($isMe)
                    @include('native.partials.empty', ['command' => 'fatal: you do not have any commits yet', 'message' => 'Your commits show up here. Make your first one from your log.'])
                @else
                    @include('native.partials.empty', ['command' => 'fatal: no commits yet', 'message' => "{$user->handle()} hasn't committed anything yet."])
                @endif
            @endif

            @if ($pinnedCommit)
                {{-- The pinned commit sits above the rest, marked in mono as on the web, with no rail above it. --}}
                <native:row ref="pinned" class="w-full bg-theme-surface">
                    <native:column :width="64" />
                    <native:row class="flex-1 pt-3 gap-[6] items-center">
                        <native:icon :ios="\App\Icons\Lucide::Pin->ios()" :android="\App\Icons\Lucide::Pin->android()" :size="12" class="text-theme-ink-muted" />
                        <native:text font="mono" class="text-xs text-theme-ink-muted">Pinned</native:text>
                    </native:row>
                </native:row>
                @include('native.partials.commit-row', ['commit' => $pinnedCommit, 'previousLane' => null, 'continuesRun' => false, 'rowKey' => 'pinned-'.$pinnedCommit->id])
            @endif

            @include('native.partials.feed', ['rows' => $rows])

            @if ($commits !== [] || $pinnedCommit)
                @if ($nextCursor !== null)
                    @include('native.partials.rail-loading')
                @elseif ($tab === 'replies')
                    @include('native.partials.rail-end', ['lane' => $user->lane(), 'message' => $isMe ? 'That was your first reply.' : "That's {$user->handle()}'s first reply."])
                @else
                    @include('native.partials.rail-end', ['lane' => $user->lane(), 'message' => $isMe ? 'That was your first commit. Everyone starts somewhere.' : "That's {$user->handle()}'s root commit."])
                @endif
            @endif
        @endif
    </native:list>
    </native:column>
</native:column>
