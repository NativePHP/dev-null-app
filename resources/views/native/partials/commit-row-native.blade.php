{{--
    The feed's commit row as one native element (devnull/commit-row), for comparing with the Blade row on the phones.
    It draws what commit-row's pressable branch draws with commit-row-content, commit-body, commit-actions and the rail,
    for a commit commit-row has checked it can draw (App\Devnull\CommitRows::draws): no media, no code block, not part
    of a series and approved.

    The element takes no classes. Everything it draws is a value, and every value is picked here, the way those
    partials pick their classes: muted, thread below, the lanes, which rail pieces show, which actions show and what
    they say. The element only draws what it's given.
--}}
@use('App\Devnull\BodyRuns')
@use('App\Devnull\CommitMenu')
@use('App\Devnull\Display')
@use('App\Devnull\ImageCache')
@use('App\Devnull\Lane')
@use('App\Devnull\Data\Pasteis')
@use('App\Icons\Lucide')
@use('Devnull\CommitRow\ColorPair')
@use('Devnull\CommitRow\PlatformIcon')
@php
    $author = $commit->author;
    $muted ??= false;
    $hairline ??= true;
    $continuesNext ??= null;
    $threadBelow ??= false;
    $withTail ??= true;
    $inFeed = $continuesNext !== null;
    $endsWithDot = $inFeed && ! $continuesRun && ! $continuesNext;
    $hasHairline = $hairline && ! $threadBelow;
    $metaSize = $muted ? 12 : 13;

    $ink = ColorPair::theme($muted ? 'ink-muted' : 'ink');
    $inkMuted = ColorPair::theme('ink-muted');
    $primary = ColorPair::theme('primary');
    $icon = fn (Lucide $lucide, bool $filled = false): PlatformIcon => new PlatformIcon($lucide->ios($filled), $lucide->android($filled));

    $folded = $commit->isFolded() && ! in_array($commit->id, $unfolded ?? [], true);

    // The action row, as commit-actions picks it ($compact is $muted).
    $expandable = isset($threads);
    $isOpen = $expandable && array_key_exists($commit->id, $threads);
    $viewer ??= app(\App\Devnull\Account::class)->user();
    $stashed ??= [];
    $pasteis ??= app(\App\Devnull\Account::class)->pasteis();
    $isOwn = $commit->author->id === $viewer?->id;
    $canReact = $commit->isApproved() || $isOwn;
    $hasThread = $expandable && $commit->replyPreview !== null;
    $showsReplies = ! $hasThread && $commit->repliesCount > 0;
    $hasQuoteStash = in_array('quote-'.$commit->id, $stashed, true);
    $hasReplyStash = in_array('reply-'.$commit->id, $stashed, true);
    $canGivePastel = $pasteis !== null && $viewer !== null && ! $isOwn && $commit->isApproved();
    $pastelLabel = Display::count($commit->pasteisCount, 'pastel', 'pastéis');

    // The rail, as commit-row-content hands it to the rail partial.
    $lane = $commit->lane();
    $laneColor = ColorPair::theme(Lane::token($lane));
    $hasTail = $inFeed ? $continuesNext || ! $continuesRun : $withTail;
    $replyTail = $threadBelow && ! ($inFeed && $continuesNext);
    $node = $continuesRun ? 'dot' : 'avatar';
    $avatarSize = $muted ? 36 : 48;
    $ring = $avatarSize <= 24 ? 1 : 2;
    $avatarInner = $avatarSize - $ring * 4;

    $parent = $commit->parent;
    $parentLink = $parent !== null && ! $parent->isDeleted();
    $repliesLabel = $isOpen ? 'Hide replies' : Display::count($commit->repliesCount, 'reply', 'replies');
    $starA11y = ($commit->starred ? 'Unstar commit' : 'Star commit').($commit->starsCount > 0 ? ', '.Display::count($commit->starsCount, 'star', 'stars') : '');
    $nodeA11y = $author->name."'s profile";
    $press = [
        'replyingTo' => $parentLink ? "openCommit('{$parent->id}')" : null,
        'showMore' => $folded ? "showMore('{$commit->id}')" : null,
        'star' => $canReact && $viewer !== null ? "toggleStar('{$commit->id}')" : null,
        'pastel' => $canGivePastel ? "givePastel('{$commit->id}')" : null,
        'quote' => $canReact ? "quoteCommit('{$commit->id}')" : null,
        'reply' => "replyTo('{$commit->id}')",
        'replies' => $showsReplies ? ($expandable ? "toggleReplies('{$commit->id}')" : "openCommit('{$commit->id}')") : null,
        'node' => "openProfile('{$author->username}')",
    ];
