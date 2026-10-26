{{--
    A commit's body, drawn from its segments as the web draws it: paragraphs of prose with tappable mentions and
    inline code on a wash, and fenced code blocks as mono text in a hairline box.
    The runs inside a paragraph sit flush left with no blank lines between them on purpose: under whitespace-pre-wrap
    any whitespace between those tags would print. Each line ends in a tag or a directive, whose newline PHP drops.

    A standalone /dev/null is the wordmark, in semibold with tighter tracking like the web's, its middle slash a
    hollow lane-0 outline. text-outline's 1pt is the web's max(1px, 0.035em) at body sizes. iOS breaks lines after a
    slash, so a word joiner (U+2060) after each one keeps the wordmark on one line. It never breaks before a
    slash, even after a space, so a zero-width space (U+200B) after a space lets the line break there as a browser
    would; without it, a few wordmarks in a row can't wrap at all. VoiceOver skips both and still reads "/dev/null".

    A quote, lines that start with ">", sits behind an amber rule on the left like the web's blockquote, its
    runs drawn as a paragraph's.

    A link is Commit Blue and opens on a tap, numbered by its place among the body's links. Like the web, a URL shows
    shortened, and a dev/null commit or a GitHub repository, issue or pull request shows as a mono name with the owner
    muted, held on one line by a word joiner after its slash. The web's dev/null and GitHub marks before those are icons,
    which text runs can't hold, so they're left out.

    A body too long to show whole in a list shows its start, as devnull cut it, and "Show more" in Commit Blue, which
    opens the rest where it is. The commit a page is about ($focused) is always whole. $muted sets it in muted ink, for
    the commits around the one a page is about.
--}}
@php($size ??= 'text-[15px]')
@php($ink = ($muted ?? false) ? 'text-theme-ink-muted' : 'text-theme-ink')
@php($folded = ! ($focused ?? false) && $commit->isFolded() && ! in_array($commit->id, $unfolded ?? [], true))
@php($linkIndex = 0)
@foreach ($folded ? $commit->previewContent : $commit->content as $block)
    @if ($block->isCode())
        <native:column class="w-full mt-1 mb-1 rounded-md border border-theme-hairline bg-theme-wash">
            @if ($block->language)
                <native:text font="mono" class="w-full px-3 pt-2 text-[11px] text-right text-theme-ink-muted">{{ $block->language }}</native:text>
            @endif
            <native:text :text="$block->code" font="mono" class="w-full px-3 py-2 text-[13px] leading-snug {{ $ink }} whitespace-pre-wrap select-text" />
        </native:column>
    @else
@if ($block->isQuote())
<native:column class="w-full my-1 pl-3 border-l-2 border-theme-lane-5">
@endif
<native:text class="w-full {{ $size }} leading-relaxed {{ $ink }} whitespace-pre-wrap">@foreach ($block->runs as $run)
@if ($run->isMention())
<native:text :text="$run->text" font="semibold" class="font-semibold text-theme-primary" @press="openProfile('{{ $run->username }}')" />
@elseif ($run->isCode)
<native:text :text="$run->text" font="mono" class="text-[13px] bg-theme-wash" />
@elseif ($run->link?->isNamed())
<native:text font="mono" class="text-[13px] text-theme-primary" @press="openLink('{{ $commit->id }}', {{ $linkIndex++ }})"><native:text :text="$run->link->prefix" class="text-theme-ink-muted" />&#x2060;<native:text :text="$run->link->shown" /></native:text>
@elseif ($run->link)
<native:text :text="$run->link->shown" class="text-theme-primary" @press="openLink('{{ $commit->id }}', {{ $linkIndex++ }})" />
@elseif ($run->isWordmark)
@php($afterSpace = $loop->index > 0 && ctype_space(substr($block->runs[$loop->index - 1]->text, -1)))
<native:text font="semibold" class="font-semibold tracking-tight">@if ($afterSpace)&#x200B;@endif/&#x2060;dev<native:text text="/" class="text-outline text-theme-lane-0" />&#x2060;null</native:text>
@else
<native:text :text="$run->text" />
@endif
@endforeach
</native:text>
@if ($block->isQuote())
</native:column>
@endif
    @endif
@endforeach
@if ($folded)
    <native:pressable ref="show-more-{{ $commit->id }}" @press="showMore('{{ $commit->id }}')" a11y-label="Show more">
        <native:text font="medium" class="{{ $size }} text-theme-primary">Show more</native:text>
    </native:pressable>
@endif
