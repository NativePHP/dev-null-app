{{--
    An empty Following log, as the web words it. When you follow nobody yet, devnull sends a few commits from people
    worth following, each with a Follow button; otherwise it points at Trending, a link in Commit Blue. Sentences with
    a link are runs written flush left, since under whitespace-pre-wrap whitespace between tags would print.
--}}
<native:column ref="empty-state" class="w-full items-center px-6 py-16 gap-3 bg-theme-surface">
    <native:text font="mono" class="text-[13px] text-center text-theme-ink-muted">fatal: your log does not have any commits yet</native:text>
    @if ($suggestions === [])
<native:text class="text-[15px] text-center leading-relaxed text-theme-ink max-w-[320] whitespace-pre-wrap"><native:text text="Your log shows your commits and commits from people you follow. Find someone worth following on " />
<native:text ref="empty-trending" text="Trending" font="medium" class="text-theme-primary" @press="show('trending')" />
<native:text text="." />
</native:text>
    @else
        <native:text class="text-[15px] text-center leading-relaxed text-theme-ink max-w-[320]">Your log shows your commits and commits from people you follow. Here is what a few developers committed lately:</native:text>

        <native:column ref="suggestions" class="w-full mt-2 rounded-lg border border-theme-hairline">
            @foreach ($suggestions as $suggestion)
                @php($author = $suggestion->author)
                <native:row ref="suggestion-{{ $author->username }}" class="w-full p-3 gap-3 items-center">
                    <native:pressable @press="openProfile('{{ $author->username }}')" a11y-label="{{ $author->name }}'s profile">
                        @include('native.partials.avatar', ['user' => $author, 'size' => 36])
                    </native:pressable>
                    <native:column class="flex-1 gap-[2]">
                        <native:row class="w-full gap-[6] items-center">
                            <native:text font="semibold" class="text-sm text-theme-ink flex-shrink" :max-lines="1">{{ $author->name }}</native:text>
                            <native:text font="mono" class="text-[13px] text-theme-ink-muted flex-shrink" :max-lines="1">{{ $author->handle() }}</native:text>
                        </native:row>
                        <native:text class="w-full text-sm text-theme-ink-muted" :max-lines="1">{{ $suggestion->plainText() }}</native:text>
                    </native:column>
                    <native:button ref="follow-{{ $author->username }}" :variant="$myColour" size="sm" @press="followSuggested('{{ $author->username }}')" a11y-label="Follow {{ $author->handle() }}">Follow</native:button>
                </native:row>
                @unless ($loop->last)
                    <native:column class="w-full h-[1] bg-theme-hairline" />
                @endunless
            @endforeach
        </native:column>

<native:text class="mt-2 text-sm text-center text-theme-ink-muted whitespace-pre-wrap"><native:text text="Or browse " />
<native:text ref="empty-trending" text="Trending" font="medium" class="text-theme-primary" @press="show('trending')" />
<native:text text=" for more." />
</native:text>
    @endif
</native:column>
