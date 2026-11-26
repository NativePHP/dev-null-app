@use('App\Devnull\Display')
@use('App\Devnull\Lane')
@use('App\Icons\Lucide')

{{--
    A group of notifications, as the web's inbox draws it: on its own rail in the lane of whoever it's about, running
    from its node down to a dot, like a lone commit on the Log.

    Someone else's commit (a reply, a quote, a mention, a first commit) leads with them: their avatar ringed in their
    lane is the node, then their name, handle and the time, what they did in a muted line, and their words.

    Everything else has a 36pt hollow circle in the lane with the kind's icon (a moderator's stays muted), a row of
    stacked avatars and the time (or a chip for a verdict or a level-up), then the sentence: the person's name, their
    handle when they act alone, both names for two, "and N others" for more, and what they did. Your own commit sits
    under a star or a verdict in a muted hairline box. A rejection gives the moderator's reason, a timeout its reason,
    how long and when posting resumes, a level-up what the rank means.

    A group of more than one opens in place from a Commit Blue toggle ("5 stars"), listing each event behind a Rail
    Gray guide line (see notification-group). A group of followers has no follow back of its own, as on the web.
    The toggle's chevron turns down while the group is open, and back as it closes, as the Log's replies button does.
    It carries the animation shut as well as open: iOS only animates a change on a node that already had it. Opening,
    it turns a frame after the row grows, as the notifications start to fade in ($turned): iOS animates everything
    about a node that changes in the frame its animated props do, and the row growing in that frame moved the
    chevron down and back up as it turned. A group fading out ($closingGroups) is still drawn, with the toggle
    already back to its count.

    In the iOS workspace the row whose commit is open beside the list ($selected) takes the wash, as the Log marks its
    open commit.

    No press-* feedback: on iOS it holds any drag that starts on the row, and these rows cover the whole list.
    The name's run carries font-semibold as well as the font: inside a sentence, NativeUITextRenderer sets each run's
    weight from font_weight over the face it was given, so the face alone draws regular.
--}}
@php($laneNumber = $notification->actor->lane())
@php($lane = Lane::token($laneNumber))
@php($icon = Lucide::forNotification($notification->type))
@php($iconColour = $notification->isModeration() ? 'text-theme-ink-muted' : 'text-theme-'.$lane)
@php($drawn = array_key_exists($notification->id, $expanded))
@php($open = $drawn && ! in_array($notification->id, $closingGroups ?? [], true))
@php($turned = $open && ! in_array('group-'.$notification->id, $fadedItems ?? [], true))
@php($failed = in_array($notification->id, $failedGroups, true))
@php($noun = $notification->groupNoun())
@php($second = $notification->secondActor())
@php($withoutBottomBorder ??= false)
@php($time = Display::relativeTime($notification->createdAt))
<native:pressable native:key="notification-{{ $notification->id }}" ref="notification-{{ $notification->id }}" @press="open('{{ $notification->id }}')" class="w-full {{ ($selected ?? false) ? 'bg-theme-wash' : 'bg-theme-surface' }}" a11y-label="{{ $isNew ? 'New. ' : '' }}{{ $notification->namesNobody() ? $notification->sentence() : $notification->actor->name.' '.$notification->sentence() }}">
    <native:row class="w-full min-h-[96]">
        <native:column :width="64" />
        <native:column class="flex-1">
            <native:column class="w-full py-3 pr-4 gap-1">
                @if ($notification->isActorsCommit())
                    <native:row class="w-full gap-[6] items-center">
                        <native:text ref="actor-name-{{ $notification->id }}" font="semibold" class="flex-shrink text-[15px] font-semibold text-theme-ink" :max-lines="1" @press="openActor('{{ $notification->actor->username }}')" a11y-label="{{ $notification->actor->handle() }}'s profile">{{ $notification->actor->name }}</native:text>
                        <native:text font="mono" class="flex-shrink text-[13px] text-theme-ink-muted" :max-lines="1" @press="openActor('{{ $notification->actor->username }}')">{{ $notification->actor->handle() }}</native:text>
                        <native:spacer />
                        <native:text font="mono" class="text-[13px] text-theme-ink-muted flex-shrink-0">{{ $time }}</native:text>
                    </native:row>
                    <native:text ref="action-{{ $notification->id }}" class="w-full text-sm text-theme-ink-muted">{{ ucfirst($notification->sentence()) }}</native:text>
                @else
                    <native:row class="w-full min-h-[40] gap-2 items-center">
                        @if ($notification->isLevelUp())
                            @if ($notification->levelLabel() !== null)
                                <native:column ref="level-{{ $notification->id }}" class="px-2 py-[2] rounded-md border border-theme-{{ $lane }}/25 bg-theme-{{ $lane }}/8">
                                    <native:text font="mono-medium" class="text-xs text-theme-{{ $lane }}">{{ $notification->levelLabel() }}</native:text>
                                </native:column>
                            @endif
                        @elseif ($notification->isModeration())
                            <native:column ref="verdict-{{ $notification->id }}" class="px-2 py-[2] rounded-sm {{ $notification->isRejection() ? 'bg-theme-destructive/10' : 'bg-theme-wash' }}">
                                <native:text font="mono" class="text-xs {{ $notification->isRejection() ? 'text-theme-destructive' : 'text-theme-ink-muted' }}">{{ $notification->type }}</native:text>
                            </native:column>
                        @else
                            @include('native.partials.stacked-avatars', ['id' => $notification->id, 'actors' => $notification->actors, 'more' => max(0, $notification->actorsCount - count($notification->actors))])
                        @endif
                        <native:spacer />
                        <native:text font="mono" class="text-[13px] text-theme-ink-muted flex-shrink-0">{{ $time }}</native:text>
                        @if ($notification->isFollow() && $notification->actorsCount === 1 && $notification->actorIsFollowed !== null)
                            @include('native.partials.follow-back', ['actor' => $notification->actor, 'isFollowing' => $notification->actorIsFollowed])
                        @endif
                    </native:row>

                    @if ($notification->namesNobody())
                        <native:text class="w-full text-[15px] text-theme-ink">{{ $notification->sentence() }}.</native:text>
                    @else
