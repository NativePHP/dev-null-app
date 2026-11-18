@use('App\Devnull\Display')
@use('App\Devnull\NotificationFilter')

{{--
    The inbox, as the web lays it out: one list of the kinds you chose on the settings screen, with a day marker
    where the day changes and HEAD@{last visit} under what's new. Each group sits on its own rail in the lane of
    whoever it's about, ending in a dot (see partials/notification).

    Keyed per choice of kinds, so a change starts a fresh list at the top. Pull to refresh asks devnull again, and the
    end of the list asks for the next page.
--}}
<native:column class="w-full h-full bg-theme-background">
    @include('native.partials.error')
    @include('native.partials.refresh-failed')
    @include('native.partials.push-prompt')

    <native:list
        native:key="notifications-{{ $listKey }}"
        ref="notifications"
        class="w-full flex-1 bg-theme-surface"
        on-refresh="load"
        on-end-reached="loadMore"
        :end-reached-buffer="5"
    >
        @if (! $loaded && $error === null)
            <native:column ref="loading-notifications" class="w-full items-center py-16 bg-theme-surface" a11y-label="Loading notifications">
                <native:activity-indicator />
            </native:column>
        @endif

        @if ($loaded && $notifications === [] && $error === null)
            <native:column ref="empty-state" class="w-full items-center px-8 py-16 gap-3 bg-theme-surface">
                <native:text font="mono" class="text-[13px] text-center text-theme-ink-muted">{{ NotificationFilter::command($kinds) }}</native:text>
                <native:text class="text-[15px] text-center leading-relaxed text-theme-ink max-w-[320]">{{ NotificationFilter::emptyMessage($kinds) }}</native:text>
                @if (count($kinds) !== count(NotificationFilter::Kinds))
                    <native:pressable ref="show-all" @press="showAll" a11y-label="Show all notifications">
                        <native:text class="text-[15px] text-theme-primary">Show all notifications</native:text>
                    </native:pressable>
                @endif
            </native:column>
        @endif

        @foreach ($notifications as $index => $notification)
            @php($before = $notifications[$index - 1] ?? null)
            @if ($notification->id === $lastVisitBefore)
                @include('native.partials.last-visit-marker', ['label' => 'Notifications above are new since your last visit', 'muted' => true])
            @endif
            @if ($notification->createdAt && ($before?->createdAt === null || Display::dayKey($before->createdAt) !== Display::dayKey($notification->createdAt)))
                @include('native.partials.day-marker', ['label' => Display::dayLabel($notification->createdAt), 'lane' => null])
            @endif
            @include('native.partials.notification', [
                'notification' => $notification,
                'isNew' => $notification->isNewSince($readAt),
                'withoutBottomBorder' => $lastVisitBefore !== null && ($notifications[$index + 1] ?? null)?->id === $lastVisitBefore,
            ])
        @endforeach

        @if ($end === 'more')
            <native:row ref="loading-more" class="w-full py-4 justify-center">
                <native:activity-indicator size="small" />
            </native:row>
        @elseif ($end === 'oldest')
            {{-- The web's footer: a hollow root node in the oldest one's lane, with no line into it. --}}
            @include('native.partials.rail-end', ['message' => "That's the oldest notification. There's nothing before it.", 'lane' => end($notifications)->actor->lane(), 'ref' => 'oldest-notification'])
        @endif
    </native:list>
</native:column>
