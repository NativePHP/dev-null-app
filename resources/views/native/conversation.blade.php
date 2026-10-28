{{--
    A Remotes thread: the messages, oldest first, anchored to the latest at the bottom as a chat is, then the
    composer in the bottom bar. Who's typing shows under the title in the nav bar, so the bar never changes height
    for it. While the thread can't take a message, the bar says why instead, in the web's words, with Merge and Drop
    for a request.
--}}
<native:column class="w-full h-full bg-theme-background">
    @include('native.partials.error')
    @include('native.partials.refresh-failed')

    {{--
        Each row is a child of the scroll view itself, not of one column inside it: SwiftUI keeps a reader's place by
        the rows on screen, and with everything in a single row it held onto the bottom instead, so a new message
        pulled someone reading back down to it.
    --}}
    <native:scroll-view native:key="thread-{{ $username }}{{ $org }}" ref="thread" scroll-anchor="bottom" class="w-full flex-1 bg-theme-surface">
        @if ($hasOlder)
            <native:row class="w-full py-3 justify-center">
                <native:text ref="load-older" font="mono" class="text-[13px] text-theme-primary" @press="loadOlder">↑ earlier messages</native:text>
            </native:row>
        @endif

        @if (! $loaded && $error === null)
            <native:column ref="loading-thread" class="w-full items-center py-16" a11y-label="Loading messages">
                <native:activity-indicator />
            </native:column>
        @endif

        @if ($loaded && $items === [] && $canPush)
            <native:column ref="empty-thread" class="w-full items-center px-8 py-16">
                <native:text font="mono" class="text-[13px] text-center text-theme-ink-muted">{{ $isOrg ? 'empty history. say hi to the org' : 'empty history. say hi to @'.$username }}</native:text>
            </native:column>
        @endif

        @foreach ($items as $item)
            @if ($item['type'] === 'day')
                <native:row native:key="day-{{ $item['label'] }}" ref="day-{{ $item['label'] }}" class="w-full pt-4 pb-1 justify-center">
                    <native:row class="px-3 py-[2] gap-[6] items-center rounded-full bg-theme-wash">
                        <native:column :width="4" :height="4" class="rounded-full bg-theme-ink-muted" />
                        <native:text font="mono" class="text-[11px] text-theme-ink-muted">{{ $item['label'] }}</native:text>
                    </native:row>
                </native:row>
            @elseif ($item['type'] === 'head')
                <native:row ref="head" class="w-full px-4 pt-4 gap-2 items-center">
                    <native:column class="flex-1 h-[1] bg-theme-{{ $myColour }}/40" />
                    <native:text font="mono-medium" class="text-[11px] text-theme-{{ $myColour }}">HEAD · {{ $item['count'] }} new</native:text>
                    <native:column class="flex-1 h-[1] bg-theme-{{ $myColour }}/40" />
                </native:row>
            @elseif ($item['type'] === 'event')
                <native:row native:key="event-{{ $item['message']->id }}" ref="event-{{ $item['message']->id }}" class="w-full px-6 pt-3 justify-center">
                    <native:text font="mono" class="text-[11px] text-center text-theme-ink-muted" :max-lines="2">* {{ $item['text'] }}</native:text>
                </native:row>
            @else
                @include('native.partials.message-item', $item)
            @endif
        @endforeach

        {{-- The bar sits over the end of the thread, so the thread clears it. The scroll view clears the home indicator. --}}
        <native:column ref="composer-clearance" class="w-full h-[{{ $composerClearance }}]" />
    </native:scroll-view>

    @if ($loaded)
        <native:bottom-bar>
            <native:column ref="composer-bar" class="w-full bg-theme-surface">
                <native:column class="w-full h-[1] bg-theme-hairline" />
                @if ($canPush)
                    @if ($replyTo)
                        <native:row ref="replying" class="w-full h-[44] px-4 gap-3 items-center">
                            <native:column class="w-[2] h-[28] rounded-full bg-theme-{{ $myColour }}" />
                            <native:column class="flex-1 gap-[2]">
                                <native:text font="medium" class="text-xs text-theme-ink">Replying to {{ $replyTo->userId === $viewer?->id ? 'yourself' : ($authors[$replyTo->userId]->name ?? 'Someone') }}</native:text>
                                <native:text class="text-xs text-theme-ink-muted" :max-lines="1">{{ $replyTo->body !== '' ? $replyTo->body : 'Image' }}</native:text>
                            </native:column>
                            <native:pressable ref="cancel-reply" @press="cancelReply" a11y-label="Cancel reply">
                                <native:icon :ios="\App\Icons\Lucide::X->ios()" :android="\App\Icons\Lucide::X->android()" :size="16" class="text-theme-ink-muted" />
                            </native:pressable>
                        </native:row>
                    @endif
                    {{-- The same room above and below the field, so the bar sits evenly on the keyboard. --}}
                    <native:row class="w-full px-4 py-2 gap-3 items-center">
                        <native:column class="flex-1">
                            <native:bare-text-input
                                native:key="message-input-{{ $composerKey }}"
                                ref="message-input"
                                class="w-full py-[4] text-theme-ink"
                                :autofocus="$composerKey > 0"
                                :placeholder="$placeholder"
                                native:model.debounce.150ms="draft"
                                multiline
                                :max-lines="5"
                                :max-length="\App\Devnull\Data\Message::MaxLength"
                                a11y-label="Message"
                            />
                        </native:column>
                        @php($length = mb_strlen($draft))
                        @if ($length >= 1600)
                            <native:text ref="message-length" font="mono" class="text-[11px] {{ $length >= 2000 ? 'text-theme-destructive' : ($length >= 1900 ? 'text-theme-pending' : 'text-theme-ink-muted') }}">{{ number_format($length) }}/2,000</native:text>
                        @endif
                        <native:column class="w-[72]">
                            <native:button ref="push" :variant="$myColour" size="sm" :disabled="trim($draft) === ''" @press="send" class="w-full">Push</native:button>
                        </native:column>
                    </native:row>
                @else
                    <native:column ref="access-bar" class="w-full px-4 pt-3 pb-4 gap-3">
                        <native:text ref="access" class="w-full text-sm leading-relaxed text-theme-ink-muted">{{ match ($access) {
                            'request' => "@{$username} wants to push to you. Merge to reply, or drop it. They won't be told.",
                            'waiting' => "pushed. waiting for @{$username} to merge your request",
                            'restricted' => "@{$username} doesn't follow you, so you can't push to them yet",
                            default => "@{$username} isn't taking remotes",
                        } }}</native:text>
                        @if ($access === 'request')
                            <native:row class="w-full gap-3">
                                <native:column class="flex-1">
                                    <native:button ref="drop" variant="secondary" size="lg" @press="drop" class="w-full">Drop</native:button>
                                </native:column>
                                <native:column class="flex-1">
                                    <native:button ref="merge" :variant="$myColour" size="lg" @press="merge" class="w-full">Merge</native:button>
                                </native:column>
                            </native:row>
                            @if ($requestError)
                                <native:text ref="request-error" class="text-sm text-theme-destructive">{{ $requestError }}</native:text>
                            @endif
                        @endif
                    </native:column>
                @endif
            </native:column>
        </native:bottom-bar>
    @endif

    {{-- React on a message: the reactions the web offers first, then the rest. --}}
    <native:bottom-sheet ref="message-sheet" :visible="$sheetOpen" detents="0.45" @dismiss="messageSheetClosed">
        @if ($sheetMessage)
            <native:column class="w-full px-5 pt-6 pb-8 gap-5">
                <native:row ref="quick-reactions" class="w-full gap-3 justify-center">
                    @foreach ($quickReactions as $emoji)
                        <native:pressable ref="quick-{{ $loop->index }}" @press="toggleReaction({{ $sheetMessage->id }}, '{{ $emoji }}')" a11y-label="React with {{ $emoji }}">
                            <native:stack :width="52" :height="52" center class="rounded-full {{ $sheetMessage->hasReacted($viewer?->id, $emoji) ? 'bg-theme-'.$myColour.'/20' : 'bg-theme-wash' }}">
                                <native:text class="text-[26px]">{{ $emoji }}</native:text>
                            </native:stack>
                        </native:pressable>
                    @endforeach
                </native:row>
                {{-- EDGE's rows don't wrap on iOS, so the rest come in rows of eight. --}}
                <native:column ref="more-reactions" class="w-full gap-1 items-center">
                    @foreach (array_chunk($moreReactions, 8) as $row)
                        <native:row class="gap-1">
                            @foreach ($row as $emoji)
                                <native:pressable ref="more-{{ $loop->parent->index * 8 + $loop->index }}" @press="toggleReaction({{ $sheetMessage->id }}, '{{ $emoji }}')" a11y-label="React with {{ $emoji }}">
                                    <native:stack :width="40" :height="40" center class="rounded-full {{ $sheetMessage->hasReacted($viewer?->id, $emoji) ? 'bg-theme-'.$myColour.'/20' : 'bg-theme-surface' }}">
                                        <native:text class="text-[22px]">{{ $emoji }}</native:text>
                                    </native:stack>
                                </native:pressable>
                            @endforeach
                        </native:row>
                    @endforeach
                </native:column>
            </native:column>
        @endif
    </native:bottom-sheet>

    @include('native.partials.mute-sheet')
</native:column>
