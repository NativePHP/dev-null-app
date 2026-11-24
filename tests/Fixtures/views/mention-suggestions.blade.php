{{-- The mention list alone, for MentionSuggestionsTest: the partial with whatever options the test passes. --}}
<native:column ref="fixture-root" class="w-full flex-1">
    @include('native.partials.mention-suggestions', ['field' => 'body', ...$options])
</native:column>
