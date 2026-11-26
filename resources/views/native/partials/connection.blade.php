{{--
    Someone in a list of followers or following, as the web's connections page draws them: their avatar ringed in their
    lane and offset from the surface, then their name, their handle with Follows you beside it where that applies, and
    up to two lines of bio. Follow sits on the right, a fixed width so Following takes the same room, except on your
    own row. A tap anywhere else opens them.

    Every list passes $showsFollowsYou, false where Follows you says nothing new (your own followers) or the web leaves
    it off (a guild's members). A list with no Follow (the guild's members, as the web draws them) passes
    $showsFollow = false, and the moderator's row passes $isModerator, which puts "(moderator)" after the name and
    ", moderator" on what screen readers say.

    No press-* feedback: on iOS it holds any drag that starts on the row, and these rows cover the whole list.
--}}
@php($user = $person->user)
<native:pressable native:key="person-{{ $user->id }}" ref="person-{{ $user->username }}" @press="open('{{ $user->username }}')" class="w-full bg-theme-surface" a11y-label="{{ $user->name }}, {{ $user->handle() }}{{ $person->followsYou && $showsFollowsYou ? ', follows you' : '' }}{{ ($isModerator ?? false) ? ', moderator' : '' }}">
    <native:row class="w-full px-4 py-3 gap-3 items-start">
        <native:stack :width="48" :height="48" center class="rounded-full bg-theme-{{ \App\Devnull\Lane::token($user->lane()) }} flex-shrink-0">
            <native:stack :width="44" :height="44" center class="rounded-full bg-theme-surface">
                @include('native.partials.avatar', ['user' => $user, 'size' => 40])
            </native:stack>
        </native:stack>

        <native:column class="flex-1 gap-[2]">
            @if ($isModerator ?? false)
                <native:row class="w-full gap-1 items-center">
                    <native:text font="semibold" class="text-[15px] text-theme-ink flex-shrink" :max-lines="1">{{ $user->name }}</native:text>
                    <native:text ref="moderator-{{ $user->username }}" class="text-[13px] text-theme-ink-muted flex-shrink-0">(moderator)</native:text>
                </native:row>
            @else
                <native:text font="semibold" class="w-full text-[15px] text-theme-ink" :max-lines="1">{{ $user->name }}</native:text>
            @endif
            {{-- EDGE's rows don't wrap on iOS, so the handle gives way to the badge. --}}
            <native:row class="w-full gap-2 items-center">
                <native:text font="mono" class="text-[13px] text-theme-ink-muted flex-shrink" :max-lines="1">{{ $user->handle() }}</native:text>
                @if ($person->followsYou && $showsFollowsYou)
                    <native:column ref="follows-you-{{ $user->username }}" class="px-2 py-[2] rounded bg-theme-wash flex-shrink-0">
                        <native:text class="text-xs text-theme-ink-muted">Follows you</native:text>
                    </native:column>
                @endif
            </native:row>
            @if ($user->bio)
                <native:text class="w-full mt-1 text-sm text-theme-ink" :max-lines="2">{{ $user->bio }}</native:text>
            @endif
        </native:column>

        @if (! $isMe && ($showsFollow ?? true))
            {{-- A button sizes to its label, so it fills a slot of its own. --}}
            <native:column :width="96" class="flex-shrink-0">
                <native:button ref="follow-{{ $user->username }}" size="sm" :variant="$person->isFollowed ? 'secondary' : $myColour" @press="toggleFollow('{{ $user->username }}')" class="w-full" a11y-hint="{{ $person->isFollowed ? 'Unfollows' : 'Follows' }} {{ $user->handle() }}">{{ $person->isFollowed ? 'Following' : 'Follow' }}</native:button>
            </native:column>
        @endif
    </native:row>
    <native:column class="w-full h-[1] border-b border-theme-hairline" />
</native:pressable>
