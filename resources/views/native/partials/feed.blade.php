{{--
    A feed's rows from App\Devnull\Timeline: day markers, the last-visit marker and commits on the rail. Under a commit
    hangs its thread, inside its row's hairline, as on the web: the newest reply devnull previews, with "Show more"
    when there are others, or the replies opened in place, with "Show more" to the commit's page when there are more
    than they show. The commit's rail runs on past them when its author's run goes on below.

    Given a $rowCache (see App\Devnull\RowCache), commits and replies that haven't changed since the last frame are
    drawn from it rather than built again. Given a $rowWindow (see App\Devnull\RowWindow), rows far from the screen
    go as placeholders with the keys their partials give them, so the phone keeps each one's place and height.
--}}
@php
    $rowCache ??= null;
    $rowWindow ??= null;
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
            $opened = $threads[$feedCommit->id] ?? null;
            $threadReplies = $opened ?? ($feedCommit->isDeleted() ? [] : $feedCommit->replyPreview?->replies ?? []);
            $feedDraws = $rowWindow?->place($feedIndex, 1 + count($threadReplies)) ?? true;
            $hasMoreReplies = $opened === null
                ? ! $feedCommit->isDeleted() && ($feedCommit->replyPreview?->hasMore ?? false)
                : in_array($feedCommit->id, $threadsWithMore ?? [], true);
            $morePress = $opened === null ? "showMoreReplies('{$feedCommit->id}')" : "openReplies('{$feedCommit->id}')";
            $railLane = $row->continuesNext ? $feedCommit->lane() : null;
            $commitRow = [
                'commit' => $feedCommit,
                'previousLane' => $row->previousLane,
                'continuesRun' => $row->continuesRun,
                'continuesNext' => $row->continuesNext,
                'threadBelow' => $threadReplies !== [],
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
        @foreach ($threadReplies as $index => $threadReply)
            @php
                $replyRow = [
                    'reply' => $threadReply,
                    'lane' => $feedCommit->lane(),
                    'previousLane' => $index === 0 ? null : $threadReplies[$index - 1]->lane(),
                    'hasNext' => ! $loop->last || $hasMoreReplies,
                    'railLane' => $railLane,
                    'thread' => $feedCommit,
                    'more' => $loop->last && $hasMoreReplies ? $morePress : null,
                    'last' => $loop->last,
                ];
            @endphp
            @if (! $feedDraws)
                @php
                    $rowWindow->placeholder('thread-reply-'.$threadReply->id);
                @endphp
            @elseif ($rowCache === null)
                @include('native.partials.thread-reply', $replyRow)
            @else
                @php
                    $rowScope = array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]);
                    $rowCache->include('reply-'.$threadReply->id, \App\Devnull\RowInputs::for($threadReply, $rowScope), 'native.partials.thread-reply', $replyRow, $rowScope);
                @endphp
            @endif
        @endforeach
    @endif
@endforeach
