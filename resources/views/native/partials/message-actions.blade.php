{{--
    React and Reply for the message you tapped, as the web shows them beside a bubble on hover: Reply nearest it, React
    past it, with the web's icons. The gutter keeps its width for every message, so they come and go without moving
    anything.
--}}
<native:row ref="actions-{{ $key }}" class="w-[64] gap-1 items-center {{ $mine ? 'justify-end' : 'justify-start' }}">
    @if ($showsActions)
        @foreach ($mine ? ['react', 'reply'] : ['reply', 'react'] as $action)
            <native:pressable ref="{{ $action }}-{{ $key }}" @press="{{ $action === 'reply' ? 'reply' : 'openReactions' }}({{ $message->id }})" a11y-label="{{ $action === 'reply' ? 'Reply' : 'React' }}">
                <native:stack :width="30" :height="30" center class="rounded-full bg-theme-wash">
                    @php($icon = $action === 'reply' ? \App\Icons\Lucide::Reply : \App\Icons\Lucide::SmilePlus)
                    <native:icon :ios="$icon->ios()" :android="$icon->android()" :size="16" class="text-theme-ink-muted" />
                </native:stack>
            </native:pressable>
        @endforeach
    @endif
</native:row>
