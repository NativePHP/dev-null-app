{{--
    Where a thread leaves the rail, as the web's thread elbow from #121: a dashed line in the commit's lane down from
    the rail ($from), round a 12pt corner, and across to the first reply's avatar ($to), level with its middle. A
    nested reply's elbow leaves the spine of the reply above it the same way. EDGE has no dashed borders, so each dash
    is placed along the way (see Display::elbowDashes), and the ones on the corner are turned to follow it, as in
    dashed-ring. Any corner radius makes EDGE clip a node to its shape, so the last dash is cut 1pt short of the avatar.
--}}
@php($colour = 'bg-theme-'.\App\Devnull\Lane::token($lane))
@php($from ??= 31.5)
@php($to ??= 64)
<native:column ref="thread-elbow" class="absolute top-0 left-0 w-[{{ $to - 1 }}] h-[{{ \App\Devnull\Display::ElbowLevel + 1 }}] rounded-[0.5]">
    @foreach (\App\Devnull\Display::elbowDashes($from, $to) as $dash)
        @if ($dash['rotate'] === -90.0)
            <native:column class="absolute top-[{{ $dash['y'] - 0.5 }}] left-[{{ round($dash['x'] - 2, 2) }}] w-[4] h-[1] {{ $colour }}" />
        @elseif ($dash['rotate'] === 0.0)
            <native:column class="absolute top-[{{ round($dash['y'] - 2, 2) }}] left-[{{ $dash['x'] - 0.5 }}] w-[1] h-[4] {{ $colour }}" />
        @else
            <native:column class="absolute top-[{{ round($dash['y'] - 2, 2) }}] left-[{{ round($dash['x'] - 0.5, 2) }}] w-[1] h-[4] {{ $colour }}" :rotate="$dash['rotate']" />
        @endif
    @endforeach
</native:column>
