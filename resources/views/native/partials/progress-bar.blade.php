{{--
    A rounded bar filled $percent of the way, as the web's achievements draw progress. EDGE has no percentage widths,
    so the fill and the rest share the row by flex-grow. A grow of 0 counts as 1 here, so each side is left out when
    it would have none.
--}}
@php($height ??= 6)
@php($fill ??= 'bg-theme-ink-muted/40')
<native:row ref="{{ $ref }}" class="w-full h-[{{ $height }}] rounded-full bg-theme-wash" a11y-label="{{ $label }}">
    @if ($percent > 0)
        <native:column :flexGrow="$percent" :flexBasis="0" class="h-full rounded-full {{ $fill }}" />
    @endif
    @if ($percent < 100)
        <native:column :flexGrow="100 - $percent" :flexBasis="0" />
    @endif
</native:row>
