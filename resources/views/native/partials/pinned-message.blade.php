{{--
    One pinned message in the pinned sheet, as the web's panel lists it: who wrote it and when, Reply, React and, for a
    maintainer, Unpin, which always show; then up to three lines of it with the rest a tap on Show more away, how many
    images it has, and its reactions.
--}}
@php($author = $message->user ?? ($authors[$message->userId] ?? null))
@php($isLong = isset($longPins[$message->id]))
@php($isExpanded = in_array($message->id, $expandedPins, true))
<native:column native:key="{{ $key }}" ref="{{ $key }}" class="w-full px-5 py-3 gap-1 {{ $first ? '' : 'border-t border-theme-hairline' }}">
    <native:row class="w-full gap-2 items-center">
        {{-- Who and when on two lines, so a long name never squeezes out the time beside the actions. --}}
        @if ($author)
            <native:pressable ref="{{ $key }}-author" @press="openProfile('{{ $author->username }}')" class="flex-1" a11y-label="{{ $author->name }}'s profile">
                <native:row class="w-full gap-2 items-center">
                    @include('native.partials.avatar', ['user' => $author, 'size' => 28])
                    <native:column class="flex-1 gap-[1]">
                        <native:text font="semibold" class="text-[13px] text-theme-ink" :max-lines="1">{{ $author->name }}</native:text>
                        <native:text font="mono" class="text-[11px] text-theme-ink-muted" :max-lines="1">{{ $message->createdAt === null ? '' : \App\Devnull\Display::dayLabel($message->createdAt).' · '.$message->time() }}</native:text>
                    </native:column>
                </native:row>
            </native:pressable>
        @else
            <native:text font="mono" class="flex-1 text-[11px] text-theme-ink-muted" :max-lines="1">{{ $message->createdAt === null ? '' : \App\Devnull\Display::dayLabel($message->createdAt).' · '.$message->time() }}</native:text>
        @endif
        {{-- Side by side with no gap: each is a 32-point target around a 16-point icon. --}}
        <native:row ref="{{ $key }}-actions" class="flex-shrink-0 items-center">
            @if ($canPush)
                @foreach (['reply', 'react'] as $action)
                    @php($icon = $action === 'reply' ? \App\Icons\Lucide::Reply : \App\Icons\Lucide::SmilePlus)
                    <native:pressable ref="{{ $key }}-{{ $action }}" @press="{{ $action === 'reply' ? 'reply' : 'openReactions' }}({{ $message->id }})" a11y-label="{{ $action === 'reply' ? 'Reply' : 'React' }}">
                        <native:stack :width="32" :height="32" center>
                            <native:icon :ios="$icon->ios()" :android="$icon->android()" :size="16" class="text-theme-ink-muted" />
                        </native:stack>
                    </native:pressable>
                @endforeach
            @endif
            @if ($canPin)
                <native:pressable ref="{{ $key }}-unpin" @press="unpinMessage({{ $message->id }})" a11y-label="Unpin message">
                    <native:stack :width="32" :height="32" center>
                        <native:icon :ios="\App\Icons\Lucide::PinOff->ios()" :android="\App\Icons\Lucide::PinOff->android()" :size="16" class="text-theme-ink-muted" />
                    </native:stack>
                </native:pressable>
            @endif
        </native:row>
    </native:row>

    @if ($message->body !== '')
        @if ($isLong && ! $isExpanded)
            <native:text :text="$message->body" class="w-full text-[15px] leading-snug text-theme-ink whitespace-pre-wrap" :max-lines="\App\NativeComponents\Conversation::PinnedLines" />
        @else
            <native:column class="w-full">
                @include('native.partials.message-body', ['ink' => 'text-theme-ink', 'mentionInk' => 'text-theme-primary', 'quoteRule' => 'border-theme-lane-5'])
            </native:column>
        @endif
        @if ($isLong)
            <native:pressable ref="{{ $key }}-more" @press="togglePinnedText({{ $message->id }})">
                <native:text font="medium" class="text-[13px] text-theme-primary">{{ $isExpanded ? 'Show less' : 'Show more' }}</native:text>
            </native:pressable>
        @endif
    @endif

    @if ($message->images !== [])
        <native:row ref="{{ $key }}-images" class="gap-1 items-center">
            <native:icon :ios="\App\Icons\Lucide::Image->ios()" :android="\App\Icons\Lucide::Image->android()" :size="14" class="text-theme-ink-muted" />
            <native:text class="text-[13px] text-theme-ink-muted">{{ count($message->images) > 1 ? count($message->images).' images' : 'Image' }}</native:text>
        </native:row>
    @endif

    @include('native.partials.message-reactions')
</native:column>
