{{--
    React and Reply for the message you tapped, right beside its bubble as the web shows them on hover: Reply nearest
    it, React past it, with the web's icons. Every message keeps them, faded out and tucked a few points toward the
    bubble, so a tap fades and slides them into place, and the slot never changes width. Faded out, they take no taps.
--}}
<native:row ref="actions-{{ $key }}" class="w-[64] flex-shrink-0 items-center {{ $mine ? 'justify-end' : 'justify-start' }}">
    <native:row
        native:key="actions-{{ $key }}-buttons"
        ref="actions-{{ $key }}-buttons"
        class="gap-1 items-center {{ $showsActions ? 'opacity-[1]' : 'opacity-[0]' }}"
        :translate-x="$showsActions ? 0 : ($mine ? 8 : -8)"
        animate-duration="180"
        animate-easing="ease-out"
    >
        @foreach ($mine ? ['react', 'reply'] : ['reply', 'react'] as $action)
            <native:pressable ref="{{ $action }}-{{ $key }}" @press="{{ $action === 'reply' ? 'reply' : 'openReactions' }}({{ $message->id }})" a11y-label="{{ $action === 'reply' ? 'Reply' : 'React' }}">
                <native:stack :width="30" :height="30" center class="rounded-full bg-theme-wash">
                    @php($icon = $action === 'reply' ? \App\Icons\Lucide::Reply : \App\Icons\Lucide::SmilePlus)
                    <native:icon :ios="$icon->ios()" :android="$icon->android()" :size="16" class="text-theme-ink-muted" />
                </native:stack>
            </native:pressable>
        @endforeach
    </native:row>
</native:row>