<native:text class="w-full text-[15px] text-theme-ink whitespace-pre-wrap"><native:text ref="actor-name-{{ $notification->id }}" :text="$notification->actor->name" font="semibold" class="font-semibold" @press="openActor('{{ $notification->actor->username }}')" a11y-label="{{ $notification->actor->handle() }}'s profile" />
<native:text text=" " />
@if ($second !== null)
<native:text text="and " />
<native:text ref="second-actor-{{ $notification->id }}" :text="$second->name" font="semibold" class="font-semibold" @press="openActor('{{ $second->username }}')" a11y-label="{{ $second->handle() }}'s profile" />
<native:text :text="' '.$notification->sentence()" />
@elseif ($notification->others() === '')
<native:text :text="$notification->actor->handle()" font="mono" class="text-[13px] text-theme-ink-muted" @press="openActor('{{ $notification->actor->username }}')" />
<native:text :text="' '.$notification->sentence()" />
@else
<native:text :text="$notification->others().' '.$notification->sentence()" />
@endif
</native:text>
                    @endif
                @endif

                @if ($notification->isTimeout() && $notification->timeoutReason !== null)
                    <native:column ref="timeout-{{ $notification->id }}" class="w-full mt-1 gap-1">
                        <native:text class="w-full text-sm text-theme-ink-muted whitespace-pre-wrap"><native:text text="Reason: " /><native:text :text="$notification->timeoutReason" class="text-theme-ink" /><native:text text=" · Duration: " /><native:text :text="(string) $notification->timeoutDuration" class="text-theme-ink" /></native:text>
                        @if ($notification->timeoutEndsAt !== null)
                            <native:text class="w-full text-sm text-theme-ink-muted">Posting resumes {{ Display::fullDateTime($notification->timeoutEndsAt) }}.</native:text>
                        @endif
                    </native:column>
                @elseif ($notification->body !== null && ! $notification->isFollow() && ! $notification->isTimeout())
                    @if ($notification->isAboutYourCommit())
                        <native:column ref="your-commit-{{ $notification->id }}" class="w-full mt-1 px-3 py-2 rounded-lg border border-theme-hairline">
                            <native:text class="w-full text-[15px] leading-relaxed text-theme-ink-muted" :max-lines="3">{{ $notification->body }}</native:text>
                        </native:column>
                    @else
                        <native:text ref="excerpt-{{ $notification->id }}" class="w-full text-[15px] leading-relaxed text-theme-ink" :max-lines="6">{{ $notification->body }}</native:text>
                    @endif
                @endif

                @if ($notification->isLevelUp() && $notification->rankDescription !== null)
                    <native:text ref="rank-description-{{ $notification->id }}" class="w-full mt-1 text-sm text-theme-ink-muted">{{ $notification->rankDescription }}</native:text>
                @endif

                @if ($notification->isRejection())
                    <native:column ref="rejection-{{ $notification->id }}" class="w-full mt-1 gap-1">
                        @if ($notification->reason)
                            <native:text class="w-full text-sm text-theme-ink whitespace-pre-line"><native:text font="mono" class="text-[13px] text-theme-ink-muted">reason: </native:text>{{ $notification->reason }}</native:text>
                        @endif
                        <native:text class="w-full text-sm text-theme-ink-muted">{{ $notification->organization === null ? 'Only you can see this update and your rejected commit.' : "Only @{$notification->organization}'s members can see this update and its rejected commit." }}</native:text>
                    </native:column>
                @endif

                @if ($notification->isGrouped())
                    <native:pressable ref="group-{{ $notification->id }}" @press="toggleGroup('{{ $notification->id }}')" class="-ml-2 mt-1 px-2 py-[2]" a11y-label="{{ $open ? 'Hide '.$noun : $notification->eventCount.' '.$noun }}">
                        <native:row class="gap-1 items-center">
                            <native:icon ref="group-chevron-{{ $notification->id }}" :ios="Lucide::ChevronRight->ios()" :android="Lucide::ChevronRight->android()" :size="14" class="text-theme-primary" :rotate="$turned ? 90 : 0" :animate-duration="\App\NativeComponents\Notifications::GroupFade" />
                            <native:text font="medium" class="text-[13px] text-theme-primary">{{ $open ? 'Hide '.$noun : $notification->eventCount.' '.$noun }}</native:text>
                        </native:row>
                    </native:pressable>

                    @if ($drawn || $failed)
                        @include('native.partials.notification-group', ['group' => $notification, 'items' => $expanded[$notification->id] ?? []])
                    @endif
                @endif
            </native:column>
            @unless ($withoutBottomBorder)
                <native:column class="w-full h-[1] border-b border-theme-hairline" />
            @endunless
        </native:column>

        {{-- Its own rail, drawn last so it sits on top: the node, a line in the lane down to a dot 20pt above the bottom. --}}
        @if ($notification->isActorsCommit())
            @include('native.partials.rail', [
                'previous' => null,
                'lane' => $laneNumber,
                'tail' => $laneNumber,
                'node' => 'avatar',
                'user' => $notification->actor,
                'press' => "openActor('{$notification->actor->username}')",
                'endsWithDot' => true,
            ])
        @else
            <native:column class="absolute top-[32] bottom-[20] left-[31] w-[2] bg-theme-{{ $lane }}" />
            @include('native.partials.rail-node', ['lane' => $laneNumber, 'nodeRef' => 'end-dot-'.$notification->id, 'position' => 'absolute bottom-[11] left-[23]'])
            <native:column class="absolute top-[14] left-[14]">
                <native:stack ref="node-{{ $notification->id }}" :width="36" :height="36" center class="rounded-full border-2 border-theme-{{ $lane }} bg-theme-surface">
                    <native:icon :ios="$icon->ios($notification->isStar())" :android="$icon->android($notification->isStar())" :size="16" class="{{ $iconColour }}" />
                </native:stack>
            </native:column>
        @endif
    </native:row>
</native:pressable>
