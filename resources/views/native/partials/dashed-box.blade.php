{{--
    A 1pt dashed border, 8pt rounded, drawn over the box it's placed last in, as the web's `border border-dashed`.
--}}
@php($colour ??= 'border-theme-hairline')
<native:column class="absolute top-0 bottom-0 left-0 right-0 rounded-lg border border-dashed {{ $colour }}" />
