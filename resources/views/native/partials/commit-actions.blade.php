{{--
    The action row under a commit, in the web's order. On the left: the star and its count, Quote, Reply, and in Commit
    Blue "› N replies" when the row has no thread under it already. On the right: More.

    The web's right side has the bookmark, copy link and Report, and a phone has no room for all three: a profile row
    with "› 3 replies" would need about 356pt of the 330pt it has. So they share More, which opens the platform's own
    menu (a UIMenu on iOS, a dropdown on Android, see CommitMenu for what's in it). Tapping More only opens the menu.

    Reply opens the commit with its reply composer focused. In a feed ($expandable) the replies link opens the thread in
    place, as on the web; elsewhere it opens the commit. Like the web, someone else's commit that isn't approved gets no
    star, Quote or Save commit, and your own commit's star is just its count, since you can't star it.

    Quote opens a sheet to write the quote in, over the commit being quoted (see commit-sheet).

    Each action is at least 44pt wide and 36pt tall to tap, as Apple asks, with the row pulled in by the extra height so
    the commit keeps its rhythm. EDGE makes a pressable's whole box tappable, so the box is the target. Off the log,
    "› N replies" sits beside Reply, and both won't fit on a phone with their labels, so Reply is just its icon there.

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
@php($repliesPress = $repliesToggle ?? ($expandable ? "toggleReplies('{$commit->id}')" : "openCommit('{$commit->id}')"))
@php($repliesCount ??= $commit->repliesCount)
@php($iconSize = $compact ? 14 : 16)
@php($labelSize = $compact ? 'text-xs' : 'text-[13px]')
@php($viewer ??= app(\App\Devnull\Account::class)->user())
@php($isOwn = $commit->author->id === $viewer?->id)
@php($canReact = $commit->isApproved() || $isOwn)
@php($hasThread = $expandable && $commit->replyPreview !== null)
@php($showsReplies = $withReplies && ! $hasThread && $repliesCount > 0)
@php($starLabel = ($commit->starred ? 'Unstar commit' : 'Star commit').($commit->starsCount > 0 ? ', '.\App\Devnull\Display::count($commit->starsCount, 'star', 'stars') : ''))
<native:row ref="actions-{{ $commit->id }}" class="w-full -ml-2 -my-[6] items-center">
    @if ($canReact && $isOwn)
        {{-- Your own commit: how many stars others gave, not a button. --}}
        <native:row ref="star-count-{{ $commit->id }}" class="min-w-[44] h-[36] px-2 gap-1 items-center" a11y-label="{{ $commit->starsCount === 0 ? 'No stars yet' : \App\Devnull\Display::count($commit->starsCount, 'star', 'stars') }}">
            <native:icon :ios="\App\Icons\Lucide::Star->ios()" :android="\App\Icons\Lucide::Star->android()" :size="$iconSize" class="text-theme-ink-muted {{ $commit->starsCount === 0 ? 'opacity-50' : '' }}" />
            @if ($commit->starsCount > 0)
                <native:text font="mono" class="{{ $labelSize }} text-theme-ink-muted">{{ $commit->starsCount }}</native:text>
            @endif
        </native:row>
    @elseif ($canReact)
        <native:pressable ref="star-{{ $commit->id }}" @press="toggleStar('{{ $commit->id }}')" a11y-label="{{ $starLabel }}">
            <native:row class="min-w-[44] h-[36] px-2 gap-1 items-center">
                <native:icon :ios="\App\Icons\Lucide::Star->ios($commit->starred)" :android="\App\Icons\Lucide::Star->android($commit->starred)" :size="$iconSize" class="{{ $commit->starred ? 'text-theme-primary' : 'text-theme-ink-muted' }}" />
                @if ($commit->starsCount > 0)
                    <native:text font="mono" class="{{ $labelSize }} {{ $commit->starred ? 'text-theme-primary' : 'text-theme-ink-muted' }}">{{ $commit->starsCount }}</native:text>
                @endif
            </native:row>
        </native:pressable>
    @endif

    @if ($withQuote && $canReact)
        <native:pressable ref="quote-{{ $commit->id }}" @press="quoteCommit('{{ $commit->id }}')" a11y-label="Quote">
            <native:column class="min-w-[44] h-[36] px-2 items-center justify-center">
                <native:icon :ios="\App\Icons\Lucide::Quote->ios()" :android="\App\Icons\Lucide::Quote->android()" :size="$iconSize" class="text-theme-ink-muted" />
            </native:column>
        </native:pressable>
    @endif

    @if ($withReply)
        <native:pressable ref="reply-to-{{ $commit->id }}" @press="replyTo('{{ $commit->id }}')" a11y-label="Reply">
            <native:row class="min-w-[44] h-[36] px-2 gap-[6] items-center {{ $showsReplies ? 'justify-center' : '' }}">
                <native:icon :ios="\App\Icons\Lucide::MessageSquareReply->ios()" :android="\App\Icons\Lucide::MessageSquareReply->android()" :size="$iconSize" class="text-theme-ink-muted" />
                @unless ($showsReplies)
                    <native:text class="{{ $labelSize }} text-theme-ink-muted">Reply</native:text>
                @endunless
            </native:row>
        </native:pressable>
    @endif

    @if ($showsReplies)
        <native:pressable ref="replies-{{ $commit->id }}" @press="{{ $repliesPress }}" a11y-label="{{ $isOpen ? 'Hide replies' : \App\Devnull\Display::count($repliesCount, 'reply', 'replies') }}">
            <native:row class="h-[36] px-2 gap-1 items-center">
                @if ($isOpen)
                    <native:icon :ios="\App\Icons\Lucide::ChevronDown->ios()" :android="\App\Icons\Lucide::ChevronDown->android()" :size="14" class="text-theme-primary" />
                    <native:text class="{{ $labelSize }} text-theme-primary">Hide replies</native:text>
                @else
                    <native:icon :ios="\App\Icons\Lucide::ChevronRight->ios()" :android="\App\Icons\Lucide::ChevronRight->android()" :size="14" class="text-theme-primary" />
                    <native:text class="{{ $labelSize }} text-theme-primary">{{ \App\Devnull\Display::count($repliesCount, 'reply', 'replies') }}</native:text>
                @endif
            </native:row>
        </native:pressable>
    @endif

    <native:spacer />

    <native:pressable ref="more-{{ $commit->id }}" :menu="\App\Devnull\CommitMenu::items($commit, $viewer)" a11y-label="More">
        <native:column class="min-w-[44] h-[36] items-center justify-center">
            <native:icon :ios="\App\Icons\Lucide::Ellipsis->ios()" :android="\App\Icons\Lucide::Ellipsis->android()" :size="$iconSize" class="text-theme-ink-muted" />
        </native:column>
    </native:pressable>
</native:row>