@endphp
<native:commit-row
    native:key="{{ $rowKey ?? 'commit-'.$commit->id }}"
    ref="commit-{{ $commit->id }}"
    @press="openCommit('{{ $commit->id }}')"
    :content-min-height="$muted ? 56 : 64"
    :content-padding-top="12"
    :content-padding-bottom="$threadBelow ? 0 : ($hasHairline ? 13 : 12)"
    :hairline="$hasHairline"
    :name="$author->name"
    :name-size="$muted ? 14 : 15"
    :name-color="$ink"
    :handle="$author->handle()"
    :time="Display::relativeTime($commit->createdAt)"
    :meta-size="$metaSize"
    :replying-to="$parent === null ? '' : 'replying to '.($parentLink ? $parent->author->handle() : '[deleted]')"
    :replying-to-press="$press['replyingTo']"
    :replying-to-a11y="$parentLink ? 'Replying to '.$parent->author->handle() : ''"
    :replying-to-icon="$icon(Lucide::CornerDownRight)"
    :body="BodyRuns::for($commit, $folded ? $commit->previewContent : $commit->content)"
    :body-size="$muted ? 14 : 15"
    :body-color="$ink"
    :show-more-press="$press['showMore']"
    :icon-size="$muted ? 14 : 16"
    :label-size="$muted ? 12 : 13"
    :star-press="$press['star']"
    :star-icon="$icon(Lucide::Star, $commit->starred)"
    :star-on="$commit->starred"
    :star-count="$commit->starsCount > 0 ? Display::compactCount($commit->starsCount) : ''"
    :star-a11y="$starA11y"
    :pastel="$canGivePastel ? 'give' : ($pasteis !== null ? 'count' : '')"
    :pastel-press="$press['pastel']"
    :pastel-src="Pasteis::Image"
    :pastel-size="$muted ? 18 : 20"
    :pastel-count="Display::compactCount($commit->pasteisCount)"
    :pastel-a11y="$canGivePastel ? 'Give a pastel, '.$pastelLabel.', '.$pasteis->left().' left today' : $pastelLabel"
    :quote-press="$press['quote']"
    :quote-icon="$icon(Lucide::Quote)"
    :quote-stash="$hasQuoteStash"
    :quote-a11y="$hasQuoteStash ? 'Quote, you have an unsent quote here' : 'Quote'"
    :reply-press="$press['reply']"
    :reply-icon="$icon(Lucide::MessageSquareReply)"
    :reply-label="$showsReplies ? '' : 'Reply'"
    :reply-stash="$hasReplyStash"
    :reply-a11y="$hasReplyStash ? 'Reply, you have an unsent reply here' : 'Reply'"
    :reply-centered="$showsReplies"
    :replies-press="$press['replies']"
    :replies-icon="$icon($isOpen ? Lucide::ChevronDown : Lucide::ChevronRight)"
    :replies-label="$repliesLabel"
    :replies-a11y="$repliesLabel"
    :more-icon="$icon(Lucide::Ellipsis)"
    :menu="CommitMenu::entries($commit, $viewer)"
    :lane-color="$laneColor"
    :previous-color="$previousLane === null ? null : ColorPair::theme(Lane::token($previousLane))"
    :tail="$hasTail"
    :tail-bottom="$endsWithDot ? 20 : 0"
    :end-dot="$endsWithDot && $hasTail"
    :reply-tail="$replyTail"
    :reply-tail-top="$node === 'dot' ? 23 : 32"
    :reply-tail-height="$endsWithDot ? 20 : 0"
    :node="$node"
    :node-top="$node === 'dot' ? 14 : 32 - intdiv($avatarSize, 2)"
    :node-left="$node === 'dot' ? 23 : 32 - intdiv($avatarSize, 2)"
    :node-press="$press['node']"
    :node-a11y="$nodeA11y"
    :avatar-size="$avatarSize"
    :avatar-ring="$ring"
    :avatar-inner="$avatarInner"
    :avatar-initial="mb_strtoupper(mb_substr($author->name, 0, 1))"
    :avatar-initial-size="$avatarInner * 0.4"
    :avatar-src="$author->avatarUrl === null ? null : ImageCache::src($author->avatarUrl)"
/>
