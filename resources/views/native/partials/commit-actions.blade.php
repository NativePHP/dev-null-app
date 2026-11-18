{{--
    The action row under a commit, in the web's order. On the left: the star and its count, in pastéis mode the pastel
    and its count, Quote and Reply, each with its label. On the right: More. Under the row, on a line of its own, the
    replies button: "› N replies" in Commit Blue, or "Hide replies" with the chevron turned down while they show.

    Share link, Report and Delete live in More, which opens the platform's own menu (a UIMenu on iOS, a dropdown
    on Android, see CommitMenu for what's in it). Tapping More only opens the menu.

    Reply opens the commit with its reply composer focused. In a feed ($expandable) the replies button opens the replies
    in place, as on the web (see Concerns\ExpandsThreads); elsewhere it opens the commit with its replies showing. Like
    the web, someone else's commit that isn't approved gets no star or Quote. devnull doesn't let you star your own
    commits, so on those the star is the count, which opens who starred it (see OpensStargazers), or a faint star until
    there are any, as on the web.

    Quote opens a sheet to write the quote in, over the commit being quoted (see commit-sheet).

    The pastel is the web's: the pastel de nata and how many the commit was given, always, even 0. On someone else's
    published commit it gives one more per tap; on your own, or one that isn't published, it's just the count. It
    only shows in pastéis mode ($pasteis). A star you give and a pastel pop as they do on the web (see PopsReactions).

    A commit with a quote or a reply you started and didn't send marks that button, as the web does with stash@{0}
    beside its label. That doesn't fit the row, so here it's a dot after the label, inside the button.
    The screen passes the names of the drafts it has kept ($stashed, see App\Devnull\Stash).

    Each action is at least 44pt wide and 36pt tall to tap, as Apple asks. The row has no negative vertical margins:
    Android draws a negative margin as an offset and keeps the space, so the row sat 6pt lower there than on iOS, and
    with it everything placed against it. $endDot draws the end of the commit's rail, a lane dot, inside the row at the
    rail's x, so it's level with the actions whatever is above or below them (see commit-row-content).

    A button is the pressable itself, laid out as a row, with no row inside it, since a list builds and measures every
    node of a row as it scrolls in. More keeps its column: a pressable with a menu draws its content as the menu's
    label, at the label's own size in the corner of the pressable's box, so the column is what centres the icon.

    A commit page opens its replies under its commit in place: it passes what the toggle presses ($repliesToggle),
    whether they're open ($repliesOpen) and how many there are ($repliesCount). $compact draws the row smaller, for
    the commits around the one a page is about.

    Wherever the button opens the replies in place, in a feed or on a commit page, its chevron turns down while they
    show and back as they close, over the time a feed's thread takes to fade (ThreadRows::Fade). The chevron carries
    the animation shut as well as open: iOS only animates a change on a node that already had it.
--}}
@php($withReply ??= true)
@php($withReplies ??= true)
@php($withQuote ??= true)
@php($compact ??= false)
@php($expandable ??= isset($threads))
@php($isOpen = $repliesOpen ?? ($expandable && array_key_exists($commit->id, $threads) && ! in_array($commit->id, $closingThreads ?? [], true)))
@php($repliesPress = $repliesToggle ?? ($expandable ? "toggleReplies('{$commit->id}')" : "openReplies('{$commit->id}')"))
@php($opensInPlace = $expandable || isset($repliesToggle))
@php($repliesCount ??= $commit->repliesCount)
@php($iconSize = $compact ? 14 : 16)
@php($labelSize = $compact ? 'text-xs' : 'text-[13px]')
@php($viewer ??= app(\App\Devnull\Account::class)->user())
@php($stashed ??= [])
@php($hasQuoteStash = in_array('quote-'.$commit->id, $stashed, true))
@php($hasReplyStash = in_array('reply-'.$commit->id, $stashed, true))
@php($isOwn = $commit->author->id === $viewer?->id)
@php($canQuote = $commit->isApproved() || $isOwn)
@php($showsReplies = $withReplies && ! $commit->isDeleted() && $repliesCount > 0)
@php($endDot ??= null)
@php($starPop = \App\Devnull\Pop::transform($popping['star-'.$commit->id] ?? null, 'star'))
@php($pastelPop = \App\Devnull\Pop::transform($popping['pastel-'.$commit->id] ?? null, 'pastel'))
@php($pasteis ??= app(\App\Devnull\Account::class)->pasteis())
@php($canGivePastel = $pasteis !== null && $viewer !== null && ! $isOwn && $commit->isApproved())
@php($pastelLabel = \App\Devnull\Display::count($commit->pasteisCount, 'pastel', 'pastéis'))
@php($pastelSize = $compact ? 18 : 20)
@php($starLabel = ($commit->starred ? 'Unstar commit' : 'Star commit').($commit->starsCount > 0 ? ', '.\App\Devnull\Display::count($commit->starsCount, 'star', 'stars') : ''))
<native:row ref="actions-{{ $commit->id }}" class="w-full h-[36] -ml-2 items-center">
    @if ($commit->isApproved() && ! $isOwn && $viewer !== null && ! $commit->isDeleted())
        <native:pressable ref="star-{{ $commit->id }}" @press="toggleStar('{{ $commit->id }}')" class="flex-row min-w-[44] h-[36] px-2 gap-1 items-center" a11y-label="{{ $starLabel }}">
            <native:icon :ios="\App\Icons\Lucide::Star->ios($commit->starred)" :android="\App\Icons\Lucide::Star->android($commit->starred)" :size="$iconSize" class="{{ $commit->starred ? 'text-theme-primary' : 'text-theme-ink-muted' }}" :scale="$starPop['scale']" :rotate="$starPop['rotate']" :animate-duration="$starPop['duration']" :animate-easing="$starPop['easing']" />
            @if ($commit->starsCount > 0)
                <native:text font="mono" class="{{ $labelSize }} {{ $commit->starred ? 'text-theme-primary' : 'text-theme-ink-muted' }}">{{ \App\Devnull\Display::compactCount($commit->starsCount) }}</native:text>
            @endif
        </native:pressable>
    @elseif ($isOwn && ! $commit->isDeleted() && $commit->starsCount > 0)
        <native:pressable ref="star-count-{{ $commit->id }}" @press="openStargazers('{{ $commit->id }}')" class="flex-row min-w-[44] h-[36] px-2 gap-1 items-center" a11y-label="{{ \App\Devnull\Display::count($commit->starsCount, 'star', 'stars') }}, show who starred">
            <native:icon :ios="\App\Icons\Lucide::Star->ios()" :android="\App\Icons\Lucide::Star->android()" :size="$iconSize" class="text-theme-ink-muted" />
            <native:text font="mono" class="{{ $labelSize }} text-theme-ink-muted">{{ \App\Devnull\Display::compactCount($commit->starsCount) }}</native:text>
        </native:pressable>
    @elseif ($isOwn && ! $commit->isDeleted())
        <native:row ref="star-count-{{ $commit->id }}" class="min-w-[44] h-[36] px-2 gap-1 items-center" a11y-label="No stars yet">
            <native:icon :ios="\App\Icons\Lucide::Star->ios()" :android="\App\Icons\Lucide::Star->android()" :size="$iconSize" class="text-theme-ink-muted/50" />
        </native:row>
    @endif

    @if ($canGivePastel)
        <native:pressable ref="pastel-{{ $commit->id }}" @press="givePastel('{{ $commit->id }}')" class="flex-row min-w-[44] h-[36] px-2 gap-1 items-center" a11y-label="Give a pastel, {{ $pastelLabel }}, {{ $pasteis->left() }} left today">
            <native:image :src="\App\Devnull\Data\Pasteis::Image" :width="$pastelSize" :height="$pastelSize" :fit="1" :scale="$pastelPop['scale']" :animate-duration="$pastelPop['duration']" :animate-easing="$pastelPop['easing']" />
            <native:text font="mono" class="{{ $labelSize }} text-theme-ink-muted">{{ \App\Devnull\Display::compactCount($commit->pasteisCount) }}</native:text>
        </native:pressable>
    @elseif ($pasteis !== null)
        <native:row ref="pastel-count-{{ $commit->id }}" class="min-w-[44] h-[36] px-2 gap-1 items-center" a11y-label="{{ $pastelLabel }}">
            <native:image :src="\App\Devnull\Data\Pasteis::Image" :width="$pastelSize" :height="$pastelSize" :fit="1" />
            <native:text font="mono" class="{{ $labelSize }} text-theme-ink-muted">{{ \App\Devnull\Display::compactCount($commit->pasteisCount) }}</native:text>
        </native:row>
    @endif

    @if ($withQuote && $canQuote)
        <native:pressable ref="quote-{{ $commit->id }}" @press="quoteCommit('{{ $commit->id }}')" class="flex-row min-w-[44] h-[36] px-2 gap-[6] items-center" a11y-label="{{ $hasQuoteStash ? 'Quote, you have an unsent quote here' : 'Quote' }}">
            <native:icon :ios="\App\Icons\Lucide::Quote->ios()" :android="\App\Icons\Lucide::Quote->android()" :size="$iconSize" class="text-theme-ink-muted" />
            <native:text class="{{ $labelSize }} text-theme-ink-muted">Quote</native:text>
            @if ($hasQuoteStash)
                <native:column ref="quote-stash-{{ $commit->id }}" class="w-[5] h-[5] -ml-[3] rounded-full bg-theme-ink-muted" />
            @endif
        </native:pressable>
    @endif

    @if ($withReply)
        <native:pressable ref="reply-to-{{ $commit->id }}" @press="replyTo('{{ $commit->id }}')" class="flex-row min-w-[44] h-[36] px-2 gap-[6] items-center" a11y-label="{{ $hasReplyStash ? 'Reply, you have an unsent reply here' : 'Reply' }}">
            <native:icon :ios="\App\Icons\Lucide::MessageSquareReply->ios()" :android="\App\Icons\Lucide::MessageSquareReply->android()" :size="$iconSize" class="text-theme-ink-muted" />
            <native:text class="{{ $labelSize }} text-theme-ink-muted">Reply</native:text>
            @if ($hasReplyStash)
                <native:column ref="reply-stash-{{ $commit->id }}" class="w-[5] h-[5] -ml-[3] rounded-full bg-theme-ink-muted" />
            @endif
        </native:pressable>
    @endif

    <native:spacer />

    <native:pressable ref="more-{{ $commit->id }}" :menu="\App\Devnull\CommitMenu::items($commit, $viewer)" a11y-label="More">
        <native:column class="min-w-[44] h-[36] items-center justify-center">
            <native:icon :ios="\App\Icons\Lucide::Ellipsis->ios()" :android="\App\Icons\Lucide::Ellipsis->android()" :size="$iconSize" class="text-theme-ink-muted" />
        </native:column>
    </native:pressable>

    @if ($endDot !== null)
        {{-- The end of the rail, at its x: the row starts 8pt into the 64pt gutter's edge, and the dot is 18pt round. --}}
        @include('native.partials.rail-node', ['lane' => $endDot, 'nodeRef' => 'end-dot', 'position' => 'absolute top-[9] -left-[33]'])
    @endif
</native:row>

@if ($showsReplies)
    <native:pressable ref="replies-{{ $commit->id }}" @press="{{ $repliesPress }}" class="flex-row self-start h-[36] -ml-2 px-2 gap-1 items-center" a11y-label="{{ $isOpen ? 'Hide replies' : \App\Devnull\Display::count($repliesCount, 'reply', 'replies') }}">
        <native:icon :ios="\App\Icons\Lucide::ChevronRight->ios()" :android="\App\Icons\Lucide::ChevronRight->android()" :size="14" class="text-theme-primary" :rotate="$opensInPlace ? ($isOpen ? 90 : 0) : null" :animate-duration="$opensInPlace ? \App\Devnull\ThreadRows::Fade : null" />
        <native:text class="{{ $labelSize }} text-theme-primary">{{ $isOpen ? 'Hide replies' : \App\Devnull\Display::count($repliesCount, 'reply', 'replies') }}</native:text>
    </native:pressable>
@endif
