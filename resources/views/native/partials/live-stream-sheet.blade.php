{{--
    The web's live stream sheet, which a tap on a live user's avatar opens (see OpensLiveStream): the stream's
    thumbnail in 16:9 with the LIVE badge in its corner, "{name} is live" and where, Watch now, then who it is with
    Open profile, which their own profile leaves out. The thumbnail isn't kept on the device, as it changes while they
    stream. Nothing is drawn until the sheet opens.
--}}
@php($streamer = $liveStreamer === null ? null : \App\Devnull\Data\User::fromArray($liveStreamer))
<native:bottom-sheet ref="live-stream-sheet" :visible="$streamer !== null" detents="medium" @dismiss="liveStreamClosed">
    @if ($streamer?->live)
        <native:column class="w-full px-5 pt-6 pb-10 gap-4">
            <native:stack class="w-full aspect-[16/9] rounded-lg bg-theme-wash">
                @if ($streamer->live->thumbnailUrl)
                    <native:image ref="live-stream-thumbnail" :src="$streamer->live->thumbnailUrl" :fit="2" alt="{{ $streamer->name }}'s stream" class="w-full h-full rounded-lg" />
                @endif
                <native:column class="absolute top-[8] left-[8]">
                    @include('native.partials.live-badge', ['size' => 'lg'])
                </native:column>
            </native:stack>

            <native:column class="w-full gap-[2]">
                <native:text font="semibold" class="text-lg text-theme-ink" :max-lines="2">{{ $streamer->name }} is live</native:text>
                <native:text font="mono" class="text-sm text-theme-ink-muted" :max-lines="1">{{ $streamer->live->host() }}</native:text>
            </native:column>

            <native:button ref="watch-live-stream" variant="primary" size="lg" :ios-icon-trailing="\App\Icons\Lucide::ArrowUpRight->ios()" :android-icon-trailing="\App\Icons\Lucide::ArrowUpRight->android()" @press="watchLiveStream" class="w-full">Watch now</native:button>

            @if ($liveStreamWithProfile)
                <native:column class="w-full h-[1] bg-theme-hairline" />
                <native:row class="w-full gap-3 items-center">
                    @include('native.partials.rail-avatar', ['user' => $streamer, 'lane' => $streamer->lane(), 'size' => 48])
                    <native:column class="flex-1 gap-[2]">
                        <native:text font="semibold" class="text-base text-theme-ink" :max-lines="1">{{ $streamer->name }}</native:text>
                        <native:text font="mono" class="text-sm text-theme-ink-muted" :max-lines="1">{{ $streamer->handle() }}</native:text>
                    </native:column>
                    <native:button ref="live-stream-profile" variant="secondary" size="sm" @press="openLiveStreamerProfile">Open profile</native:button>
                </native:row>
            @endif
        </native:column>
    @endif
</native:bottom-sheet>
