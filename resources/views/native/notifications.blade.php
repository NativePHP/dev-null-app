{{--
    The inbox. Under the title, the web's filter row: the three its phone layout shows, and its More, which opens the
    sheet with the rest (see notifications-filters-sheet). Several are on at once; each chip carries its selected
    state natively, so a screen reader says which are on, as the web's aria-pressed does — a chip's press is the
    server's to answer, its state handed back (see NativeUIChipRenderer). The More chip says whether any of its
    filters are on the same way, with the ellipsis the web's menu button draws. The row wraps at larger type sizes
    (flex-wrap, which the row carries on both phones), so no chip is ever clipped short.

    The list below is one list, whatever's on: the selection starts it afresh at the top, so a list kept while every
    row changed under its scroll offset drew blank on iOS, and it keeps its key while it refreshes, pages and polls.

    A poll's arrivals wait in the pill pinned over the top of the list, under the filters, so it's in reach however
    far down you've read. It floats over the rows rather than sitting in the list, so the list's height, rows and
    scroll hold still while it comes and goes — only a tap on it, which puts what it holds on top and goes back to
    the top, changes the list. Its place is always in the tree, hidden until there's something to show, so nothing
    is added beside a scrolled list at runtime. Keyed so the banners above coming and going change none of it.
--}}
<native:column class="w-full h-full bg-theme-background">
    @include('native.partials.error')
    @include('native.partials.refresh-failed')

    <native:column native:key="notifications-filters" class="w-full bg-theme-surface">
        <native:row ref="filters" class="w-full px-4 py-[10] gap-2 flex-wrap justify-center items-center">
            @foreach ($quickFilters as $category)
                @include('native.partials.notification-filter', ['category' => $category])
            @endforeach
            @php($moreOn = array_intersect($moreFilters, $enabled) !== [])
            <native:chip
                ref="filter-more"
                :selected="$moreOn"
                :ios="\App\Icons\Lucide::Ellipsis->ios()"
                :android="\App\Icons\Lucide::Ellipsis->android()"
                @press="openMoreFilters"
                a11y-label="More notification filters"
            />
        </native:row>
        <native:column class="w-full h-[1] bg-theme-hairline" />
    </native:column>

    <native:column class="w-full flex-1">
        @include('native.partials.notifications-page')

        <native:row ref="new-notifications-slot" class="absolute top-[10] left-0 right-0 justify-center {{ $stagedCount === 0 ? 'hidden' : '' }}">
            @if ($stagedCount > 0)
                <native:button ref="new-notifications" variant="primary" size="sm" :ios="\App\Icons\Lucide::ArrowUp->ios()" :android="\App\Icons\Lucide::ArrowUp->android()" @press="showNewNotifications" a11y-label="Show {{ $newLabel }}">{{ $newLabel }}</native:button>
            @endif
        </native:row>
    </native:column>

    @include('native.partials.notifications-filters-sheet')
</native:column>
