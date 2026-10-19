{{--
    The web's New remote picker: a field to find a developer by name or handle, then who matches, the ones you can
    message first. Until you type it says what to do, and a term nobody matches answers in git's voice.
--}}
<native:column class="w-full h-full bg-theme-surface">
    @include('native.partials.error')

    <native:column class="w-full px-4 py-3 bg-theme-surface">
        @include('native.partials.people-search', ['placeholder' => 'Message a developer', 'autofocus' => true])
    </native:column>
    <native:column class="w-full h-[1] bg-theme-hairline" />

    <native:list ref="candidates" class="w-full flex-1 bg-theme-surface">
        @if ($term === '')
            <native:column ref="people-hint" class="w-full px-8 py-12 items-center bg-theme-surface">
                <native:text class="text-sm text-center text-theme-ink-muted">Type a name or handle to find a developer</native:text>
            </native:column>
        @elseif ($searched === $term && $candidates === [])
            <native:column ref="people-none" class="w-full px-8 py-12 items-center bg-theme-surface">
                <native:text font="mono" class="text-[13px] text-center text-theme-ink-muted" :max-lines="1">fatal: nothing matches '{{ $term }}'</native:text>
            </native:column>
        @elseif ($searched === $term)
            @foreach ($candidates as $candidate)
                @include('native.partials.candidate-row', ['user' => $candidate['user'], 'reason' => $candidate['reason'], 'press' => 'pick'])
            @endforeach
        @endif
    </native:list>
</native:column>
