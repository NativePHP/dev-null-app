{{--
    One filter's page in the Notifications pager (see App\NativeComponents\Notifications::page()): the filter you're on,
    or the first few groups kept for one beside it, ready for a swipe.

    Each filter has its own list, and one you leave starts again at the top of a fresh one: a list kept while every row
    changed under its scroll offset drew blank on iOS. Only the filter you're on asks for its next page, since a page
    laid out off screen still shows its rows, and every page keeps pull to refresh, since iOS builds a new list when a
    list gains or loses it.
--}}
<native:column native:key="page-{{ $page['type'] }}" class="w-full h-full bg-theme-surface">
    <native:list
        native:key="notifications-{{ $page['type'] }}-{{ $page['listKey'] }}"
        :ref="$page['showing'] ? 'notifications' : 'notifications-'.$page['type']"
        class="w-full flex-1 bg-theme-surface"
        on-refresh="load"
        :on-end-reached="$page['showing'] ? 'loadMore' : null"
        :end-reached-buffer="5"
    >
        @if (! $page['loaded'] && $page['error'] === null)
            <native:column ref="loading-notifications" class="w-full items-center py-16 bg-theme-surface" a11y-label="Loading notifications">
                <native:activity-indicator />
            </native:column>
        @endif

        @if ($page['loaded'] && $page['notifications'] === [] && $page['error'] === null)
            <native:column ref="empty-state" class="w-full items-center px-8 py-16 gap-3 bg-theme-surface">
                <native:text font="mono" class="text-[13px] text-center text-theme-ink-muted">{{ $page['type'] === 'all' ? '$ cat /dev/notifications' : '$ cat /dev/notifications | grep '.$page['type'] }}</native:text>
                <native:text class="text-[15px] text-center leading-relaxed text-theme-ink max-w-[320]">{{ $types[$page['type']][1] }}</native:text>
                @if ($page['type'] !== 'all')
                    <native:pressable ref="show-all" @press="show('all')" a11y-label="Show all notifications">
                        <native:text class="text-[15px] text-theme-primary">Show all notifications</native:text>
                    </native:pressable>
                @endif
            </native:column>
        @endif

        @foreach ($page['notifications'] as $index => $notification)
            @php($previous = $page['notifications'][$index - 1] ?? null)
            @if ($notification->id === $page['lastVisitBefore'])
                @include('native.partials.last-visit-marker', ['lane' => \App\Devnull\Lane::Rail, 'label' => 'Notifications above are new since your last visit'])
            @endif
            @if ($notification->createdAt && ($previous?->createdAt === null || \App\Devnull\Display::dayKey($previous->createdAt) !== \App\Devnull\Display::dayKey($notification->createdAt)))
                @include('native.partials.day-marker', ['label' => \App\Devnull\Display::dayLabel($notification->createdAt), 'lane' => $previous === null ? null : \App\Devnull\Lane::Rail])
            @endif
            @include('native.partials.notification', [
                'notification' => $notification,
                'hasPrevious' => $previous !== null,
                'isNew' => $notification->isNewSince($readAt),
                'expanded' => $page['expanded'],
                'expandedCursors' => $page['expandedCursors'],
                'failedGroups' => $page['failedGroups'],
            ])
        @endforeach

        @if ($page['end'] === 'more')
            <native:row ref="loading-more" class="w-full py-4 justify-center">
                <native:activity-indicator size="small" />
            </native:row>
        @elseif ($page['end'] === 'oldest')
            <native:row ref="oldest-notification" class="w-full bg-theme-surface">
                <native:column :width="64" />
                <native:column class="flex-1 pt-[21] pb-10 pr-4">
                    <native:text class="text-sm text-theme-ink-muted">That's the oldest notification. There's nothing before it.</native:text>
                </native:column>
                @include('native.partials.rail', ['previous' => \App\Devnull\Lane::Rail, 'lane' => \App\Devnull\Lane::Rail, 'node' => 'root'])
            </native:row>
        @endif
    </native:list>
</native:column>
