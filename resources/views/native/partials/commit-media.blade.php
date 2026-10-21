{{--
    What hangs under a commit's body on the web: its images, its poll, the commit it quotes, then the link preview.
    A reply in a thread shows only its images and link preview ($withEmbeds false), as the web's thread does.
    Images hold their space at their aspect ratio on a wash, so one that fails to load leaves the row as it was. Each is
    read out by the description its author gave it, as the web's alt text. Images come from the device once ImageCache has
    them, so they don't load again each time they scroll into view.
--}}
@php($images = $commit->images)
@if (count($images) === 1)
    <native:pressable ref="image-{{ $images[0]->id }}" @press="openImage('{{ $commit->id }}', 0)" class="w-full mt-2" a11y-label="{{ $images[0]->label() }}">
        <native:column class="w-full aspect-[{{ $images[0]->ratio() }}] rounded-lg border border-theme-hairline bg-theme-wash">
            <native:image :src="\App\Devnull\ImageCache::src($images[0]->url)" :fit="2" alt="{{ $images[0]->label() }}" class="w-full h-full rounded-lg" />
        </native:column>
    </native:pressable>
@elseif (count($images) > 1)
    {{-- Two side by side, three with the first one tall, four in a square: the web's grid. --}}
    <native:row class="w-full h-[200] mt-2 gap-[2] rounded-lg border border-theme-hairline bg-theme-hairline">
        @foreach (count($images) === 4 ? [[$images[0], $images[2]], [$images[1], $images[3]]] : (count($images) === 3 ? [[$images[0]], [$images[1], $images[2]]] : array_map(fn ($image) => [$image], $images)) as $column)
            <native:column class="flex-1 h-full gap-[2]">
                @foreach ($column as $image)
                    @php($index = array_search($image, $images, true))
                    <native:pressable ref="image-{{ $image->id }}" @press="openImage('{{ $commit->id }}', {{ $index }})" class="w-full flex-1 bg-theme-wash" a11y-label="{{ $image->label($index, count($images)) }}">
                        <native:image :src="\App\Devnull\ImageCache::src($image->url)" :fit="2" alt="{{ $image->label($index, count($images)) }}" class="w-full h-full" />
                    </native:pressable>
                @endforeach
            </native:column>
        @endforeach
    </native:row>
@endif

@if ($commit->poll && ($withEmbeds ?? true))
    @include('native.partials.commit-poll')
@endif

@if ($commit->isQuote() && ($withEmbeds ?? true))
    @include('native.partials.quoted-commit')
@endif

@if ($commit->linkPreview?->isReady())
    @php($preview = $commit->linkPreview)
    {{-- A compact row, not a card: a small image, the mono site, then the title and description. --}}
    <native:pressable ref="link-preview-{{ $commit->id }}" @press="openLinkPreview('{{ $commit->id }}')" class="w-full mt-2" a11y-label="{{ $preview->title }}, {{ $preview->site() }}">
        <native:row class="w-full gap-3 p-2 items-center rounded-lg border border-theme-hairline">
            @if ($preview->imageUrl)
                <native:column :width="56" :height="56" class="rounded-md bg-theme-wash">
                    <native:image :src="\App\Devnull\ImageCache::src($preview->imageUrl)" :width="56" :height="56" :fit="2" class="rounded-md" />
                </native:column>
            @endif
            <native:column class="flex-1 gap-[2] pr-1">
                <native:text font="mono" class="text-[13px] text-theme-ink-muted" :max-lines="1">{{ $preview->site() }}</native:text>
                <native:text font="semibold" class="text-sm leading-snug text-theme-ink" :max-lines="2">{{ $preview->title }}</native:text>
                @if ($preview->description)
                    <native:text class="text-[13px] leading-snug text-theme-ink-muted" :max-lines="1">{{ $preview->description }}</native:text>
                @endif
            </native:column>
        </native:row>
    </native:pressable>
@endif
