{{--
    One message in a thread, as the web draws it: yours on the right in your lane, everyone else's on the left on
    the wash. In an org, a run of someone else's messages starts with their avatar and name, a tap on which opens their profile. Above the bubble, the
    ghost of the message it replies to; beside it its images and link card; under it its reactions, one chip per
    emoji, yours outlined in your lane and a tap to take it back or add yours. A run ends in the time, or for the
    message the web gives a status, in that status.

    Dragging it toward its edge slides it out of the way with the finger, partly off the screen, and React, Reply and,
    for an org's maintainers, Pin grow in one after another where it was, as the web shows them on hover: someone else's
    to the left, your own to the right. Let go past halfway, or with a flick, and it stays open; short of that it springs
    back. The actions sit in a slot the bubble always keeps beside it, so nothing rewraps. Dragging it back, or a tap
    anywhere in the thread, puts them away; a double tap toggles a heart. A pinned message keeps its pin beside it. A message still being pushed takes
    none of them, and nothing reacts, replies or pins while the thread can't take a message.

    A long press on the bubble, the voice note or the images opens the message's menu (see App\Devnull\MessageMenu),
    which lifts it out of the thread on iOS: Reply, React and Copy text, and Pin for an org's maintainers. The menu goes
    on a pressable around the drawn bubble, so the lifted copy keeps the bubble's colour and padding.
--}}
@php($key = $message->id > 0 ? $message->id : $message->clientId)
@php($laneToken = \App\Devnull\Lane::token(($author ?? null)?->lane()))
@php($replyAuthor = $message->replyTo === null ? null : ($message->replyTo['user_id'] === $viewer?->id ? 'You' : ($authors[$message->replyTo['user_id']]->name ?? 'Someone')))
@php($replyLane = $message->replyTo === null ? 'primary' : \App\Devnull\Lane::token(($authors[$message->replyTo['user_id']] ?? null)?->lane()))
@php($replyable = ($canPush ?? false) && ! $message->isPending())
@php($showsActions = $replyable && $actionsForId === $message->id)
{{-- The row only takes a tap while some message's actions show, so a tap beside a bubble draws no ripple otherwise. --}}
@if ($actionsForId !== null)
<native:row native:key="message-{{ $key }}" ref="message-{{ $key }}" class="w-full px-3 {{ $startsRun ? 'pt-3' : 'pt-[3]' }}" @press="hideActions">
@else
<native:row native:key="message-{{ $key }}" ref="message-{{ $key }}" class="w-full px-3 {{ $startsRun ? 'pt-3' : 'pt-[3]' }}">
@endif
    @if ($replyable)
        <native:gesture-area ref="swipe-{{ $key }}" :pan-x="$pan" :snap-x="$mine ? [0, $slide] : [-$slide, 0]" @dragEnd="draggedMessage({{ $message->id }})" class="flex-1">
    @endif
    {{-- A set width, not a max: a column that only hugs its bubble measures the text on one line and clips it. --}}
    <native:column class="{{ $replyable ? 'w-full' : 'flex-1' }} gap-1 {{ $mine ? 'items-end' : 'items-start' }}">
        @if ($isOrg && ! $mine && $startsRun && $author)
            <native:pressable ref="author-{{ $key }}" @press="openProfile('{{ $author->username }}')" a11y-label="{{ $author->name }}'s profile">
                <native:row class="gap-[6] items-center px-1">
                    @include('native.partials.avatar', ['user' => $author, 'size' => 16])
                    <native:text font="medium" class="text-xs text-theme-{{ $laneToken }}" :max-lines="1">{{ $author->name }}</native:text>
                </native:row>
            </native:pressable>
        @endif

        @if ($message->replyTo !== null)
            <native:column ref="reply-ghost-{{ $key }}" class="max-w-[260] px-3 py-[6] rounded-xl border border-theme-hairline bg-theme-surface gap-[2]" a11y-label="Replying to {{ $replyAuthor }}: {{ $message->replySnippet() }}">
                <native:text font="medium" class="text-xs text-theme-{{ $replyLane }}">{{ $replyAuthor }}</native:text>
                <native:text class="text-xs text-theme-ink-muted" :max-lines="1">{{ $message->replySnippet() }}</native:text>
            </native:column>
        @endif

        {{-- An image-only message keeps its actions beside its images; otherwise they go beside the bubble. --}}
        @if ($message->images !== [] && $message->body === '')
            <native:row ref="content-{{ $key }}" class="w-full gap-1 items-center {{ $mine ? 'justify-end' : 'justify-start' }}" :translate-x="$pan ?? 0">
                @if ($mine)
                    @include('native.partials.message-actions')
                @endif
                @include('native.partials.message-images', ['photosMenu' => $menu])
                @if (! $mine)
                    @include('native.partials.message-actions')
                @endif
            </native:row>
        @else
            @include('native.partials.message-images')
        @endif

        @include('native.partials.message-voice')

        @if ($message->body !== '')
            {{-- The bubble shrinks to wrap short of the slot for its actions, which keeps its width either way. --}}
            <native:row ref="content-{{ $key }}" class="w-full gap-1 items-center {{ $mine ? 'justify-end' : 'justify-start' }}" :translate-x="$pan ?? 0">
                @if ($mine)
                    @include('native.partials.message-actions')
                @endif
                @if ($message->isPending())
                    <native:pressable ref="bubble-{{ $key }}" class="flex-shrink" :context-menu="$menu">
                        <native:column class="px-3 py-2 rounded-2xl {{ $message->status === 'rejected' ? 'border border-theme-destructive bg-theme-surface' : 'bg-theme-'.$myColour.'/60' }}">
                            @include('native.partials.message-body', ['ink' => $message->status === 'rejected' ? 'text-theme-ink' : 'text-theme-on-'.$myColour, 'mentionInk' => 'text-theme-on-'.$myColour, 'quoteRule' => $message->status === 'rejected' ? 'border-theme-lane-5' : 'border-theme-on-'.$myColour])
                        </native:column>
                    </native:pressable>
                @else
                    <native:pressable ref="bubble-{{ $key }}" class="flex-shrink" @press="hideActions" @doubleTap="heart({{ $message->id }})" :context-menu="$menu" a11y-hint="{{ $replyable ? 'Swipe sideways for React and Reply' : '' }}">
                        <native:column class="px-3 py-2 rounded-2xl {{ $mine ? 'bg-theme-'.$myColour : 'bg-theme-wash' }}">
                            @include('native.partials.message-body', ['ink' => $mine ? 'text-theme-on-'.$myColour : 'text-theme-ink', 'mentionInk' => $mine ? 'text-theme-on-'.$myColour : 'text-theme-primary', 'quoteRule' => $mine ? 'border-theme-on-'.$myColour : 'border-theme-lane-5'])
                        </native:column>
                    </native:pressable>
                @endif
                @if (! $mine)
                    @include('native.partials.message-actions')
                @endif
            </native:row>
        @endif

        @if ($message->linkPreview?->isReady())
            <native:pressable ref="link-{{ $key }}" @press="openLink({{ $message->id }})" class="max-w-[260]" a11y-label="{{ $message->linkPreview->title }}, {{ $message->linkPreview->site() }}">
                <native:column class="w-full px-3 py-2 rounded-xl border border-theme-hairline bg-theme-surface gap-[2]">
                    <native:text font="mono" class="text-[11px] text-theme-ink-muted" :max-lines="1">{{ $message->linkPreview->site() }}</native:text>
                    <native:text font="medium" class="text-sm text-theme-ink" :max-lines="2">{{ $message->linkPreview->title }}</native:text>
                </native:column>
            </native:pressable>
        @endif

        @include('native.partials.message-reactions')

        @if ($status !== null)
            <native:row ref="status-{{ $key }}" class="gap-[6] items-center px-1">
                @if ($mine)
                    @include('native.partials.message-status', ['status' => $status['state'], 'ref' => 'glyph-'.$key, 'lane' => $viewer?->lane(), 'partnerLane' => $partner?->lane()])
                @endif
                @if ($message->status === 'rejected')
                    <native:text font="mono" class="text-[11px] text-theme-destructive">rejected ·</native:text>
                    <native:text ref="retry-{{ $key }}" font="mono-medium" class="text-[11px] text-theme-destructive" @press="retry('{{ $message->clientId }}')">retry</native:text>
                @else
                    <native:text font="mono" class="text-[11px] text-theme-ink-muted">{{ $status['text'] }}</native:text>
                @endif
            </native:row>
        @elseif ($endsRun)
            <native:text font="mono" class="px-1 text-[11px] text-theme-ink-muted">{{ $message->time() }}</native:text>
        @endif
    </native:column>
    @if ($replyable)
        </native:gesture-area>
    @endif
</native:row>
