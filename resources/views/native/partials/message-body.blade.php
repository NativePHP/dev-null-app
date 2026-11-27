{{--
    A message's body, drawn from its segments as a commit's is (see partials/commit-body): prose with tappable
    mentions, links and hashtags, inline code, hex colors beside their swatch, quotes behind a rule on the left, and
    fenced code as mono text in a box. $ink is the bubble's text colour, so a body on your lane reads in that lane's
    label colour, and $quoteRule the quote's rule: amber, or the label colour on your lane, as the web's pale rule in
    your own bubbles.
    A link is in $linkInk, Commit Blue off your lane and the lane's label colour on it, with a named link's owner
    muted in $linkPrefixInk, and opens by its place among the body's links (see Conversation::openMessageLink).
    A hashtag is medium weight in $mentionInk, as a mention is, and searches commits for it on a tap. One that names a
    guild shows its name in medium mono, with no wash, as inline code is here, and opens the guild. It's in $mentionInk, not
    the guild's lane colour (see Guild::lane), which could match the colour of your own bubble.
    $maxLines caps the body to the first block at that many lines, for the pinned sheet's preview, so its links work
    before Show more.
    A standalone /dev/null is the wordmark, in the bubble's ink (see partials/wordmark).
--}}
@php($linkInk ??= $mentionInk)
@php($linkPrefixInk ??= 'text-theme-ink-muted')
@php($maxLines ??= null)
@php($linkIndex = 0)
@foreach ($maxLines === null ? $message->content : array_slice($message->content, 0, 1) as $block)
    @if ($block->isCode())
        <native:column class="w-full mt-1 mb-1 rounded-md border border-theme-hairline bg-theme-wash">
            <native:text :text="$block->code" :max-lines="$maxLines ?? 0" font="mono" class="w-full px-3 py-2 text-[13px] leading-snug text-theme-ink whitespace-pre-wrap select-text" />
        </native:column>
    @else
@if ($block->isQuote())
<native:column class="my-1 pl-3 border-l-2 {{ $quoteRule }}">
@endif
<native:text :max-lines="$maxLines ?? 0" class="text-[15px] leading-snug {{ $ink }} whitespace-pre-wrap">@foreach ($block->runs as $run)
@if ($run->isMention())
<native:text :text="$run->text" font="semibold" class="font-semibold {{ $mentionInk }}" @press="{{ $run->isOrganization ? 'openOrganization' : 'openProfile' }}('{{ $run->username }}')" />
@elseif ($run->isCode)
<native:text :text="$run->text" font="mono" class="text-[13px]" />
@elseif ($run->link?->isNamed())
<native:text font="mono" class="text-[13px] {{ $linkInk }}" @press="openMessageLink({{ $message->id }}, {{ $linkIndex++ }})"><native:text :text="$run->link->prefix" class="{{ $linkPrefixInk }}" />&#x2060;<native:text :text="$run->link->shown" /></native:text>
@elseif ($run->link)
<native:text :text="$run->link->shown" class="{{ $linkInk }}" @press="openMessageLink({{ $message->id }}, {{ $linkIndex++ }})" />
@elseif ($run->isColor())
<native:text><native:text text="■" class="text-[#{{ $run->color }}] dark:text-[#{{ $run->color }}]" />&#x202F;<native:text :text="$run->text" /></native:text>
@elseif ($run->guild)
<native:text :text="$run->guild->name" font="mono-medium" class="text-[13px] {{ $mentionInk }}" @press="openGuild('{{ $run->guild->handle }}')" />
@elseif ($run->isHashtag)
<native:text :text="$run->text" font="medium" class="font-medium {{ $mentionInk }}" @press="openHashtag('{{ $run->text }}')" />
@elseif ($run->isWordmark)
@include('native.partials.wordmark', ['afterSpace' => $loop->index > 0 && ctype_space(substr($block->runs[$loop->index - 1]->text, -1))])
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
