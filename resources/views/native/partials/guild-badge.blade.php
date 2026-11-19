{{--
    The web shows a message under commits that are posted straight to a guild's board,
    which looks like this: "posted in #guild · not shown in general logs". It says this
    because these posts stay out of profiles and feeds.

    If the user taps on the pill for the guild's hashtag (see OpensHashtags), we open a
    search for that hashtag. Once guild pages exist in the app, this can open the dedicated
    guild page instead.
--}}
@php($guild = $commit->guild)
<native:row ref="guild-badge-{{ $commit->id }}" class="w-full gap-1 items-center">
    <native:text class="text-xs text-theme-ink-muted">posted in</native:text>
    <native:pressable ref="guild-pill-{{ $commit->id }}" @press="openHashtag('{{ $guild->hashtag() }}')" class="flex-row flex-shrink gap-1 items-center rounded-sm bg-theme-wash px-1 py-[2]" a11y-label="{{ $guild->name }}, {{ $guild->hashtag() }}">
        @include('native.partials.guild-mark', ['guild' => $guild, 'size' => 14, 'ref' => 'guild-pill-mark-'.$commit->id])
        <native:text font="mono" class="text-xs text-theme-ink flex-shrink" :max-lines="1">{{ $guild->name }}</native:text>
    </native:pressable>
    <native:text class="text-xs text-theme-ink-muted">· not shown in general logs</native:text>
</native:row>
