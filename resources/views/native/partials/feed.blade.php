{{--
    A feed's rows from App\Devnull\Timeline: day markers, the last-visit marker, who to follow ($whoToFollow, see
    partials/who-to-follow) and commits on the rail. A commit's replies stay hidden until its replies button opens them
    in place (see Concerns\ExpandsThreads). Then its thread hangs under it, inside its row's hairline, a page at a time
    with "Show more" under the last, and a reply's own replies opened under it one step in (see App\Devnull\ThreadRows).
    The commit's rail runs on past them when its author's run goes on below.

    Given a $rowCache (see App\Devnull\RowCache), commits and replies that haven't changed since the last frame are
    drawn from it rather than built again. Given a $rowWindow (see App\Devnull\RowWindow), rows far from the screen
    go as placeholders with the keys their partials give them, so the phone keeps each one's place and height.
--}}
@php
    $rowCache ??= null;
    $rowWindow ??= null;
    $whoToFollow ??= null;
@endphp
@foreach ($rows as $feedIndex => $row)
    @if ($row->isDayMarker())
        @if ($rowWindow?->place($feedIndex) ?? true)
            @include('native.partials.day-marker', ['label' => $row->label, 'lane' => $row->previousLane])
        @else
            @php
                $rowWindow->placeholder('day-'.$row->label);
            @endphp
        @endif
    @elseif ($row->isWhoToFollow())
        @if ($rowWindow?->place($feedIndex) ?? true)
            @include('native.partials.who-to-follow', ['whoToFollow' => $whoToFollow])
        @else
            @php
                $rowWindow->placeholder('who-to-follow');
            @endphp
        @endif
    @elseif ($row->isLastVisitMarker())
        @if ($rowWindow?->place($feedIndex) ?? true)
            @include('native.partials.last-visit-marker', ['lane' => $row->previousLane])
        @else
            @php
                $rowWindow->placeholder('last-visit');
            @endphp
        @endif
    @else
        @php
            $feedCommit = $row->commit;
            $threadRows = $feedCommit->isDeleted() ? [] : \App\Devnull\ThreadRows::under($feedCommit, $threads ?? [], $threadCursors ?? [], $threadShown ?? [], $row->continuesNext ? $feedCommit->lane() : null);
            $feedDraws = $rowWindow?->place($feedIndex, 1 + count($threadRows)) ?? true;
            $commitRow = [
                'commit' => $feedCommit,
                'previousLane' => $row->previousLane,
                'continuesRun' => $row->continuesRun,
                'continuesNext' => $row->continuesNext,
                'threadBelow' => $threadRows !== [],
            ];
        @endphp
        @if (! $feedDraws)
            @php
                $rowWindow->placeholder(($feedCommit->isDeleted() ? 'tombstone-' : 'commit-').$feedCommit->id);
            @endphp
        @elseif ($rowCache === null)
            @include('native.partials.commit-row', $commitRow)
        @else
            @php
                $rowScope = array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]);
                $rowCache->include('commit-'.$feedCommit->id, \App\Devnull\RowInputs::for($feedCommit, $rowScope), 'native.partials.commit-row', $commitRow, $rowScope);
            @endphp
        @endif
        @foreach ($threadRows as $threadRow)
            @if (! $feedDraws)
                @php
                    $rowWindow->placeholder($threadRow['key']);
                @endphp
            @elseif ($rowCache === null || $threadRow['reply'] === null)
                @include($threadRow['partial'], $threadRow['data'])
            @else
                @php
                    $rowScope = array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]);
                    $rowCache->include('reply-'.$threadRow['reply']->id, \App\Devnull\RowInputs::for($threadRow['reply'], $rowScope), $threadRow['partial'], $threadRow['data'], $rowScope);
                @endphp
            @endif
        @endforeach
    @endif
@endforeach
