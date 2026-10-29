{{--
    The inside of a commit row, for commit-row to wrap in a link or, for the focused commit, a plain row.
    A part of a series wears its place in it ("1/3"). Shown on its own ($seriesExpanded false), a part with more
    after it links to them, as the web's "2 more commits in this series" does. $withTail runs the rail on below.

    In a feed, $continuesNext says whether the author's run goes on in the next row. The rail then runs on, or for a
    commit alone in its run ends in a dot of its own, and with replies under the row ($threadBelow) a dashed line in
    the lane carries on down to them, as the web draws it.

    On a commit page the commit it's about ($focused) is the main thing: a larger name and body in full ink, its time,
    which tapped says when in your timezone, its hash, and its replies behind a toggle in its action row. The commits around it ($muted) step back:
    smaller and in muted ink, with a smaller avatar and action row. They still open their own pages.

    A commit you just made that devnull doesn't have yet is staged: dimmed, with the staged chip and no actions, until
    the real one takes its place (see SyncsOutbox).

    A list builds and measures every node of a row as it scrolls in, so the content is one column: the rail's 64pt
    gutter is its left margin, and the hairline under it is its bottom border. A border draws inside the box, so the
    bottom padding takes the hairline's 1pt to keep the row as tall as it was.
--}}
@php($author = $commit->author)
@php($muted ??= false)
@php($hairline ??= true)
@php($seriesExpanded ??= false)
@php($withTail ??= ! $focused)
@php($continuesNext ??= null)
@php($threadBelow ??= false)
@php($inFeed = $continuesNext !== null)
@php($endsWithDot = $inFeed && ! $continuesRun && ! $continuesNext)
@php($laterParts = $seriesExpanded ? 0 : $commit->laterSeriesParts())
@php($hasHairline = $hairline && ! $threadBelow)
@php($inset = $focused ? 16 : 12)
@php($padding = 'pt-['.$inset.']'.($threadBelow ? '' : ' pb-['.($hasHairline ? $inset + 1 : $inset).']'))
@php($metaSize = $muted ? 'text-xs' : 'text-[13px]')
<native:column class="flex-1 ml-[64] {{ $muted ? 'min-h-[56]' : 'min-h-[64]' }} {{ $padding }} pr-4 gap-1 {{ $hasHairline ? 'border-b border-theme-hairline' : '' }} {{ $commit->isStaged() ? 'opacity-[0.6]' : '' }}">
    <native:row class="w-full gap-[6] items-center">
        <native:text font="semibold" class="{{ $focused ? 'text-base' : ($muted ? 'text-sm' : 'text-[15px]') }} {{ $muted ? 'text-theme-ink-muted' : 'text-theme-ink' }} flex-shrink" :max-lines="1">{{ $author->name }}</native:text>
        <native:text font="mono" class="{{ $metaSize }} text-theme-ink-muted flex-shrink" :max-lines="1">{{ $author->handle() }}</native:text>
        <native:text class="{{ $metaSize }} text-theme-ink-muted">·</native:text>
        @if ($focused && $commit->createdAt)
            {{-- Tapped, it says when in your timezone, as the web's tooltip does on hover. --}}
            <native:pressable ref="focused-time" @press="showTime('{{ $commit->id }}')" class="flex-shrink-0" a11y-label="{{ \App\Devnull\Display::fullDateTime($commit->createdAt) }}">
                <native:text font="mono" class="{{ $metaSize }} text-theme-ink-muted">{{ \App\Devnull\Display::relativeTime($commit->createdAt) }}</native:text>
            </native:pressable>
        @else
            <native:text font="mono" class="{{ $metaSize }} text-theme-ink-muted flex-shrink-0">{{ \App\Devnull\Display::relativeTime($commit->createdAt) }}</native:text>
        @endif
        @if ($commit->isSeriesPart())
            @include('native.partials.series-position')
        @endif
        @unless ($commit->isApproved())
            @include('native.partials.status-chip', ['status' => $commit->status])
        @endunless
    </native:row>

    @if ($commit->parent?->isDeleted())
        <native:row ref="replying-to-{{ $commit->id }}" class="gap-1 items-center">
            <native:icon :ios="\App\Icons\Lucide::CornerDownRight->ios()" :android="\App\Icons\Lucide::CornerDownRight->android()" :size="12" class="text-theme-ink-muted" />
            <native:text font="mono" class="text-xs text-theme-ink-muted">replying to [deleted]</native:text>
        </native:row>
    @elseif ($commit->parent)
        <native:pressable ref="replying-to-{{ $commit->id }}" @press="openCommit('{{ $commit->parent->id }}')" class="flex-row gap-1 items-center" a11y-label="Replying to {{ $commit->parent->author->handle() }}">
            <native:icon :ios="\App\Icons\Lucide::CornerDownRight->ios()" :android="\App\Icons\Lucide::CornerDownRight->android()" :size="12" class="text-theme-ink-muted" />
            <native:text font="mono" class="text-xs text-theme-ink-muted">replying to {{ $commit->parent->author->handle() }}</native:text>
        </native:pressable>
    @endif

    @include('native.partials.commit-body', ['size' => $focused ? 'text-[17px]' : ($muted ? 'text-sm' : 'text-[15px]'), 'muted' => $muted])
    @include('native.partials.commit-media')

    @if ($laterParts > 0)
        <native:pressable ref="series-continues-{{ $commit->id }}" @press="openCommit('{{ $commit->id }}')" class="mt-1 flex-row gap-[6] items-center" a11y-label="{{ $laterParts }} more {{ $laterParts === 1 ? 'commit' : 'commits' }} in this series">
            <native:icon :ios="\App\Icons\Lucide::ListOrdered->ios()" :android="\App\Icons\Lucide::ListOrdered->android()" :size="14" class="text-theme-primary" />
            <native:text font="mono" class="text-xs text-theme-primary">{{ $laterParts }} more {{ $laterParts === 1 ? 'commit' : 'commits' }} in this series</native:text>
        </native:pressable>
    @endif

    @if ($focused)
        <native:text ref="focused-hash" font="mono" class="mt-1 text-[13px] text-theme-ink-muted">{{ $commit->shortHash }}</native:text>
    @endif

    @if ($commit->isStaged())
    @elseif ($focused)
        @include('native.partials.commit-actions', [
            'withReply' => false,
            'repliesToggle' => 'toggleReplies',
            'repliesOpen' => $repliesShown ?? false,
            'repliesCount' => $replyCount ?? $commit->repliesCount,
        ])
    @else
        @include('native.partials.commit-actions', ['compact' => $muted])
    @endif
</native:column>

@include('native.partials.rail', [
    'previous' => $previousLane,
    'lane' => $commit->lane(),
    'tail' => ($inFeed ? $continuesNext || ! $continuesRun : $withTail) ? $commit->lane() : null,
    'endsWithDot' => $endsWithDot,
    'replyTail' => $threadBelow && ! ($inFeed && $continuesNext),
    'node' => $continuesRun ? 'dot' : 'avatar',
    'avatarSize' => $muted ? 36 : 48,
    'user' => $author,
    'press' => $author->isLive() ? "openLiveStream('{$author->username}')" : "openProfile('{$author->username}')",
])
