{{--
    A message's body, drawn from its segments as a commit's is (see partials/commit-body): prose with tappable
    mentions and inline code, hex colors beside their swatch, quotes behind a rule on the left, and fenced code as
    mono text in a box. $ink is the bubble's text colour, so a body on your lane reads in that lane's label colour,
    and $quoteRule the quote's rule: amber, or the label colour on your lane, as the web's pale rule in your own
    bubbles.
--}}
@foreach ($message->content as $block)
    @if ($block->isCode())
        <native:column class="w-full mt-1 mb-1 rounded-md border border-theme-hairline bg-theme-wash">
            <native:text :text="$block->code" font="mono" class="w-full px-3 py-2 text-[13px] leading-snug text-theme-ink whitespace-pre-wrap select-text" />
        </native:column>
    @else
@if ($block->isQuote())
<native:column class="my-1 pl-3 border-l-2 {{ $quoteRule }}">
@endif
<native:text class="text-[15px] leading-snug {{ $ink }} whitespace-pre-wrap">@foreach ($block->runs as $run)
@if ($run->isMention())
<native:text :text="$run->text" font="semibold" class="font-semibold {{ $mentionInk }}" @press="{{ $run->isOrganization ? 'openOrganization' : 'openProfile' }}('{{ $run->username }}')" />
@elseif ($run->isCode)
<native:text :text="$run->text" font="mono" class="text-[13px]" />
@elseif ($run->isColor())
<native:text><native:text text="■" class="text-[#{{ $run->color }}] dark:text-[#{{ $run->color }}]" />&#x202F;<native:text :text="$run->text" /></native:text>
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
