{{--
    A group of notifications on the inbox's Rail Gray rail, as the web draws it, each kind in its own shape.

    A reply, a quote or a mention is someone's own words: their avatar rings the node in their lane (a link to them,
    when they act alone), their name and handle head the row with the type's icon in their lane beside the time, what
    they did runs under it as a link to the commit, and their commit follows. A quote may bring the commit of yours
    it quotes along, boxed as the web's card — who wrote it, when, the words, opening the commit quoted — a tombstone
    once it was deleted or can no longer be seen, and nothing at all when it didn't.

    A star, a follow or a level-up runs the other way: the type's icon in the node, the people stacked up top with the
    time (a level chip instead, when devnull sent it; a follow of theirs alone offers the follow back), then who did
    what — a star of a commit with images shows the first of them beside it whatever the commit says, and one that
    says nothing but its images is worded so and shows no box, your own commit sits under a star or a verdict in a
    muted hairline box, and a level-up says only that you reached one, since the API tells no more. A moderator's
    verdict is muted, a rejection gives their reason.

    A row that arrived since your last visit says so to a screen reader: its type icon, or the avatar in its node,
    carries "New" — the only place the row's own label would say it, which pressables don't yet announce.

    A group of more than one opens in place from a Commit Blue toggle ("5 stars"), listing each event behind a Rail
    Gray guide line, each follower with their own follow back. A group of followers has none of its own, as the web
    leaves it out, since it could only follow one of them back, and a tap on it opens the list rather than the latest
    follower's profile.

    No press-* feedback: on iOS it holds any drag that starts on the row, and these rows cover the whole list.
    The name's run carries font-semibold as well as the font: inside a sentence, NativeUITextRenderer sets each run's
    weight from font_weight over the face it was given, so the face alone draws regular.
--}}
@php($icon = \App\Icons\Lucide::forNotification($notification->type))
@php($laneToken = \App\Devnull\Lane::token($notification->actor->lane()))
@php($iconColour = $notification->isModeration() ? 'text-theme-ink-muted' : 'text-theme-'.$laneToken)
@php($open = array_key_exists($notification->id, $expanded))
@php($failed = in_array($notification->id, $failedGroups, true))
@php($noun = $notification->groupNoun())
@php($actorsCommit = $notification->isActorsCommit())
@php($level = $notification->isLevelUp() && $notification->level !== null && $notification->rank !== null ? $notification : null)
@php($images = $notification->starredImages())
<native:pressable native:key="notification-{{ $notification->id }}" ref="notification-{{ $notification->id }}" @press="open('{{ $notification->id }}')" class="w-full bg-theme-surface" a11y-label="{{ $isNew ? 'New. ' : '' }}{{ $notification->isModeration() || $notification->isLevelUp() ? $notification->sentence() : $notification->actor->name.' '.$notification->sentence() }}">
    <native:row class="w-full">
        <native:column :width="64" />
        <native:column class="flex-1">
            <native:column class="w-full py-3 pr-4 gap-1">
                @if ($actorsCommit)
                    {{-- Their name and handle head the row, the type's icon in their lane beside the time, as the web draws it; the avatar rings the node below. --}}
                    <native:row class="w-full min-h-[28] gap-[6] items-center">
                        <native:text ref="actor-name-{{ $notification->id }}" :text="$notification->actor->name" font="semibold" class="font-semibold text-[15px] text-theme-ink flex-shrink" :max-lines="1" @press="openActor('{{ $notification->actor->username }}')" a11y-label="{{ $notification->actor->handle() }}'s profile" />
                        <native:text :text="$notification->actor->handle()" font="mono" class="text-[13px] text-theme-ink-muted" :max-lines="1" @press="openActor('{{ $notification->actor->username }}')" />
                        <native:spacer />
                        <native:icon :ios="$icon->ios()" :android="$icon->android()" :size="16" class="{{ $iconColour }} flex-shrink-0" a11y-label="{{ $isNew ? 'New' : '' }}" />
                        <native:text font="mono" class="text-[13px] text-theme-ink-muted flex-shrink-0">{{ \App\Devnull\Display::relativeTime($notification->createdAt) }}</native:text>
                    </native:row>

                    {{-- What they did, as the web's link to the commit. --}}
                    <native:text ref="action-{{ $notification->id }}" class="w-full text-sm text-theme-ink-muted" @press="open('{{ $notification->id }}')" a11y-label="{{ $notification->sentence() }}">{{ ucfirst($notification->sentence()) }}</native:text>

                    @if ($notification->body !== null)
                        <native:text ref="excerpt-{{ $notification->id }}" class="w-full text-[15px] leading-relaxed text-theme-ink" :max-lines="6">{{ $notification->body }}</native:text>
                    @endif

                    {{-- The commit of yours this one quotes, boxed as the web's card: who wrote it and when it was, and the words, opening the commit quoted. One that was deleted, or can no longer be seen, is a tombstone that opens nothing. --}}
                    @if ($notification->showsQuotedCommit())
                        @include('native.partials.quoted-commit', ['commit' => $notification->commit])
                    @endif
                @elseif ($notification->isModeration())
                    <native:row class="w-full min-h-[40] gap-2 items-center">
                        <native:column ref="verdict-{{ $notification->id }}" class="px-2 py-[2] rounded-full {{ $notification->isRejection() ? 'bg-theme-destructive/10' : 'bg-theme-wash' }}">
                            <native:text font="mono" class="text-xs {{ $notification->isRejection() ? 'text-theme-destructive' : 'text-theme-ink-muted' }}">{{ $notification->type }}</native:text>
                        </native:column>
                        <native:spacer />
                        <native:text font="mono" class="text-[13px] text-theme-ink-muted flex-shrink-0">{{ \App\Devnull\Display::relativeTime($notification->createdAt) }}</native:text>
                    </native:row>

                    <native:text class="w-full text-[15px] text-theme-ink">{{ $notification->sentence() }}.</native:text>

                    @if ($notification->body !== null)
                        <native:column ref="your-commit-{{ $notification->id }}" class="w-full mt-1 px-3 py-2 rounded-lg border border-theme-hairline">
                            <native:text class="w-full text-[15px] leading-relaxed text-theme-ink-muted" :max-lines="3">{{ $notification->body }}</native:text>
                        </native:column>
                    @endif

                    @if ($notification->isRejection())
                        <native:column ref="rejection-{{ $notification->id }}" class="w-full mt-1 gap-1">
                            @if ($notification->reason)
                                <native:text class="w-full text-sm text-theme-ink whitespace-pre-line"><native:text font="mono" class="text-[13px] text-theme-ink-muted">reason: </native:text>{{ $notification->reason }}</native:text>
                            @endif
                            <native:text class="w-full text-sm text-theme-ink-muted">Only you can see this update and your rejected commit.</native:text>
                        </native:column>
                    @endif
                @else
                    {{-- A star, a follow or a level-up: the people, then who did what. --}}
                    <native:row class="w-full min-h-[40] gap-2 items-center">
                        @if ($level !== null)
                            <native:column ref="level-chip-{{ $notification->id }}" class="px-2 py-[2] rounded-md border border-theme-{{ $laneToken }}/25 bg-theme-{{ $laneToken }}/10">
                                <native:text font="mono-medium" class="text-xs text-theme-{{ $laneToken }}">Lv. {{ $notification->level }} · {{ $notification->rank }}</native:text>
                            </native:column>
                        @else
                            @include('native.partials.stacked-avatars', ['id' => $notification->id, 'actors' => $notification->actors, 'more' => max(0, $notification->actorsCount - count($notification->actors))])
                        @endif
                        <native:spacer />
                        <native:text font="mono" class="text-[13px] text-theme-ink-muted flex-shrink-0">{{ \App\Devnull\Display::relativeTime($notification->createdAt) }}</native:text>
                        @if ($notification->isFollow() && $notification->actorsCount === 1 && $notification->actorIsFollowed !== null)
                            @include('native.partials.follow-back', ['actor' => $notification->actor, 'isFollowing' => $notification->actorIsFollowed])
                        @endif
                    </native:row>

                    <native:row class="w-full gap-3 items-start">
                        @if ($notification->isLevelUp())
                            <native:text class="flex-1 text-[15px] text-theme-ink">{{ $notification->sentence() }}.</native:text>
                        @else
<native:text class="flex-1 text-[15px] text-theme-ink whitespace-pre-wrap"><native:text ref="actor-name-{{ $notification->id }}" :text="$notification->actor->name" font="semibold" class="font-semibold" @press="openActor('{{ $notification->actor->username }}')" a11y-label="{{ $notification->actor->handle() }}'s profile" />
<native:text text=" " />
@if ($notification->others() === '')
<native:text :text="$notification->actor->handle()" font="mono" class="text-[13px] text-theme-ink-muted" @press="openActor('{{ $notification->actor->username }}')" />
<native:text :text="' '.$notification->sentence()" />
@else
<native:text :text="$notification->others().' '.$notification->sentence()" />
@endif
</native:text>
                        @endif
                        {{-- The first of the commit's images, as the web always shows it beside a star, whatever the commit says. --}}
                        @if ($images !== [])
                            <native:image ref="starred-image-{{ $notification->id }}" :src="\App\Devnull\ImageCache::src($images[0]->url)" :width="40" :height="40" :fit="2" class="rounded-md border border-theme-hairline bg-theme-wash flex-shrink-0" />
                        @endif
                    </native:row>

                    @if ($notification->isLevelUp() && $notification->rankDescription !== null)
                        <native:text ref="level-description-{{ $notification->id }}" class="w-full text-sm text-theme-ink-muted">{{ $notification->rankDescription }}</native:text>
                    @endif

                    @if ($notification->isStar() && $notification->body !== null && ! $notification->isImageOnlyStar())
                        <native:column ref="your-commit-{{ $notification->id }}" class="w-full mt-1 px-3 py-2 rounded-lg border border-theme-hairline">
                            <native:text class="w-full text-[15px] leading-relaxed text-theme-ink-muted" :max-lines="3">{{ $notification->body }}</native:text>
                        </native:column>
                    @endif
                @endif

                @if ($notification->isGrouped())
                    <native:pressable ref="group-{{ $notification->id }}" @press="toggleGroup('{{ $notification->id }}')" class="-ml-2 mt-1 px-2 py-[2]" a11y-label="{{ $open ? 'Hide '.$noun : $notification->eventCount.' '.$noun }}">
                        <native:row class="gap-1 items-center">
                            <native:icon :ios="($open ? \App\Icons\Lucide::ChevronDown : \App\Icons\Lucide::ChevronRight)->ios()" :android="($open ? \App\Icons\Lucide::ChevronDown : \App\Icons\Lucide::ChevronRight)->android()" :size="14" class="text-theme-primary" />
                            <native:text font="medium" class="text-[13px] text-theme-primary">{{ $open ? 'Hide '.$noun : $notification->eventCount.' '.$noun }}</native:text>
                        </native:row>
                    </native:pressable>

                    @if ($open || $failed)
                        @include('native.partials.notification-group', ['group' => $notification, 'items' => $expanded[$notification->id] ?? []])
                    @endif
                @endif
            </native:column>
            <native:column class="w-full h-[1] bg-theme-hairline" />
        </native:column>

        {{-- The Rail Gray rail runs the whole inbox, through each node. --}}
        @if ($hasPrevious)
            <native:column class="absolute top-0 left-[31] w-[2] h-[32] bg-theme-rail" />
        @endif
        <native:column class="absolute top-[32] bottom-0 left-[31] w-[2] bg-theme-rail" />
        <native:column class="absolute top-[14] left-[14]">
            @if ($actorsCommit)
                {{-- Their avatar in the node, in their lane's ring: a link to them when they act alone, saying "New" to a screen reader when it is. --}}
                @if ($notification->isGrouped())
                    <native:stack ref="node-{{ $notification->id }}" :width="36" :height="36" center class="rounded-full border-2 {{ $isNew ? 'border-theme-primary' : 'border-theme-'.$laneToken }} bg-theme-surface">
                        @include('native.partials.avatar', ['user' => $notification->actor, 'size' => 28, 'alt' => $isNew ? 'New' : null])
                    </native:stack>
                @else
                    <native:pressable ref="node-avatar-{{ $notification->id }}" @press="openActor('{{ $notification->actor->username }}')" a11y-label="{{ $notification->actor->handle() }}'s profile">
                        <native:stack ref="node-{{ $notification->id }}" :width="36" :height="36" center class="rounded-full border-2 {{ $isNew ? 'border-theme-primary' : 'border-theme-'.$laneToken }} bg-theme-surface">
                            @include('native.partials.avatar', ['user' => $notification->actor, 'size' => 28, 'alt' => $isNew ? 'New' : null])
                        </native:stack>
                    </native:pressable>
                @endif
            @else
                <native:stack ref="node-{{ $notification->id }}" :width="36" :height="36" center class="rounded-full border-2 {{ $isNew ? 'border-theme-primary' : 'border-theme-rail' }} bg-theme-surface">
                    <native:icon :ios="$icon->ios($notification->isStar())" :android="$icon->android($notification->isStar())" :size="16" class="{{ $iconColour }}" a11y-label="{{ $isNew ? 'New' : '' }}" />
                </native:stack>
            @endif
        </native:column>
    </native:row>
</native:pressable>
