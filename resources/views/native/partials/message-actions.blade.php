{{--
    React, Reply and Pin for the message you swiped, right beside its bubble as the web shows them on hover: Pin nearest
    it, then Reply, then React, with the web's icons. Every message keeps them, faded out and tucked a few points toward
    the bubble, so a swipe fades and slides them into place, and the slot never changes width. In an org the slot keeps
    room for Pin whether it shows or not, as the web's does, so a pin arriving never rewraps a bubble. Faded out, they
    take no taps.

    A pinned message's pin shows all the time, nearest the bubble, so the others come in beyond it without moving it.
    For an org's maintainers it turns into Unpin, in the primary colour, while the others show; for everyone else it
    only marks the message, as the web's does.
--}}
@php($isPinned = isset($pinnedIds[$message->id]))
@php($pinsHere = ! $isPinned && $canPin)
@php($fading = $mine ? ['react', 'reply', ...($pinsHere ? ['pin'] : [])] : [...($pinsHere ? ['pin'] : []), 'reply', 'react'])
<native:row ref="actions-{{ $key }}" class="{{ $isOrg ? 'w-[98]' : 'w-[64]' }} flex-shrink-0 gap-1 items-center {{ $mine ? 'justify-end' : 'justify-start' }}">
    @if ($isPinned && ! $mine)
        @include('native.partials.message-pin')
    @endif
    <native:row
        native:key="actions-{{ $key }}-buttons"
        ref="actions-{{ $key }}-buttons"
        class="gap-1 items-center {{ $showsActions ? 'opacity-[1]' : 'opacity-[0]' }}"
        :translate-x="$showsActions ? 0 : ($mine ? 8 : -8)"
        animate-duration="180"
        animate-easing="ease-out"
    >
        @foreach ($fading as $action)
            @php($press = match ($action) { 'reply' => 'reply', 'react' => 'openReactions', 'pin' => 'togglePin' })
            @php($icon = match ($action) { 'reply' => \App\Icons\Lucide::Reply, 'react' => \App\Icons\Lucide::SmilePlus, 'pin' => \App\Icons\Lucide::Pin })
            <native:pressable ref="{{ $action }}-{{ $key }}" @press="{{ $press }}({{ $message->id }})" a11y-label="{{ match ($action) { 'reply' => 'Reply', 'react' => 'React', 'pin' => 'Pin message' } }}">
                <native:stack :width="30" :height="30" center class="rounded-full bg-theme-wash">
                    <native:icon :ios="$icon->ios()" :android="$icon->android()" :size="16" class="text-theme-ink-muted" />
                </native:stack>
            </native:pressable>
        @endforeach
    </native:row>
    @if ($isPinned && $mine)
        @include('native.partials.message-pin')
    @endif
</native:row>
