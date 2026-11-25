{{-- A commit's place in its author's thread, like the web's ThreadPositionBadge: "2/5" in mono on a muted wash. --}}
<native:column ref="thread-position-{{ $commit->id }}" class="ml-1 px-2 py-[2] rounded-full bg-theme-wash flex-shrink-0" a11y-label="Commit {{ str_replace(['/', '+'], [' of ', ' or more'], $threadPosition) }} in this thread">
    <native:text font="mono" class="text-xs text-theme-ink-muted">{{ $threadPosition }}</native:text>
</native:column>
