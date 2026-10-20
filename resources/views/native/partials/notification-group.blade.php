{{--
    A group opened in place, as the web expands it: each of its notifications on a compact line behind a 1pt Rail Gray
    guide line, with a 28pt avatar, the name, the icon and time, and the words someone wrote. Each opens its own
    commit, or profile for a follow. Older ones come with "Show older stars"; one that wouldn't load says so in git's
    voice, with Try again.
--}}
@php($noun = $group->groupNoun())
<native:column ref="group-items-{{ $group->id }}" class="w-full mt-2">
    @foreach ($items as $item)
        <native:pressable ref="group-item-{{ $item->id }}" @press="open('{{ $item->id }}')" class="w-full">
            <native:row class="w-full py-2 pl-8 gap-[10] items-start">
                <native:pressable ref="group-actor-{{ $item->id }}" @press="openActor('{{ $item->actor->username }}')" a11y-label="{{ $item->actor->handle() }}'s profile">
                    @include('native.partials.avatar', ['user' => $item->actor, 'size' => 28])
                </native:pressable>
                <native:column class="flex-1 gap-1">
                    <native:row class="w-full min-h-[28] gap-2 items-center">
                        <native:text font="semibold" class="flex-shrink text-[15px] text-theme-ink" :max-lines="1" @press="openActor('{{ $item->actor->username }}')" a11y-label="{{ $item->actor->handle() }}'s profile">{{ $item->actor->name }}</native:text>
                        <native:spacer />
                        @php($itemIcon = \App\Icons\Lucide::forNotification($item->type))
                        <native:icon :ios="$itemIcon->ios($item->isStar())" :android="$itemIcon->android($item->isStar())" :size="16" class="{{ $item->isModeration() ? 'text-theme-ink-muted' : 'text-theme-'.\App\Devnull\Lane::token($item->actor->lane()) }}" />
                        <native:text font="mono" class="text-[13px] text-theme-ink-muted flex-shrink-0">{{ \App\Devnull\Display::relativeTime($item->createdAt) }}</native:text>
                        @if ($item->isFollow() && $item->actorIsFollowed !== null)
                            @include('native.partials.follow-back', ['actor' => $item->actor, 'isFollowing' => $item->actorIsFollowed])
                        @endif
                    </native:row>
                    @if ($item->body !== null && ! $item->isStar() && ! $item->isFollow())
                        <native:text class="w-full text-[15px] leading-relaxed text-theme-ink" :max-lines="6">{{ $item->body }}</native:text>
                    @endif
                </native:column>
            </native:row>
        </native:pressable>
    @endforeach

    @if ($failed)
        <native:row ref="group-failed-{{ $group->id }}" class="w-full py-2 pl-8 gap-3 items-center">
            <native:text font="mono" class="text-[13px] text-theme-ink-muted">fatal: could not load {{ $noun }}</native:text>
            <native:button ref="group-retry-{{ $group->id }}" variant="secondary" size="sm" @press="loadGroup('{{ $group->id }}')">Try again</native:button>
        </native:row>
    @elseif (isset($expandedCursors[$group->id]))
        <native:pressable ref="group-older-{{ $group->id }}" @press="loadGroup('{{ $group->id }}', true)" class="ml-6 mt-1 px-2 py-[2]">
            <native:text font="medium" class="text-[13px] text-theme-primary">Show older {{ $noun }}</native:text>
        </native:pressable>
    @endif

    {{-- The guide line, drawn last so it sits over the rows it runs beside. --}}
    <native:column class="absolute top-0 bottom-2 left-[14] w-[1] bg-theme-rail" />
</native:column>
