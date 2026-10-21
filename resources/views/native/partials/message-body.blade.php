{{--
    A message's body, drawn from its segments as a commit's is (see partials/commit-body): prose with tappable
    mentions and inline code, and fenced code as mono text in a box. $ink is the bubble's text colour, so a body
    on your lane reads in that lane's label colour.
--}}
@foreach ($message->content as $block)
    @if ($block->isCode())
        <native:column class="w-full mt-1 mb-1 rounded-md border border-theme-hairline bg-theme-wash">
            <native:text :text="$block->code" font="mono" class="w-full px-3 py-2 text-[13px] leading-snug text-theme-ink whitespace-pre-wrap select-text" />
        </native:column>
    @else
<native:text class="text-[15px] leading-snug {{ $ink }} whitespace-pre-wrap">@foreach ($block->runs as $run)
@if ($run->isMention())
<native:text :text="$run->text" font="semibold" class="font-semibold {{ $mentionInk }}" @press="openProfile('{{ $run->username }}')" />
@elseif ($run->isCode)
<native:text :text="$run->text" font="mono" class="text-[13px]" />
@else
<native:text :text="$run->text" />
@endif
@endforeach
</native:text>
    @endif
@endforeach
