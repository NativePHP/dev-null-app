{{--
    A link devnull can play, such as a YouTube video, as the web's link embed: the poster at the player's shape with a play
    button over it, then a row naming the provider, and the video's title and channel once they're known (see VideoDetails),
    which the web doesn't show. A playlist, which devnull sends without a poster, shows YouTube's thumbnail. Playing opens
    the video in the in-app browser for now (see ShowsCommits::playEmbed). A vertical video is no taller than the tallest
    image, and one with no poster at all plays from a black box.
--}}
@php($embed = $preview->embed)
@php($card = \App\Devnull\VideoDetails::card($preview))
@php($ratio = round(max(\App\Devnull\Data\CommitImage::TallestRatio, $embed->aspectRatio), 4))
<native:column class="w-full mt-2 rounded-lg border border-theme-hairline">
    <native:pressable ref="embed-play-{{ $commit->id }}" @press="playEmbed('{{ $commit->id }}')" class="w-full" a11y-label="Play {{ $card['title'] ?? $embed->kind }} from {{ $card['provider'] }}">
        <native:stack center class="w-full aspect-[{{ $ratio }}] rounded-t-lg bg-black">
            @if ($card['poster'])
                <native:image :src="\App\Devnull\ImageCache::src($card['poster'])" :fit="2" class="w-full h-full rounded-t-lg" />
            @endif
            <native:column class="w-full h-full rounded-t-lg bg-black/20" />
            <native:stack :width="56" :height="56" center class="rounded-full bg-black/70">
                <native:icon :ios="\App\Icons\Lucide::Play->ios()" :android="\App\Icons\Lucide::Play->android()" :size="24" class="text-white" />
            </native:stack>
        </native:stack>
    </native:pressable>
    <native:pressable ref="link-preview-{{ $commit->id }}" @press="openLinkPreview('{{ $commit->id }}')" class="w-full border-t border-theme-hairline" a11y-label="{{ implode(', ', array_filter([$card['title'], $card['author'], $card['provider']])) }}">
        <native:column class="w-full gap-[2] px-3 py-2">
            <native:text font="mono" class="text-[13px] text-theme-ink-muted" :max-lines="1">{{ $card['provider'] }}</native:text>
            @if ($card['title'])
                <native:text font="semibold" class="text-[15px] leading-snug text-theme-ink" :max-lines="2">{{ $card['title'] }}</native:text>
            @endif
            @if ($card['author'])
                <native:text class="text-[13px] leading-snug text-theme-ink-muted" :max-lines="1">{{ $card['author'] }}</native:text>
            @endif
        </native:column>
    </native:pressable>
</native:column>
