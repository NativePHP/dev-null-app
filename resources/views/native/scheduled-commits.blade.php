@use('App\Devnull\Display')
@use('App\Icons\Lucide')

{{--
    Laid out like the web's Scheduled commits page: its lines on what the list is, and the timezone its times are in,
    then a row each, split by hairlines. A row says where it's at and when it's due, then its body (its start, with
    Show more, when it's long), its poll's options and how many images it has. One still waiting has the web's Commit
    now and its Cancel, a bin, and its line on what happens next. One submitted says what became of it. No press-*
    feedback on the rows: it would keep the screen from scrolling.
--}}
<native:column class="w-full h-full bg-theme-surface">
    <native:scroll-view class="w-full flex-1 bg-theme-surface">
        <native:column class="w-full pb-10">
            @include('native.partials.error')
            @include('native.partials.refresh-failed')

            <native:column ref="scheduled-intro" class="w-full px-4 py-4 gap-2">
                <native:row class="w-full gap-3 items-start">
                    <native:text class="flex-1 text-sm leading-relaxed text-theme-ink-muted">Only you can see this list. At the scheduled time, each commit goes through the usual review process. Scheduling is not approval.</native:text>
                    <native:icon :ios="Lucide::LockKeyhole->ios()" :android="Lucide::LockKeyhole->android()" :size="16" class="text-theme-ink-muted" a11y-label="Private" />
                </native:row>
                <native:text ref="scheduled-timezone" font="mono" class="text-xs text-theme-ink-muted">Times in {{ $timezone }}</native:text>
            </native:column>
            <native:column class="w-full h-[1] bg-theme-hairline" />

            @if (! $loaded)
                @if ($error === null)
                    @foreach (range(1, 2) as $skeleton)
                        <native:column ref="loading-{{ $skeleton }}" class="w-full px-4 py-4 gap-[10] opacity-[0.5]" animate-duration="900" :animate-loop="true" a11y-label="Loading">
                            <native:column class="w-1/4 h-[12] rounded bg-theme-wash" />
                            <native:column class="w-2/5 h-[10] rounded bg-theme-wash" />
                            <native:column class="w-4/5 h-[12] rounded bg-theme-wash" />
                        </native:column>
                        <native:column class="w-full h-[1] bg-theme-hairline" />
                    @endforeach
                @endif
            @elseif ($scheduled === [])
                <native:column ref="scheduled-empty" class="w-full items-center px-8 py-14 gap-3">
                    <native:icon :ios="Lucide::Clock3->ios()" :android="Lucide::Clock3->android()" :size="24" class="text-theme-ink-muted" />
                    <native:text font="medium" class="text-base text-center text-theme-ink">No scheduled commits</native:text>
                    <native:text class="text-sm text-center text-theme-ink-muted">Choose Schedule in the composer to submit later.</native:text>
                </native:column>
            @else
                @foreach ($scheduled as $item)
                    <native:column ref="scheduled-{{ $item->id }}" class="w-full px-4 py-4 gap-3">
                        <native:row class="w-full gap-3 items-start">
                            <native:column class="flex-1 gap-1">
                                <native:text ref="scheduled-status-{{ $item->id }}" font="medium" class="text-sm text-theme-ink">{{ $item->label($now) }}</native:text>
                                <native:text ref="scheduled-at-{{ $item->id }}" font="mono" class="text-xs text-theme-ink-muted">{{ Display::scheduleTime($item->scheduledAt, $timezone) }}</native:text>
                            </native:column>
                            @if ($item->isWaiting())
                                @php($when = Display::scheduleTime($item->scheduledAt, $timezone))
                                <native:button ref="commit-now-{{ $item->id }}" variant="secondary" size="sm" :loading="$busy === $item->id && $busyWith === 'commit'" :disabled="$busy !== null" @press="commitNow('{{ $item->id }}')" a11y-label="Commit now instead of at {{ $when }}">Commit now</native:button>
                                <native:pressable ref="cancel-{{ $item->id }}" @press="cancel('{{ $item->id }}')" :disabled="$busy !== null" a11y-label="Cancel the commit scheduled for {{ $when }}">
                                    <native:column class="min-w-[44] h-[36] items-center justify-center {{ $busy !== null ? 'opacity-[0.5]' : '' }}">
                                        <native:icon :ios="Lucide::Trash2->ios()" :android="Lucide::Trash2->android()" :size="18" class="text-theme-ink-muted" />
                                    </native:column>
                                </native:pressable>
                            @endif
                        </native:row>

                        @if ($item->content !== [])
                            <native:column class="w-full">
                                @include('native.partials.commit-body', ['commit' => $item, 'size' => 'text-[15px]'])
                            </native:column>
                        @endif

                        @if ($item->pollOptions)
                            <native:column ref="scheduled-poll-{{ $item->id }}" class="w-full gap-2" a11y-label="Poll options">
                                @foreach ($item->pollOptions as $option)
                                    <native:text class="w-full rounded-md border border-theme-hairline px-3 py-2 text-[15px] text-theme-ink">{{ $option }}</native:text>
                                @endforeach
                            </native:column>
                        @endif

                        @if ($item->imageCount > 0)
                            <native:text ref="scheduled-images-{{ $item->id }}" class="text-sm text-theme-ink-muted">{{ Display::count($item->imageCount, 'image', 'images') }}</native:text>
                        @endif

                        @if ($item->isWaiting())
                            <native:text class="text-xs leading-relaxed text-theme-ink-muted">{{ $item->isDue($now) ? 'Waiting for the scheduler. If submission fails, it is retried automatically.' : 'Private until submitted. You can commit it now or cancel it before submission.' }}</native:text>
                        @endif
                    </native:column>
                    <native:column class="w-full h-[1] bg-theme-hairline" />
                @endforeach

                @if ($nextPage !== null)
                    <native:column class="w-full px-4 pt-2">
                        <native:button ref="scheduled-more" variant="ghost" size="lg" @press="loadMore" class="w-full">Show more</native:button>
                    </native:column>
                @endif
            @endif
        </native:column>
    </native:scroll-view>
</native:column>
