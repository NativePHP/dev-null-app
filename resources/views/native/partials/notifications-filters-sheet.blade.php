{{--
    The More chip's sheet, standing in for the web phone layout's dropdown menu: the rest of its filter row, one chip
    a line, each carrying its own selected state as its checkboxes do. It stays open across a tap, as the menu does,
    and the list behind starts over for the selection each tap makes. Always mounted, so opening it changes nothing
    but its own visibility (see colour-sheet).
--}}
<native:bottom-sheet ref="filters-sheet" :visible="$moreOpen" detents="medium" @dismiss="moreFiltersClosed">
    @if ($moreOpen)
        <native:column class="w-full px-5 pt-6 pb-8 gap-3 items-start">
            @foreach ($moreFilters as $category)
                @include('native.partials.notification-filter', ['category' => $category])
            @endforeach
        </native:column>
    @endif
</native:bottom-sheet>
