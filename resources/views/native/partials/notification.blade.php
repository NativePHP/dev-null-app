{{--
    A group of notifications on the inbox's Rail Gray rail, as the web draws it. Its node is a 36pt hollow circle
    holding the type's icon in the person's lane (a moderator's stays muted), ringed in Commit Blue when it's new
    ($isNew). A line of stacked avatars and the time, then the sentence: the person's name, their handle when they act
    alone, otherwise "and N others", and what they did. Someone else's words follow at 15pt like a commit; your own
    commit sits under a star or a verdict in a muted hairline box. A rejection gives the moderator's reason. A level-up
    names nobody either: a chip in your lane with the level and rank, and what the rank means under the sentence.

    A group of more than one opens in place from a Commit Blue toggle ("5 stars"), listing each event behind a Rail
    Gray guide line, each follower with their own follow back. A group of followers has none of its own, as the web
    leaves it out, since it could only follow one of them back, and a tap on it opens the list rather than the latest
    follower's profile.

    No press-* feedback: on iOS it holds any drag that starts on the row, and these rows cover the whole list.
    The name's run carries font-semibold as well as the font: inside a sentence, NativeUITextRenderer sets each run's
    weight from font_weight over the face it was given, so the face alone draws regular.
--}}
@php($icon = \App\Icons\Lucide::forNotification($notification->type))
@php($lane = \App\Devnull\Lane::token($notification->actor->lane()))
@php($iconColour = $notification->isModeration() ? 'text-theme-ink-muted' : 'text-theme-'.$lane)
@php($open = array_key_exists($notification->id, $expanded))
@php($failed = in_array($notification->id, $failedGroups, true))
@php($noun = $notification->groupNoun())
<native:pressable native:key="notification-{{ $notification->id }}" ref="notification-{{ $notification->id }}" @press="open('{{ $notification->id }}')" class="w-full bg-theme-surface" a11y-label="{{ $isNew ? 'New. ' : '' }}{{ $notification->namesNobody() ? $notification->sentence() : $notification->actor->name.' '.$notification->sentence() }}">
    <native:row class="w-full">
        <native:column :width="64" />
        <native:column class="flex-1">
            <native:column class="w-full py-3 pr-4 gap-1">
                <native:row class="w-full min-h-[40] gap-2 items-center">
                    @if ($notification->isLevelUp())
                        @if ($notification->levelLabel() !== null)
                            <native:column ref="level-{{ $notification->id }}" class="px-2 py-[2] rounded-full border border-theme-{{ $lane }}/25 bg-theme-{{ $lane }}/8">
                                <native:text font="mono-medium" class="text-xs text-theme-{{ $lane }}">{{ $notification->levelLabel() }}</native:text>
                            </native:column>
                        @endif
                    @elseif ($notification->isModeration())
                        <native:column ref="verdict-{{ $notification->id }}" class="px-2 py-[2] rounded-full {{ $notification->isRejection() ? 'bg-theme-destructive/10' : 'bg-theme-wash' }}">
                            <native:text font="mono" class="text-xs {{ $notification->isRejection() ? 'text-theme-destructive' : 'text-theme-ink-muted' }}">{{ $notification->type }}</native:text>
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

                @if ($notification->namesNobody())
                    <native:text class="w-full text-[15px] text-theme-ink">{{ $notification->sentence() }}.</native:text>
                @else
<native:text class="w-full text-[15px] text-theme-ink whitespace-pre-wrap"><native:text ref="actor-name-{{ $notification->id }}" :text="$notification->actor->name" font="semibold" class="font-semibold" @press="openActor('{{ $notification->actor->username }}')" a11y-label="{{ $notification->actor->handle() }}'s profile" />
<native:text text=" " />
@if ($notification->others() === '')
<native:text :text="$notification->actor->handle()" font="mono" class="text-[13px] text-theme-ink-muted" @press="openActor('{{ $notification->actor->username }}')" />
<native:text :text="' '.$notification->sentence()" />
@else
<native:text :text="$notification->others().' '.$notification->sentence()" />
@endif
</native:text>
                @endif

                @if ($notification->body !== null && ! $notification->isFollow())
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
                        <native:text class="w-full text-sm text-theme-ink-muted">Only you can see this update and your rejected commit.</native:text>
                    </native:column>
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
            <native:stack ref="node-{{ $notification->id }}" :width="36" :height="36" center class="rounded-full border-2 {{ $isNew ? 'border-theme-primary' : 'border-theme-rail' }} bg-theme-surface">
                <native:icon :ios="$icon->ios($notification->isStar())" :android="$icon->android($notification->isStar())" :size="16" class="{{ $iconColour }}" />
            </native:stack>
        </native:column>
    </native:row>
</native:pressable>
