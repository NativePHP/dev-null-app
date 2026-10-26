{{--
    The action row under a commit, in the web's order. On the left: the star and its count, in pastéis mode the pastel
    and its count, Quote, Reply, and in Commit Blue "› N replies" when the row has no thread under it already. On the
    right: More.

    Share link, Report and Delete live in More, which opens the platform's own menu (a UIMenu on iOS, a dropdown
    on Android, see CommitMenu for what's in it). Tapping More only opens the menu.

    Reply opens the commit with its reply composer focused. In a feed ($expandable) the replies link opens the thread in
    place, as on the web; elsewhere it opens the commit with its replies showing. Like the web, someone else's commit
    that isn't approved gets no star or Quote. Your own commits can be starred too, including ones waiting for approval.

    Quote opens a sheet to write the quote in, over the commit being quoted (see commit-sheet).

    The pastel is the web's: the pastel de nata and how many the commit was given, always, even 0. On someone else's
    published commit it gives one more per tap; on your own, or one that isn't published, it's just the count. It
    only shows in pastéis mode ($pasteis).

    A commit with a quote or a reply you started and didn't send marks that button, as the web does with stash@{0}
    beside its label. That doesn't fit the row, so here it's a dot after the icon or label, inside the button's 44pt.
    The screen passes the names of the drafts it has kept ($stashed, see App\Devnull\Stash).

    Each action is at least 44pt wide and 36pt tall to tap, as Apple asks, with the row pulled in by the extra height so
    the commit keeps its rhythm. EDGE makes a pressable's whole box tappable, so the box is the target. Off the log,
    "› N replies" sits beside Reply, and both won't fit on a phone with their labels, so Reply is just its icon there.

    A button is the pressable itself, laid out as a row, with no row inside it, since a list builds and measures every
    node of a row as it scrolls in. More keeps its column: a pressable with a menu draws its content as the menu's
    label, at the label's own size in the corner of the pressable's box, so the column is what centres the icon.

    A commit page opens its replies under its commit in place: it passes what the toggle presses ($repliesToggle),
    whether they're open ($repliesOpen) and how many there are ($repliesCount). $compact draws the row smaller, for
    the commits around the one a page is about.
--}}
@php($withReply ??= true)
@php($withReplies ??= true)
@php($withQuote ??= true)
@php($compact ??= false)
@php($expandable ??= isset($threads))
@php($isOpen = $repliesOpen ?? ($expandable && array_key_exists($commit->id, $threads)))
@php($repliesPress = $repliesToggle ?? ($expandable ? "toggleReplies('{$commit->id}')" : "openReplies('{$commit->id}')"))
@php($repliesCount ??= $commit->repliesCount)
@php($iconSize = $compact ? 14 : 16)
@php($labelSize = $compact ? 'text-xs' : 'text-[13px]')
@php($viewer ??= app(\App\Devnull\Account::class)->user())
@php($stashed ??= [])
@php($hasQuoteStash = in_array('quote-'.$commit->id, $stashed, true))
@php($hasReplyStash = in_array('reply-'.$commit->id, $stashed, true))
@php($isOwn = $commit->author->id === $viewer?->id)
@php($canReact = $commit->isApproved() || $isOwn)
@php($hasThread = $expandable && $commit->replyPreview !== null)
@php($showsReplies = $withReplies && ! $hasThread && $repliesCount > 0)
@php($pasteis ??= app(\App\Devnull\Account::class)->pasteis())
@php($canGivePastel = $pasteis !== null && $viewer !== null && ! $isOwn && $commit->isApproved())
@php($pastelLabel = \App\Devnull\Display::count($commit->pasteisCount, 'pastel', 'pastéis'))
@php($pastelSize = $compact ? 18 : 20)
@php($starLabel = ($commit->starred ? 'Unstar commit' : 'Star commit').($commit->starsCount > 0 ? ', '.\App\Devnull\Display::count($commit->starsCount, 'star', 'stars') : ''))
<native:row ref="actions-{{ $commit->id }}" class="w-full -ml-2 -my-[6] items-center">
    @if ($canReact && $viewer !== null && ! $commit->isDeleted())
        <native:pressable ref="star-{{ $commit->id }}" @press="toggleStar('{{ $commit->id }}')" class="flex-row min-w-[44] h-[36] px-2 gap-1 items-center" a11y-label="{{ $starLabel }}">
            <native:icon :ios="\App\Icons\Lucide::Star->ios($commit->starred)" :android="\App\Icons\Lucide::Star->android($commit->starred)" :size="$iconSize" class="{{ $commit->starred ? 'text-theme-primary' : 'text-theme-ink-muted' }}" />
            @if ($commit->starsCount > 0)
                <native:text font="mono" class="{{ $labelSize }} {{ $commit->starred ? 'text-theme-primary' : 'text-theme-ink-muted' }}">{{ \App\Devnull\Display::compactCount($commit->starsCount) }}</native:text>
            @endif
        </native:pressable>
    @endif

    @if ($canGivePastel)
        <native:pressable ref="pastel-{{ $commit->id }}" @press="givePastel('{{ $commit->id }}')" class="flex-row min-w-[44] h-[36] px-2 gap-1 items-center" a11y-label="Give a pastel, {{ $pastelLabel }}, {{ $pasteis->left() }} left today">
            <native:image :src="\App\Devnull\Data\Pasteis::Image" :width="$pastelSize" :height="$pastelSize" :fit="1" />
            <native:text font="mono" class="{{ $labelSize }} text-theme-ink-muted">{{ \App\Devnull\Display::compactCount($commit->pasteisCount) }}</native:text>
        </native:pressable>
    @elseif ($pasteis !== null)
        <native:row ref="pastel-count-{{ $commit->id }}" class="min-w-[44] h-[36] px-2 gap-1 items-center" a11y-label="{{ $pastelLabel }}">
            <native:image :src="\App\Devnull\Data\Pasteis::Image" :width="$pastelSize" :height="$pastelSize" :fit="1" />
            <native:text font="mono" class="{{ $labelSize }} text-theme-ink-muted">{{ \App\Devnull\Display::compactCount($commit->pasteisCount) }}</native:text>
        </native:row>
    @endif

    @if ($withQuote && $canReact)
        <native:pressable ref="quote-{{ $commit->id }}" @press="quoteCommit('{{ $commit->id }}')" class="flex-row min-w-[44] h-[36] px-2 gap-[3] items-center justify-center" a11y-label="{{ $hasQuoteStash ? 'Quote, you have an unsent quote here' : 'Quote' }}">
            <native:icon :ios="\App\Icons\Lucide::Quote->ios()" :android="\App\Icons\Lucide::Quote->android()" :size="$iconSize" class="text-theme-ink-muted" />
            @if ($hasQuoteStash)
                <native:column ref="quote-stash-{{ $commit->id }}" class="w-[5] h-[5] rounded-full bg-theme-ink-muted" />
            @endif
        </native:pressable>
    @endif

    @if ($withReply)
        <native:pressable ref="reply-to-{{ $commit->id }}" @press="replyTo('{{ $commit->id }}')" class="flex-row min-w-[44] h-[36] px-2 gap-[6] items-center {{ $showsReplies ? 'justify-center' : '' }}" a11y-label="{{ $hasReplyStash ? 'Reply, you have an unsent reply here' : 'Reply' }}">
            <native:icon :ios="\App\Icons\Lucide::MessageSquareReply->ios()" :android="\App\Icons\Lucide::MessageSquareReply->android()" :size="$iconSize" class="text-theme-ink-muted" />
            @unless ($showsReplies)
                <native:text class="{{ $labelSize }} text-theme-ink-muted">Reply</native:text>
            @endunless
            @if ($hasReplyStash)
                <native:column ref="reply-stash-{{ $commit->id }}" class="w-[5] h-[5] -ml-[3] rounded-full bg-theme-ink-muted" />
            @endif
        </native:pressable>
    @endif

    @if ($showsReplies)
        <native:pressable ref="replies-{{ $commit->id }}" @press="{{ $repliesPress }}" class="flex-row h-[36] px-2 gap-1 items-center" a11y-label="{{ $isOpen ? 'Hide replies' : \App\Devnull\Display::count($repliesCount, 'reply', 'replies') }}">
            @if ($isOpen)
                <native:icon :ios="\App\Icons\Lucide::ChevronDown->ios()" :android="\App\Icons\Lucide::ChevronDown->android()" :size="14" class="text-theme-primary" />
                <native:text class="{{ $labelSize }} text-theme-primary">Hide replies</native:text>
            @else
                <native:icon :ios="\App\Icons\Lucide::ChevronRight->ios()" :android="\App\Icons\Lucide::ChevronRight->android()" :size="14" class="text-theme-primary" />
                <native:text class="{{ $labelSize }} text-theme-primary">{{ \App\Devnull\Display::count($repliesCount, 'reply', 'replies') }}</native:text>
            @endif
        </native:pressable>
    @endif

    <native:spacer />

    <native:pressable ref="more-{{ $commit->id }}" :menu="\App\Devnull\CommitMenu::items($commit, $viewer)" a11y-label="More">
        <native:column class="min-w-[44] h-[36] items-center justify-center">
            <native:icon :ios="\App\Icons\Lucide::Ellipsis->ios()" :android="\App\Icons\Lucide::Ellipsis->android()" :size="$iconSize" class="text-theme-ink-muted" />
        </native:column>
    </native:pressable>
</native:row>
