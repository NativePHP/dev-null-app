{{--
    A bio or company synced from GitHub, with each account it tags as a badge, as the web's BioText draws them: the
    account's square GitHub icon, then its handle, in a small tag on the wash (see bio-mention). $press names the
    screen's method a badge calls with the handle. Text that tags nobody is one run of text, as before.

    EDGE can't put an image in a run of text, so text with tags is a wrapping row of words and badges per paragraph
    (see App\Devnull\BioText). $size is the text's size in points, which the badges are scaled to.
--}}
@php($size ??= 15)
@php($press ??= 'openGitHubMention')
@php($maxLines ??= null)
@if (\App\Devnull\BioText::hasMentions($text))
    @php($mentionIndex = 0)
    <native:column ref="{{ $ref }}" class="{{ $flowClasses ?? 'w-full' }} gap-[2]">
        @foreach (\App\Devnull\BioText::paragraphs($text) as $paragraph)
            <native:row class="w-full flex-wrap items-center">
                @foreach ($paragraph as $piece)
                    @php($space = $piece['spaceAfter'] ? 'mr-['.round($size * 0.27).']' : '')
                    @if ($piece['mention'] !== null)
                        @include('native.partials.bio-mention', ['handle' => $piece['mention'], 'ref' => $ref.'-mention-'.$mentionIndex, 'size' => $size, 'press' => $press, 'space' => $space])
                        @php($mentionIndex++)
                    @else
                        <native:text :text="$piece['text']" :max-lines="1" class="flex-shrink-0 py-[3] {{ $space }} {{ $wordClasses ?? 'text-theme-ink' }}" :font-size="$size" />
                    @endif
                @endforeach
            </native:row>
        @endforeach
    </native:column>
@else
    <native:text ref="{{ $ref }}" class="{{ $classes }}" :max-lines="$maxLines ?? 0">{{ $text }}</native:text>
@endif
