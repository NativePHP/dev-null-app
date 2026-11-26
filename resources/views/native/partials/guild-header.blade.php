{{--
    A guild's header, at the top of its board (see guild.blade.php), in the web's words: its icon or emoji (see
    guild-mark) with Join or Leave beside it, as a profile has Follow, and Edit before it for its moderator, as the
    web's pencil shows them it's theirs. They are the profile's 40pt pills (see glass-pill-button), with Join in your
    colour as the thing to do next. Then its name, "#handle · Public guild · N members", the count opening its
    members, what it's about, then how to post there, or that it's paused while it has no moderator. New commit,
    which posts to the board, is in the nav bar while the guild takes posts (see Guild::navigationOptions()).
--}}
@php($members = \App\Devnull\Display::count($guild->memberCount, 'member', 'members'))
<native:column ref="guild-header" class="w-full bg-theme-surface">
    <native:column class="w-full px-4 pt-6 pb-4 gap-3">
        <native:row class="w-full items-start justify-between">
            <native:stack :width="64" :height="64" class="rounded-lg bg-theme-wash" center>
                @include('native.partials.guild-mark', ['guild' => $guild, 'size' => 64, 'ref' => 'guild-header-mark'])
            </native:stack>

            <native:row class="gap-2 items-center">
                @if ($canModerate)
                    @include('native.partials.glass-pill-button', ['ref' => 'guild-edit', 'label' => 'Edit', 'press' => 'editGuild', 'hint' => 'Edits '.$guild->hashtag()."'s name, description and icon"])
                @endif
                @if ($guild->isJoined)
                    @include('native.partials.glass-pill-button', ['ref' => 'guild-join', 'label' => 'Leave', 'press' => 'toggleJoined', 'hint' => 'Leaves '.$guild->hashtag()])
                @else
                    @include('native.partials.glass-pill-button', ['ref' => 'guild-join', 'label' => 'Join', 'press' => 'toggleJoined', 'hint' => 'Joins '.$guild->hashtag(), 'filled' => true])
                @endif
            </native:row>
        </native:row>

        <native:column class="w-full gap-1">
            <native:text font="semibold" class="text-2xl tracking-tight text-theme-ink">{{ $guild->name }}</native:text>
            <native:row ref="guild-meta" class="w-full gap-1 items-center">
                <native:text font="mono" class="text-[13px] text-theme-ink-muted flex-shrink" :max-lines="1">{{ $guild->hashtag() }}</native:text>
                <native:text class="text-[13px] text-theme-ink-muted">·</native:text>
                <native:text class="text-[13px] text-theme-ink-muted">{{ $guild->isPaused ? 'Public guild · Paused' : 'Public guild' }}</native:text>
                <native:text class="text-[13px] text-theme-ink-muted">·</native:text>
                <native:pressable ref="guild-members" @press="openMembers" a11y-label="{{ $members }}" a11y-hint="Opens the guild's members">
                    <native:text class="text-[13px] text-theme-ink">{{ $members }}</native:text>
                </native:pressable>
            </native:row>
        </native:column>

        @if ($guild->description !== '')
            <native:text ref="guild-description" class="w-full text-[15px] leading-relaxed text-theme-ink">{{ $guild->description }}</native:text>
        @endif

        <native:text ref="guild-how-to-post" class="w-full text-sm text-theme-ink-muted">{{ $guild->isPaused ? 'This guild is paused until it has a moderator. Its board and members are preserved.' : 'Include '.$guild->hashtag().' in any commit to post here.' }}</native:text>
    </native:column>
    <native:column class="w-full h-[1] bg-theme-hairline" />
</native:column>
