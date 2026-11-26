<native:column class="w-full h-full bg-theme-background">
    @include('native.partials.error')
    @include('native.partials.refresh-failed')

    <native:list native:key="guild-members" class="w-full flex-1 bg-theme-surface" on-refresh="load" on-end-reached="loadMore" :end-reached-buffer="5">
        @if ($unavailable)
            @include('native.partials.empty', ['command' => 'fatal: guilds are not available', 'message' => "Guilds aren't open to you yet."])
        @elseif ($missing)
            @include('native.partials.empty', ['command' => 'fatal: no such guild #'.$handle, 'message' => 'This guild is no longer available.'])
        @elseif (! $loaded)
            @if ($error === null)
                @foreach (range(1, 6) as $skeleton)
                    @include('native.partials.connection-loading', ['ref' => 'loading-'.$skeleton, 'label' => 'Loading members'])
                @endforeach
            @endif
        @elseif ($people === [])
            @include('native.partials.empty', ['command' => 'fatal: no members yet', 'message' => 'Nobody has joined this guild yet.'])
        @else
            @foreach ($people as $person)
                @include('native.partials.connection', ['person' => $person, 'isMe' => false, 'showsFollow' => false, 'showsFollowsYou' => false, 'isModerator' => $moderatorId !== null && $person->user->id === $moderatorId, 'myColour' => null])
            @endforeach

            @if ($nextCursor !== null)
                @include('native.partials.connection-loading', ['ref' => 'loading-more', 'label' => 'Loading more people'])
            @endif
        @endif
    </native:list>
</native:column>
