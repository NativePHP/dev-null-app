{{--
    One run of a commit's paragraph (see commit-body for how each kind looks). It sits inside whitespace-pre-wrap
    text, so every line ends in a tag or a directive, whose newline PHP drops, and nothing comes between this comment
    and the first directive. $linkIndex numbers a link among the body's links, $afterSpace says the run follows a
    space, for the wordmark, and $static leaves hashtags as plain text.

    Drawn as a piece of a row of words (see BodyFlow), $pieceClasses holds the piece's own size and spacing, and
    $pieceInk the ink for a run without its own colour. They go on the run's own text rather than a wrapper, so the
    row measures each word in its own font, and a semibold word like the wordmark isn't cut short. Inside a
    paragraph both are empty, and runs take the paragraph's.
--}}@php($pieceClasses ??= '')
@php($pieceInk ??= '')
@if ($run->isMention())
<native:text :text="$run->text" font="semibold" class="{{ $pieceClasses }} font-semibold text-theme-primary" @press="{{ $run->isOrganization ? 'openOrganization' : 'openProfile' }}('{{ $run->username }}')" />
@elseif ($run->isCode)
<native:text :text="$run->text" font="mono" class="{{ $pieceClasses }} {{ $pieceInk }} text-[13px] bg-theme-wash" />
@elseif ($run->link?->isNamed())
<native:text font="mono" class="{{ $pieceClasses }} text-[13px] text-theme-primary" @press="openLink('{{ $commit->id }}', {{ $linkIndex }})"><native:text :text="$run->link->prefix" class="text-theme-ink-muted" />&#x2060;<native:text :text="$run->link->shown" /></native:text>
@elseif ($run->link)
<native:text :text="$run->link->shown" class="{{ $pieceClasses }} text-theme-primary" @press="openLink('{{ $commit->id }}', {{ $linkIndex }})" />
@elseif ($run->isColor())
<native:text class="{{ $pieceClasses }} {{ $pieceInk }}"><native:text text="■" class="text-[#{{ $run->color }}] dark:text-[#{{ $run->color }}]" />&#x202F;<native:text :text="$run->text" /></native:text>
@elseif ($run->isHashtag && ! ($static ?? false))
<native:text :text="$run->text" font="medium" class="{{ $pieceClasses }} font-medium text-theme-primary" @press="openHashtag('{{ $run->text }}')" />
@elseif ($run->isWordmark)
<native:text font="semibold" class="{{ $pieceClasses }} {{ $pieceInk }} font-semibold tracking-tight">@if ($afterSpace)&#x200B;@endif/&#x2060;dev<native:text text="/" class="text-outline text-theme-lane-0" />&#x2060;null</native:text>
@else
<native:text :text="$run->text" class="{{ $pieceClasses }} {{ $pieceInk }}" />
@endif
