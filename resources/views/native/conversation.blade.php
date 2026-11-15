{{--
    A Remotes thread: the messages, oldest first, anchored to the latest at the bottom as a chat is, then the
    composer in the bottom bar, with photos, voice notes and Ping. Who's typing shows under the title in the nav bar, so the bar never changes height
    for it. While the thread can't take a message, the bar says why instead, in the web's words, with Merge and Drop
    for a request. An org's pinned messages lie in a bar over the top of the thread.
--}}
<native:column class="w-full h-full bg-theme-background">
    @include('native.partials.error')
    @include('native.partials.refresh-failed')

    {{--
        Each row is a child of the scroll view itself, not of one column inside it: SwiftUI keeps a reader's place by
        the rows on screen, and with everything in a single row it held onto the bottom instead, so a new message
        pulled someone reading back down to it. The stack around it is always there, so a pin coming or going never
        rebuilds the thread under you.
    --}}
    <native:stack ref="thread-stack" class="w-full flex-1">
    <native:scroll-view native:key="thread-{{ $username }}{{ $org }}" ref="thread" scroll-anchor="bottom" class="w-full h-full bg-theme-surface">
        {{-- The pinned bar lies over the top, so the oldest message can still scroll clear of it. --}}
        @if ($pinned !== [])
            <native:column ref="pinned-clearance" class="w-full h-[37]" />
        @endif

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

    @if ($pinned !== [])
        @include('native.partials.pinned-bar')
    @endif
    </native:stack>

    @if ($loaded)
        {{--
            The composer: one rounded box with the field over a row holding the + and the round send button (see
            partials/composer-box), on a bar that is Liquid Glass on iOS, tinted with the surface so the thread
            scrolling under it is blurred out of the way, and the solid surface with a hairline on Android. The web's
            tools, photos, the microphone and Ping, open from the one +. Above the box sit the people to mention, what
            you're replying to and the photos on the draft. While the thread can't take a message, the bar is solid
            on both, with why in the box's place.
        --}}
        <native:bottom-bar>
            <native:column native:key="composer-bar" ref="composer-bar" class="w-full {{ $canPush ? 'android:bg-theme-surface ios:bg-theme-surface/70 ios:glass' : 'bg-theme-surface' }}">
                <native:column class="w-full h-[1] {{ $canPush ? 'android:bg-theme-hairline' : 'bg-theme-hairline' }}" />
                @if ($canPush)
                    {{--
                        The members to mention, what you're replying to and the photos on the draft each keep a slot,
                        empty and no height when there's nothing in it, so the field's row never moves. Android rebuilds
                        a row that moves among its siblings, field and all, which drops the keyboard. An empty slot is
                        h-[0.1]: on iOS an empty one otherwise takes 10 points of the bar, and EDGE drops a height of 0.
                    --}}
                    @php($mentioning = ($mention['field'] ?? null) === 'draft' && ($mentionPeople !== [] || $mentionOrganizations !== []))
                    <native:row native:key="mention-slot" ref="mention-slot" class="w-full {{ $mentioning ? 'pt-1 ios:px-4' : 'h-[0.1]' }}">
                        @include('native.partials.mention-suggestions', ['field' => 'draft', 'heading' => ($mention['query'] ?? null) === '' ? 'Members' : null])
                    </native:row>
                    <native:row native:key="replying-slot" ref="replying-slot" class="w-full {{ $replyTo ? 'ios:px-4' : 'h-[0.1]' }}">
                        @if ($replyTo)
                            <native:row ref="replying" class="flex-1 h-[44] px-4 gap-3 items-center ios:rounded-[18] ios:glass">
                                <native:column class="w-[2] h-[28] rounded-full bg-theme-{{ $myColour }}" />
                                <native:column class="flex-1 gap-[2]">
                                    <native:text font="medium" class="text-xs text-theme-ink">Replying to {{ $replyTo->userId === $viewer?->id ? 'yourself' : ($authors[$replyTo->userId]->name ?? 'Someone') }}</native:text>
                                    <native:text class="text-xs text-theme-ink-muted" :max-lines="1">{{ $replyTo->body !== '' ? $replyTo->body : $replyTo->snippet() }}</native:text>
                                </native:column>
                                <native:pressable ref="cancel-reply" @press="cancelReply" a11y-label="Cancel reply">
                                    <native:icon :ios="\App\Icons\Lucide::X->ios()" :android="\App\Icons\Lucide::X->android()" :size="16" class="text-theme-ink-muted" />
                                </native:pressable>
                            </native:row>
                        @endif
                    </native:row>
                    <native:column native:key="message-photos" ref="message-photos" class="w-full {{ $photos !== [] ? 'px-4 pt-2' : 'h-[0.1]' }}">
                        @include('native.partials.composer-photos', ['photos' => $photos, 'photoRef' => 'photo', 'describeClass' => 'ios:glass'])
                    </native:column>
                    {{--
                        The box keeps its row, and the row its place among the bar's, so the field inside never moves.
                        A voice note takes the field's place, and the +'s, while it's recorded and waits to be pushed.
                    --}}
                    <native:row native:key="message-row" ref="message-row" class="w-full px-4 py-2">
                        @include('native.partials.composer-box', [
                            'ref' => 'message',
                            'field' => $recorder === null ? 'native.partials.message-field' : 'native.partials.voice-recorder',
                            'tools' => $recorder !== null ? [] : [
                                ['ref' => 'composer-tools', 'icon' => \App\Icons\Lucide::Plus, 'label' => 'Photos, a voice note or a ping', 'menu' => \App\Devnull\ComposerMenu::items(count($photos) >= \App\Devnull\DraftImages::MaxImages, trim($draft) !== '' || $photos !== [], $pingLabel)],
                            ],
                            'counter' => ['native.partials.message-length'],
                            'send' => [
                                'ref' => 'push',
                                'label' => 'Push',
                                'press' => 'send',
                                'disabled' => $recorder === null ? trim($draft) === '' && $photos === [] : $recorder === 'stopping' || ($recorder === 'recording' && ! $recordingStarted),
                            ],
                        ])
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

    {{-- An org's pinned messages. --}}
    <native:bottom-sheet ref="message-sheet" :visible="$sheetOpen" detents="medium,large" @dismiss="messageSheetClosed">
        @if ($pinned !== [])
            @include('native.partials.pinned-sheet')
        @endif
    </native:bottom-sheet>

    @include('native.partials.mute-sheet')

    {{-- More reactions, from the bubble or anywhere else: the emoji keyboard on iOS, Jetpack's emoji picker on Android. --}}
    <native:emoji-picker ref="emoji-picker" :visible="$emojiPickerFor !== null" @change="emojiPicked" @dismiss="emojiPickerClosed" />
</native:column>
