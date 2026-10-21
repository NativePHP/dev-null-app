{{--
    A 1pt vertical line in dashes of $lane's colour, as the web's `border-l border-dashed`: 4pt dashes with 3pt gaps,
    starting at its top and running as far as $position (top, bottom or height classes) lets it. $left is its x in points.
--}}
<native:column class="absolute {{ $position }} left-[{{ $left }}] w-[1] border-l border-dashed border-theme-{{ \App\Devnull\Lane::token($lane) }}" />
