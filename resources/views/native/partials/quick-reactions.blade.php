{{--
    React's bubble: the web's quick reactions for $message, yours tinted in your lane, and More for the phone's emoji
    picker. It points at the React button it's laid over ($anchor places it there) and opens above it unless there's no
    room. A tap outside closes it.
--}}
<native:popover ref="reactions-bubble" class="{{ $anchor }}" :visible="true" @dismiss="reactionsClosed" a11y-label="Reactions">
    <native:row ref="quick-reactions" class="gap-2 p-2 items-center">
        @foreach ($quickReactions as $emoji)
            <native:pressable ref="quick-{{ $loop->index }}" @press="toggleReaction({{ $message->id }}, '{{ $emoji }}')" a11y-label="React with {{ $emoji }}">
                <native:stack :width="44" :height="44" center class="rounded-full {{ $message->hasReacted($viewer?->id, $emoji) ? 'bg-theme-'.$myColour.'/20' : 'bg-theme-wash' }}">
                    <native:text class="text-[24px]">{{ $emoji }}</native:text>
                </native:stack>
            </native:pressable>
        @endforeach
        <native:pressable ref="more-reactions" @press="openEmojiPicker({{ $message->id }})" a11y-label="More reactions">
            <native:stack :width="44" :height="44" center class="rounded-full bg-theme-wash">
                <native:icon :ios="\App\Icons\Lucide::SmilePlus->ios()" :android="\App\Icons\Lucide::SmilePlus->android()" :size="22" class="text-theme-ink-muted" />
            </native:stack>
        </native:pressable>
    </native:row>
</native:popover>
