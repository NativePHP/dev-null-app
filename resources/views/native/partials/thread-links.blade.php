{{--
    The lines beside a reply in a thread (see thread-reply and App\Devnull\ThreadRows), drawn last in its row so they
    sit on top: the commit's rail running on past, the lines from further up running on past ($spines, each from its
    `top` or the row's top), the elbow or the link from the reply above, and the line on down to the next one. A
    flattened reply, past the web's depth, has no links of its own ($links).
--}}
@php($spines ??= [])
@php($links ??= true)
@if ($railLane !== null)
    @include('native.partials.rail', ['previous' => $railLane])
@endif

@foreach ($spines as $spineLine)
    @include('native.partials.dashed-line', ['position' => (isset($spineLine['top']) ? 'top-['.$spineLine['top'].']' : 'top-0').' bottom-0', 'left' => $spineLine['left'], 'lane' => $spineLine['lane']])
@endforeach

@if ($links)
    @if ($previousLane === null)
        @include('native.partials.thread-elbow', ['lane' => $lane, 'from' => $indent > 0 ? 43.5 + $indent : 31.5, 'to' => 64 + $indent, 'level' => $top + 12])
    @else
        @include('native.partials.dashed-line', ['position' => 'top-0 h-['.($top - 4).']', 'left' => $avatarLine - 0.5, 'lane' => $previousLane])
    @endif

    @if ($hasNext)
        {{-- From 4pt under the avatar down to the next row, which carries it on. --}}
        @include('native.partials.dashed-line', ['position' => 'top-['.($top + 28).'] bottom-0', 'left' => $avatarLine - 0.5, 'lane' => $reply->lane()])
    @endif
@endif
