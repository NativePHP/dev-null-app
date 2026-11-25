{{--
    A reply in a thread opened in place (see App\Devnull\ThreadRows), like a reply in the web's thread: a 24pt avatar
    lined up with the commit's text, or $indent further in, the name and time, the body and its actions. The thread
    hangs off the rail in dashes of each author's lane, as upstream's #121 draws it: the first reply on an elbow from
    the line of what it answers ($lane is that one's), each later one on a short link from the reply above
    ($previousLane), and a line on down from its avatar when another reply, its own replies, or "Show more", follows
    ($hasNext). $spines carry lines from further up on past it, and $railLane runs the commit's own rail on past,
    solid, when its author's run goes on below the thread (see thread-links). A deleted reply keeps its place with a
    dashed ring. In a feed the $last closes the commit's row with its hairline. On a commit page it has one only when
    another commit's row comes next ($hairline): under the replies of a commit above the commit, say, but not at the
    foot of the page.

    Past the web's depth a reply is flattened: it lines up under the reply it answers, with no lines of its own
    ($links), and says who it answers on a line under its name ($replyingTo), as the web does.

    On a commit page a deleted reply's own replies open in place too ($tombstones), with "N replies" under its ring.

    $top is the space above the avatar, 10pt, less for the first reply in a feed, which sits under the commit row's
    bottom padding (see ThreadRows::FirstReplyTop). The lines beside it are placed from the avatar, so they move with it.

    A commit page draws its replies $muted, smaller and in muted ink, so the commit they answer stays the main thing.

    A reply its author carried on in replies to themselves links to that thread, as on the web (see show-thread): when
    devnull says so, or once its replies opened in place showed it ($threadsContinued, see Concerns\ExpandsThreads).

    A reply you just sent that devnull doesn't have yet is staged: dimmed, with the staged chip and no actions.

    A reply's replies button opens them under it ($repliesInPlace): on a commit page at every depth, in a feed only for
    the commit's own replies, and a reply's replies there open its page. Tapping the reply itself opens its page. The
    rows fade in as they open and out as they close ($fades): drawn see-through and nudged up while they're in
    $fadedRows (see Concerns\ExpandsThreads), and eased to where they belong. Only what's beside the rail fades, so
    the commit's rail running past never blinks.
--}}
@php($previousLane ??= null)
@php($muted ??= false)
@php($hairline ??= ! $muted)
@php($railLane ??= null)
@php($indent ??= 0)
@php($spines ??= [])
@php($links ??= true)
@php($replyingTo ??= null)
@php($tombstones ??= false)
@php($avatarLine = 76 + $indent)
@php($repliesInPlace ??= false)
@php($fades ??= false)
@php($top ??= 10)
@php($faded = $fades && in_array($reply->id, $fadedRows ?? [], true))
@if ($reply->isDeleted())
    @php($opened = $tombstones && array_key_exists($reply->id, $threads ?? []) && ! in_array($reply->id, $closingThreads ?? [], true))
    <native:row native:key="thread-reply-{{ $reply->id }}" ref="thread-reply-{{ $reply->id }}" class="w-full bg-theme-surface">
        <native:column :width="64" />
        <native:column class="flex-1 {{ $faded ? 'opacity-0' : '' }}" :animate-duration="$fades ? \App\Devnull\ThreadRows::Fade : null" :translate-y="$fades ? ($faded ? -8 : 0) : null">
            <native:row class="w-full pr-4 pt-[{{ $top }}] gap-2 {{ $last ? 'pb-3' : 'pb-1' }} {{ $indent > 0 ? 'pl-['.$indent.']' : '' }}">
                <native:column :width="24" :height="24" class="rounded-full">
                    @include('native.partials.dashed-ring', ['size' => 24, 'lane' => \App\Devnull\Lane::Rail])
                </native:column>
                <native:column class="flex-1 gap-1">
                    <native:row class="w-full min-h-[24] items-center">
                        <native:text class="flex-1 text-sm text-theme-ink-muted"><native:text font="mono" class="text-xs">[deleted]</native:text> This commit was deleted.</native:text>
                    </native:row>
                    @if ($tombstones && $reply->repliesCount > 0)
                        <native:pressable ref="tombstone-replies-{{ $reply->id }}" @press="toggleReplies('{{ $reply->id }}')" class="-ml-2 px-2 py-1" a11y-label="{{ $opened ? 'Hide replies' : \App\Devnull\Display::count($reply->repliesCount, 'reply', 'replies') }}">
                            <native:row class="gap-1 items-center">
                                <native:icon :ios="($opened ? \App\Icons\Lucide::ChevronDown : \App\Icons\Lucide::ChevronRight)->ios()" :android="($opened ? \App\Icons\Lucide::ChevronDown : \App\Icons\Lucide::ChevronRight)->android()" :size="14" class="text-theme-primary" />
                                <native:text font="medium" class="text-[13px] text-theme-primary">{{ $opened ? 'Hide replies' : \App\Devnull\Display::count($reply->repliesCount, 'reply', 'replies') }}</native:text>
                            </native:row>
                        </native:pressable>
                    @endif
                </native:column>
            </native:row>
            @if ($last && $hairline)
                <native:column ref="thread-end-{{ $reply->id }}" class="w-full h-[1] border-b border-theme-hairline" />
            @endif
        </native:column>
        @include('native.partials.thread-links')
    </native:row>
@else
    @php($commit = $reply)
    <native:pressable native:key="thread-reply-{{ $reply->id }}" ref="thread-reply-{{ $reply->id }}" @press="openCommit('{{ $reply->id }}')" class="w-full {{ ($selectedCommit ?? null) === $reply->id ? 'bg-theme-wash' : 'bg-theme-surface' }}">
        <native:row class="w-full">
            <native:column :width="64" />
            <native:column class="flex-1 {{ $faded ? 'opacity-0' : '' }}" :animate-duration="$fades ? \App\Devnull\ThreadRows::Fade : null" :translate-y="$fades ? ($faded ? -8 : 0) : null">
                <native:row class="w-full pr-4 pt-[{{ $top }}] gap-2 {{ $last ? 'pb-3' : '' }} {{ $indent > 0 ? 'pl-['.$indent.']' : '' }}">
                    <native:pressable @press="openProfile('{{ $reply->author->username }}')" a11y-label="{{ $reply->author->name }}'s profile">
                        @include('native.partials.rail-avatar', ['user' => $reply->author, 'lane' => $reply->lane(), 'size' => 24, 'staged' => false])
                    </native:pressable>
                    <native:column class="flex-1 gap-1 {{ $reply->isStaged() ? 'opacity-[0.6]' : '' }}">
                        <native:row class="w-full min-h-[24] gap-[6] items-center">
                            <native:text font="semibold" class="{{ $muted ? 'text-[13px] text-theme-ink-muted' : 'text-sm text-theme-ink' }} flex-shrink" :max-lines="1">{{ $reply->author->name }}</native:text>
                            <native:text class="text-[13px] text-theme-ink-muted">·</native:text>
                            <native:text font="mono" class="text-xs text-theme-ink-muted flex-shrink-0">{{ \App\Devnull\Display::relativeTime($reply->createdAt) }}</native:text>
                            @unless ($reply->isApproved())
                                @include('native.partials.status-chip', ['status' => $reply->status])
                            @endunless
                        </native:row>
                        @if ($replyingTo !== null)
                            <native:row ref="replying-to-{{ $reply->id }}" class="w-full gap-1 items-center">
                                <native:icon :ios="\App\Icons\Lucide::CornerDownRight->ios()" :android="\App\Icons\Lucide::CornerDownRight->android()" :size="12" class="text-theme-ink-muted" />
                                <native:text font="mono" class="flex-1 text-xs text-theme-ink-muted" :max-lines="1">{{ $replyingTo }}</native:text>
                            </native:row>
                        @endif
                        @include('native.partials.commit-body', ['size' => $muted ? 'text-sm' : 'text-[15px]'])
                        @include('native.partials.commit-media', ['withEmbeds' => false])
                        @if ($reply->continuationId !== null || in_array($reply->id, $threadsContinued ?? [], true))
                            @include('native.partials.show-thread')
                        @endif
                        @unless ($reply->isStaged())
                            @include('native.partials.commit-actions', ['expandable' => $repliesInPlace, 'withQuote' => false, 'compact' => $muted])
                        @endunless
                    </native:column>
                </native:row>
                @if ($last && $hairline)
                    <native:column ref="thread-end-{{ $reply->id }}" class="w-full h-[1] border-b border-theme-hairline" />
                @endif
            </native:column>
            @include('native.partials.thread-links')
        </native:row>
    </native:pressable>
@endif
