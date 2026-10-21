{{--
    A commit on the graph rail, laid out like the web's commit card: [rail | name, handle and time, body, media, actions].
    The author's avatar is the node on the rail. A commit carrying on its author's run ($continuesRun) gets a lane dot
    instead. The segment above the node takes the previous author's lane, the one below takes this author's.
    $focused draws the commit a page is about: a larger body, its git date, and no link to itself.

    Rows in a list carry no press-* feedback. EDGE draws it with a zero-distance DragGesture (NodePressFeedbackModifier
    in nativephp/mobile), and on iOS that gesture keeps the list from scrolling when a drag starts on the row.
--}}
@php($previousLane ??= null)
@php($continuesRun ??= false)
@php($focused ??= false)
@if ($commit->isDeleted())
    @include('native.partials.commit-tombstone')
@elseif ($focused)
    <native:row ref="focused-commit" class="w-full bg-theme-surface">
        @include('native.partials.commit-row-content')
    </native:row>
@else
    <native:pressable native:key="{{ $rowKey ?? 'commit-'.$commit->id }}" ref="commit-{{ $commit->id }}" @press="openCommit('{{ $commit->id }}')" class="w-full flex-row bg-theme-surface">
        @include('native.partials.commit-row-content')
    </native:pressable>
@endif
