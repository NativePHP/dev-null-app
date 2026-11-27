{{--
    The /dev/null wordmark inside a run of text (see commit-body for how it's drawn and why). $afterSpace says it
    follows a space, $classes are extra classes, and $tight false leaves out the tighter tracking, as a piece of a row
    of words (see body-run). Nothing comes between this comment and the first directive, and the file ends without a
    line break, since it sits inside whitespace-pre-wrap text.
--}}@php($afterSpace ??= false)
@php($tight ??= true)
<native:text font="semibold" class="{{ $classes ?? '' }} font-semibold {{ $tight ? 'tracking-tight' : '' }}">@if ($afterSpace)&#x200B;@endif/&#x2060;dev<native:text text="/" class="text-outline text-theme-lane-0" />&#x2060;null</native:text>