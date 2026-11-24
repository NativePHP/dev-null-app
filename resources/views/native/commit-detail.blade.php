<native:column class="w-full h-full bg-theme-background">
    @include('native.partials.error')
    @include('native.partials.refresh-failed')
    @include('native.partials.commit-sheet')
    @include('native.partials.live-stream-sheet')
    @include('native.partials.stargazers-sheet')

    {{-- The stack around the page holds the fade over its foot, just above the reply bar (see partials/composer-fade). --}}
    <native:stack ref="thread-stack" class="w-full flex-1">
    {{--
        Each row is a child of the scroll view itself, so it can bring one into view: the commit the page is on, after
        tapping another commit in the tree moves the page to it (see CommitDetail::refocus() and $scrollTarget). Every
        move animates (auto-scroll-animated), and $scrollRequest as the token makes asking for the same row again scroll
        again. With animate-layout the rows the page keeps slide to their new places, and the ones it adds or drops
        fade. That needs the scroll view's key, and each row's, to stay the same across the move: the focused commit
        is keyed commit-<id>, as the commits above it are. These need NativePHP/mobile-air#736 and
        NativePHP/mobile-ui#225; without them the move jumps and a repeat of the same row doesn't scroll.
    --}}
    <native:scroll-view native:key="thread-{{ $scrollKey }}" ref="thread-scroll" class="w-full h-full bg-theme-surface" :auto-scroll-to="$scrollTarget" :auto-scroll-token="$scrollRequest" auto-scroll-animated animate-layout>
            @if (! $commit && $error === null)
                <native:column ref="loading-commit" class="w-full items-center py-16" a11y-label="Loading commit">
                    <native:activity-indicator />
                </native:column>
            @endif

            {{--
                The commit is the main thing, near the top: larger, in full ink, with its git date and its replies behind
                a toggle in its action row. The commit it replies to shows above it, and what that replies to waits
                behind "↑ N earlier commits", since EDGE can't open the page scrolled down to the commit. Opened, the
                ancestors, the commit and the rest of its series share one rail, so someone replying to themselves shows
                their avatar once. The replies hang under it on the thread's dashed lines, with the rail ending in a dot
                above them, ten at a time with "Show more" under the last. A part of a series with more parts after it
                has its replies right under it, beside the rail running on, and the later parts follow. Everything
                around the commit is muted, and the commits above it open their other replies in place under them, as the
                commit does, from their "N replies", which starts folded, even after the page moves. Tapping any commit in the tree moves the page to it rather than pushing
                another (see CommitDetail::refocus()). Every reply's own replies open in place under
                it, ten at a time too, as deep as they go, as on the web: they step in up to the web's depth and line up
                under the deepest step past it, saying who they answer (see App\Devnull\ThreadRows).

                A thread its author wrote as replies to themselves carries on below the commit, on the same rail, as
                the web reads it (see SelfThread): each part wears its place ("2/5"), ten show at a time with "Show
                more of this thread" under them, and the replies of the last part hang under it as the commit's do.
            --}}
            {{--
                The rows down to the end of the thread come from CommitDetail::pageRows(), each one child of the scroll
                view, in the order it lists them, so the place it gives a row is the row auto-scroll-to brings into
                view: the reply you just sent, say (see CommitDetail::scrollToRow()). A row drawn here and not there
                would put every row below it one out. Related commits start with a full-width line of their own (see
                partials/related-commits), so the last row above them leaves out its hairline, which runs from the
                text's edge, and the two don't stack up into a line and a half. Replies end with no hairline on a
                commit page, and "No replies yet" keeps the commit's.
            --}}
            @php($noReplies = $commit && $replyCount === 0 && $repliesLoaded && $series === [] && $threadParts === [])

            @foreach ($pageRows as $pageRow)
                @if ($pageRow['key'] === 'loading-replies')
                    <native:row ref="loading-replies" class="w-full pl-[64] pr-4 py-4 gap-2 items-center" a11y-label="Loading replies">
                        <native:activity-indicator size="small" />
                    </native:row>
                @elseif ($pageRow['reply'] === null)
                    @include($pageRow['partial'], $pageRow['data'])
                @else
                    @php($rowScope = array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))
                    @php($replyRows->include('reply-'.$pageRow['reply']->id, \App\Devnull\RowInputs::for($pageRow['reply'], $rowScope), $pageRow['partial'], $pageRow['data'], $rowScope))
                @endif
            @endforeach

            @if ($threadMore && $threadParts !== [])
                @include('native.partials.thread-continues', ['lane' => $rows[array_key_last($rows)]->commit->lane()])
            @endif

            @if ($noReplies)
                <native:column class="w-full pl-[64] pr-4 py-4">
                    <native:text class="text-sm text-theme-ink-muted">No replies yet.</native:text>
                </native:column>
            @endif

            @if ($commit && $related !== [])
                @include('native.partials.related-commits')
            @endif

            {{--
                A pushed screen runs under its bottom bar, so the page clears the reply bar itself (see
                Display::replyBarClearance): the last reply and its actions end above the fade and the bar, keyboard
                up or down.
            --}}
            @if ($commit)
                <native:column ref="reply-bar-clearance" class="w-full h-[{{ 24 + \App\Devnull\Display::replyBarClearance($reply, $replyImages !== [] && ! $replyPreviewing, $describing !== null) }}]" />
            @endif
    </native:scroll-view>

    @if ($commit && ! \App\Devnull\Display::fadesInBar())
        @include('native.partials.composer-fade', ['ref' => 'reply-fade'])
    @endif
    </native:stack>

    @if ($commit)
        {{--
            The reply bar, drawn as the Remotes composer is: the field across the top and a row under it with the
            tools on the left and the round send button on the right (see partials/composer-box), with the page fading
            out above it (see partials/composer-fade). On iOS the bar is Liquid Glass, faded in at the top, so the page
            shows through it blurred (see Display::composerBarGlass()). On Android it's the surface, painted from this
            column. Either fills the strip under the home indicator.

            The bar does everything the composer sheet did for a reply, so a reply never needs the sheet, whose
            presenting takes the keyboard down and back up on both platforms. The photo button adds photos from the
            library or the camera, which show above the field with their ✕ and ALT. Preview shows the reply as it will
            look in the field's place, and pressed again brings the field back. Beside the send button, drop throws
            the draft away whenever there's anything in it, after the web's stash@{0} when the draft came back from
            the stash, then how long the reply is near the limit.

            A member of a GitHub organization has the web's Post as at the start of the row, as composer-box's $lead:
            the avatar of whoever the reply goes out as, lined up with the field's text, which opens the menu (see
            PostAs). The send button is then filled with that one's lane.
        --}}
        @php($replyPostingMe = app(\App\Devnull\Account::class)->user())
        @php($replyPostsAs = $replyPostingMe !== null && \App\Devnull\PostAs::offered($replyAuthor))
        <native:bottom-bar class="{{ \App\Devnull\Display::composerBarGlass() }}">
            <native:column ref="reply-bar" class="w-full {{ \App\Devnull\Display::composerBar() }}">
                @if (\App\Devnull\Display::fadesInBar())
                    @include('native.partials.composer-fade', ['ref' => 'reply-fade', 'inBar' => true])
                @endif
                <native:column native:key="reply-rows" ref="reply-rows" class="w-full {{ \App\Devnull\Display::composerSurface() }}">
                {{--
                    The people to mention and the photos on the reply keep a slot each above the field, empty and
                    0.1pt tall when there's nothing in it, so the field's row never moves. Android rebuilds a row that
                    moves among its siblings, field and all, which drops the keyboard. On iOS an empty slot would
                    otherwise take 10pt of the bar, and EDGE drops a height of 0.
                --}}
                @php($mentioning = ($mention['field'] ?? null) === 'reply' && ($mentionPeople !== [] || $mentionOrganizations !== []))
                <native:row native:key="reply-mention-slot" ref="reply-mention-slot" class="w-full {{ $mentioning ? 'pt-1 ios:px-4' : 'h-[0.1]' }}">
                    @include('native.partials.mention-suggestions', ['field' => 'reply', 'label' => 'Mention a developer'])
                </native:row>
                @php($showsPhotos = $replyImages !== [] && ! $replyPreviewing)
                <native:column native:key="reply-photos-slot" ref="reply-photos-slot" class="w-full {{ $showsPhotos ? 'pl-8 pr-4 pt-2' : 'h-[0.1]' }}">
                    @if ($showsPhotos)
                        @include('native.partials.composer-photos', ['photos' => $replyImages, 'photoRef' => 'reply-photo'])
                    @endif
                </native:column>
                <native:row native:key="reply-row" ref="reply-row" class="w-full px-4 pb-2">
                    @php($hasDraft = trim($reply) !== '' || $replyImages !== [])
                    @php($photosFull = count($replyImages) >= \App\Devnull\DraftImages::MaxImages)
                    @include('native.partials.composer-box', [
                        'ref' => 'reply',
                        'field' => $replyPreviewing ? 'native.partials.reply-preview' : 'native.partials.reply-field',
                        'lead' => $replyPostsAs ? [['native.partials.post-as', ['ref' => 'reply-post-as', 'me' => $replyPostingMe, 'picked' => $replyAuthor, 'method' => 'postAsInReply', 'size' => 28, 'inset' => 'pl-3']]] : [],
                        'tools' => [
                            ['ref' => 'reply-add-photos', 'icon' => \App\Icons\Lucide::ImagePlus, 'label' => $photosFull ? 'A reply can have up to 3 photos' : 'Add photos', 'menu' => $photosFull ? null : \App\Devnull\ComposerMenu::photoItems(), 'disabled' => $photosFull],
                            ['ref' => 'reply-preview', 'icon' => \App\Icons\Lucide::Eye, 'label' => $replyPreviewing ? 'Edit' : 'Preview', 'press' => 'toggleReplyPreview', 'active' => $replyPreviewing],
                        ],
                        'trail' => [
                            ...($hasDraft ? [['native.partials.stash-label', ['ref' => 'reply-stash', 'drop' => 'dropReplyStash', 'size' => 'text-xs', 'restored' => $replyRestored]]] : []),
                            ['native.partials.body-counter', ['body' => $reply, 'ref' => 'reply-budget']],
                        ],
                        'send' => [
                            'ref' => 'send-reply',
                            'label' => 'Reply',
                            'press' => 'sendReply',
                            'disabled' => ! \App\Devnull\Data\Draft::canCommit($reply, $replyImages),
                            'colour' => $replyPostingMe === null ? $myColour : \App\Devnull\PostAs::colour($replyPostingMe, \App\Devnull\PostAs::author($replyAuthor)),
                        ],
                    ])
                </native:row>
                @if (\App\Devnull\Data\CommitBody::hasOversizedUrl($reply))
                    <native:text ref="reply-link-error" class="w-full px-4 pb-2 text-sm text-theme-destructive">Each link must not be greater than {{ \App\Devnull\Data\BodyLink::MaxLength }} characters.</native:text>
                @endif
                </native:column>
            </native:column>
        </native:bottom-bar>
    @endif
</native:column>
