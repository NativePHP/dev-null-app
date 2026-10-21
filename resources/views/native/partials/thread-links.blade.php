{{--
    The lines beside a reply in a feed's thread (see thread-reply), drawn last in its row so they sit on top: the
    commit's rail running on past, the elbow or the link from the reply above, and the line on down to the next one.
--}}
@if ($railLane !== null)
    @include('native.partials.rail', ['previous' => $railLane])
@endif

@if ($spine !== null)
    @include('native.partials.dashed-line', ['position' => 'top-0 bottom-0', 'left' => 75.5, 'lane' => $spine])
@endif

@if ($previousLane === null)
    @include('native.partials.thread-elbow', ['lane' => $lane, 'from' => $indent > 0 ? 75.5 : 31.5, 'to' => 64 + $indent])
@else
    @include('native.partials.dashed-line', ['position' => 'top-0 h-[6]', 'left' => $avatarLine - 0.5, 'lane' => $previousLane])
@endif

@if ($hasNext)
    {{-- Down to the next reply, or to the top of Show more's dot: 12pt under it, its padding and the hairline below. --}}
    @include('native.partials.dashed-line', ['position' => 'top-[38] '.($more !== null ? 'bottom-[34]' : 'bottom-0'), 'left' => $avatarLine - 0.5, 'lane' => $reply->lane()])
@endif
