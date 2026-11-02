{{--
    A row of the Remotes inbox, as the web draws it: the partner's avatar ringed in their lane, or two of an org's
    people overlapping, the name and handle (or "org · N"), the last message on one line, brighter while it has
    messages you haven't pulled, then on the right the web's pin when you pinned it, and how many are ahead in your
    lane, or when it was last written to. Your own last message in a conversation wears the web's glyph: one dot
    pushed, two joined dots once pulled. A muted row wears a crossed-out bell beside the pin.

    A tap opens the thread, and a long press the web's row menu (see App\Devnull\RemoteMenu).
--}}
@php($id = $conversation->id)
@php($last = $conversation->lastMessage)
@php($muted = $conversation->isMuted())
<native:pressable
    native:key="remote-{{ $id }}"
    ref="remote-{{ $id }}"
    @press="openConversation('{{ $id }}')"
    :context-menu="$menu"
    class="w-full"
    a11y-label="{{ $title }}{{ $conversation->isPinned() ? ', pinned' : '' }}{{ $muted ? ', muted' : '' }}{{ $conversation->ahead > 0 ? ', '.$conversation->ahead.' ahead' : '' }}. {{ $preview }}"
>
    <native:row class="w-full px-4 py-3 gap-3 items-center bg-theme-surface">
        @if ($conversation->isOrg())
            <native:column :width="44" :height="44">
                @foreach (array_reverse(array_slice($others, 0, 2), true) as $index => $member)
                    <native:column class="absolute top-[{{ $index === 0 ? 0 : 12 }}] left-[{{ $index === 0 ? 0 : 12 }}]">
                        <native:stack :width="32" :height="32" center class="rounded-full bg-theme-surface">
                            @include('native.partials.avatar', ['user' => $member, 'size' => 28])
                        </native:stack>
                    </native:column>
                @endforeach
            </native:column>
        @elseif ($conversation->partner)
            <native:stack :width="44" :height="44" center class="rounded-full bg-theme-{{ \App\Devnull\Lane::token($conversation->partner->lane()) }}">
                <native:stack :width="41" :height="41" center class="rounded-full bg-theme-surface">
                    @include('native.partials.avatar', ['user' => $conversation->partner, 'size' => 38])
                </native:stack>
            </native:stack>
        @endif

        <native:column class="flex-1 gap-[2]">
            <native:row class="w-full gap-2 items-center">
                <native:text font="semibold" class="text-[15px] text-theme-ink flex-shrink" :max-lines="1">{{ $title }}</native:text>
                <native:text font="mono" class="text-xs text-theme-ink-muted flex-shrink" :max-lines="1">{{ $conversation->isOrg() ? 'org · '.count($conversation->members) : $conversation->partner?->handle() }}</native:text>
            </native:row>
            <native:row class="w-full gap-[6] items-center">
                @if ($mine && ! $conversation->isOrg() && $last && ! $last->isEvent())
                    @include('native.partials.message-status', [
                        'status' => $conversation->lastPulled() ? 'pulled' : 'pushed',
                        'ref' => 'status-'.$id,
                        'lane' => $viewer?->lane(),
                        'partnerLane' => $conversation->partner?->lane(),
                    ])
                @endif
                @if ($last?->isEvent())
                    <native:text font="mono" class="flex-1 text-xs {{ $conversation->ahead > 0 ? 'text-theme-ink' : 'text-theme-ink-muted' }}" :max-lines="1">{{ $preview }}</native:text>
                @else
                    <native:text class="flex-1 text-sm {{ $conversation->ahead > 0 ? 'text-theme-ink' : 'text-theme-ink-muted' }}" :max-lines="1">{{ $preview }}</native:text>
                @endif
            </native:row>
        </native:column>

        <native:column class="min-w-[56] items-end gap-[2]">
            @if ($conversation->isPinned() || $muted)
                <native:row class="gap-1 items-center">
                    @if ($muted)
                        <native:icon ref="muted-{{ $id }}" :ios="\App\Icons\Lucide::BellOff->ios()" :android="\App\Icons\Lucide::BellOff->android()" :size="12" class="text-theme-ink-muted" />
                    @endif
                    @if ($conversation->isPinned())
                        <native:icon ref="pinned-{{ $id }}" :ios="\App\Icons\Lucide::Pin->ios()" :android="\App\Icons\Lucide::Pin->android()" :size="12" class="text-theme-ink-muted" />
                    @endif
                </native:row>
            @endif
            @if ($conversation->ahead > 0)
                <native:column ref="ahead-{{ $id }}" class="px-2 rounded-full bg-theme-{{ $myColour }}">
                    <native:text font="mono-medium" class="text-[11px] leading-5 text-theme-on-{{ $myColour }}">{{ $conversation->ahead }} ahead</native:text>
                </native:column>
            @else
                <native:text font="mono" class="text-xs text-theme-ink-muted">{{ \App\Devnull\Display::relativeTime($conversation->lastMessageAt) }}</native:text>
            @endif
        </native:column>
    </native:row>
    <native:column class="w-full pl-[72] bg-theme-surface">
        <native:column class="w-full h-[1] bg-theme-hairline" />
    </native:column>
</native:pressable>
