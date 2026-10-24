{{--
    A reply in the thread under a commit in a feed, like a reply in the web's thread: a 24pt avatar lined up with the
    commit's text, the name and time, the body and its actions. The thread hangs off the rail in dashes of each
    author's lane, as upstream's #121 draws it: the first reply on an elbow from the rail ($lane is the commit's), each
    later one on a short link from the reply above ($previousLane), and a line on down from its avatar when another
    reply, or "Show more", follows ($hasNext). $railLane runs the commit's own rail on past, solid, when its author's
    run goes on below the thread. A deleted reply keeps its place with a dashed ring. In a feed the $last closes the
    commit's row with its hairline. On a commit page nothing follows the thread, so there's no hairline.

    On a commit page a deleted reply's own replies open in place ($tombstones), since it has no page of their own,
    one level in ($indent 32), hanging off a dashed spine from its ring as the web nests them. $spine carries the
    thread's line on past a nested reply when the deleted one has another reply after it.

    The last reply carries the thread's "Show more" when there's more ($more, what it presses), with the line from its
    avatar running down to it.

    A commit page draws its replies $muted, smaller and in muted ink, so the commit they answer stays the main thing.

    A reply you just sent that devnull doesn't have yet is staged: dimmed, with the staged chip and no actions.
--}}
@php($previousLane ??= null)
@php($muted ??= false)
@php($railLane ??= null)
@php($indent ??= 0)
@php($spine ??= null)
@php($tombstones ??= false)
@php($more ??= null)
@php($avatarLine = 76 + $indent)
@if ($reply->isDeleted())
    @php($opened = $tombstones && array_key_exists($reply->id, $tombstoneReplies ?? []))
    <native:row native:key="thread-reply-{{ $reply->id }}" ref="thread-reply-{{ $reply->id }}" class="w-full bg-theme-surface">
        <native:column :width="64" />
        <native:column class="flex-1">
            <native:row class="w-full pr-4 pt-[10] gap-2 {{ $last && $more === null ? 'pb-3' : 'pb-1' }} {{ $indent > 0 ? 'pl-['.$indent.']' : '' }}">
                <native:column :width="24" :height="24" class="rounded-full">
                    @include('native.partials.dashed-ring', ['size' => 24, 'lane' => \App\Devnull\Lane::Rail])
                </native:column>
                <native:column class="flex-1 gap-1">
                    <native:row class="w-full min-h-[24] items-center">
                        <native:text class="flex-1 text-sm text-theme-ink-muted"><native:text font="mono" class="text-xs">[deleted]</native:text> This commit was deleted.</native:text>
                    </native:row>
                    @if ($tombstones && $reply->repliesCount > 0)
                        <native:pressable ref="tombstone-replies-{{ $reply->id }}" @press="toggleTombstone('{{ $reply->id }}')" class="-ml-2 px-2 py-1" a11y-label="{{ $opened ? 'Hide replies' : \App\Devnull\Display::count($reply->repliesCount, 'reply', 'replies') }}">
                            <native:row class="gap-1 items-center">
                                <native:icon :ios="($opened ? \App\Icons\Lucide::ChevronDown : \App\Icons\Lucide::ChevronRight)->ios()" :android="($opened ? \App\Icons\Lucide::ChevronDown : \App\Icons\Lucide::ChevronRight)->android()" :size="14" class="text-theme-primary" />
                                <native:text font="medium" class="text-[13px] text-theme-primary">{{ $opened ? 'Hide replies' : \App\Devnull\Display::count($reply->repliesCount, 'reply', 'replies') }}</native:text>
                            </native:row>
                        </native:pressable>
                    @endif
                </native:column>
            </native:row>
            @if ($more !== null)
                @include('native.partials.thread-more', ['commit' => $thread ?? $reply])
            @endif
            @if ($last && ! $muted)
                <native:column ref="thread-end-{{ $reply->id }}" class="w-full h-[1] bg-theme-hairline" />
            @endif
        </native:column>
        @include('native.partials.thread-links')
        @if ($opened && ($tombstoneReplies[$reply->id] ?? []) !== [])
            {{-- The spine its own replies hang off, down from its ring. --}}
            @include('native.partials.dashed-line', ['position' => 'top-[38] bottom-0', 'left' => $avatarLine - 0.5, 'lane' => \App\Devnull\Lane::Rail])
        @endif
    </native:row>
@else
    @php($commit = $reply)
    <native:pressable native:key="thread-reply-{{ $reply->id }}" ref="thread-reply-{{ $reply->id }}" @press="openCommit('{{ $reply->id }}')" class="w-full bg-theme-surface">
        <native:row class="w-full">
            <native:column :width="64" />
            <native:column class="flex-1">
                <native:row class="w-full pr-4 pt-[10] gap-2 {{ $last && $more === null ? 'pb-3' : '' }} {{ $indent > 0 ? 'pl-['.$indent.']' : '' }}">
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
                        @include('native.partials.commit-body', ['size' => $muted ? 'text-sm' : 'text-[15px]'])
                        @include('native.partials.commit-media', ['withEmbeds' => false])
                        @unless ($reply->isStaged())
                            @include('native.partials.commit-actions', ['expandable' => false, 'withQuote' => false, 'compact' => $muted])
                        @endunless
                    </native:column>
                </native:row>
                @if ($more !== null)
                    @include('native.partials.thread-more', ['commit' => $thread ?? $reply])
                @endif
                @if ($last && ! $muted)
                    <native:column ref="thread-end-{{ $reply->id }}" class="w-full h-[1] bg-theme-hairline" />
                @endif
            </native:column>
            @include('native.partials.thread-links')
        </native:row>
    </native:pressable>
@endif
