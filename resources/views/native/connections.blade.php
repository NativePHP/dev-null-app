<native:column class="w-full h-full bg-theme-background">
    @include('native.partials.error')
    @include('native.partials.refresh-failed')

    <native:list native:key="connections" class="w-full flex-1 bg-theme-surface" on-refresh="load" on-end-reached="loadMore" :end-reached-buffer="5">
        @if ($unavailable)
            @include('native.partials.profile-unavailable', ['profile' => null])
        @elseif (! $loaded)
            @if ($error === null)
                @foreach (range(1, 6) as $skeleton)
                    @include('native.partials.connection-loading', ['ref' => 'loading-'.$skeleton, 'label' => 'Loading '.$type])
                @endforeach
            @endif
        @elseif ($people === [])
            @include('native.partials.empty', $type === 'following'
                ? ['command' => 'fatal: no following yet', 'message' => "@{$username} isn't following anyone yet."]
                : ['command' => 'fatal: no followers yet', 'message' => "Nobody is following @{$username} yet."])
        @else
            @foreach ($people as $person)
                {{-- Everyone on your own Followers follows you, so it goes without saying there. --}}
                @include('native.partials.connection', ['person' => $person, 'isMe' => $person->user->username === $viewer?->username, 'showsFollowsYou' => ! ($isOwn && $type === 'followers')])
            @endforeach

            @if ($nextCursor !== null)
                @include('native.partials.connection-loading', ['ref' => 'loading-more', 'label' => 'Loading more people'])
            @endif
        @endif
    </native:list>
</native:column>
