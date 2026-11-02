{{--
    What an org picker's search found, inside its list: a hint before anything is typed, the web's line when nothing
    matches, or the people, picked or not, with why anyone who can't be picked can't.
--}}
@if ($term === '')
    <native:column ref="people-hint" class="w-full px-8 py-12 items-center bg-theme-surface">
        <native:text class="text-sm text-center text-theme-ink-muted">Type a name or handle</native:text>
    </native:column>
@elseif ($searched === $term && $rows === [])
    <native:column ref="people-none" class="w-full px-8 py-12 items-center bg-theme-surface">
        <native:text font="mono" class="text-[13px] text-center text-theme-ink-muted" :max-lines="1">fatal: nothing matches '{{ $term }}'</native:text>
    </native:column>
@elseif ($searched === $term)
    @foreach ($rows as $row)
        @include('native.partials.candidate-row', $row + ['press' => 'toggle'])
    @endforeach
@endif
