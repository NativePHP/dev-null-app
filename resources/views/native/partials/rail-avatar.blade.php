{{--
    An author on the rail, as the web's RailAvatar: their 40pt avatar in a 2pt ring of their lane, with a 2pt gap in
    the surface colour. A staged one (a commit not made yet) draws the ring in dashes. $size is the ring's, 48 by
    default; the context around a commit page's commit draws it at 36. Replies use a 24pt ring with a 1pt border and gap.
    The ring is a border on a surface disc, which draws inside it, so the gap needs no disc of its own. $position
    places it, as the rail does with `absolute` and its insets. Someone streaming wears a red ring instead, and above
    24pt the LIVE badge, centred 6pt below the avatar as on the web.
--}}
@php($staged ??= false)
@php($size ??= 48)
@php($ringWidth = $size <= 24 ? 1 : 2)
@php($position ??= '')
@php($live = ! $staged && $user->isLive())
@if ($staged)
    <native:column ref="staged-avatar" :width="48" :height="48" class="{{ $position }} rounded-full bg-theme-surface">
        @include('native.partials.dashed-ring', ['size' => 48, 'lane' => $lane])
        <native:column class="absolute top-[4] left-[4]">
            @include('native.partials.avatar', ['user' => $user, 'size' => 40])
        </native:column>
    </native:column>
@elseif ($live && $size > 24)
    <native:column :width="$size" :height="$size" class="{{ $position }}">
        <native:stack :width="$size" :height="$size" center class="rounded-full bg-theme-surface border-2 border-theme-live">
            @include('native.partials.avatar', ['user' => $user, 'size' => $size - 8])
        </native:stack>
        <native:row class="absolute left-0 right-0 top-[{{ $size - 12 }}] justify-center">
            @include('native.partials.live-badge')
        </native:row>
    </native:column>
@else
    <native:stack :width="$size" :height="$size" center class="{{ $position }} rounded-full bg-theme-surface {{ $ringWidth === 1 ? 'border' : 'border-2' }} border-theme-{{ $live ? 'live' : \App\Devnull\Lane::token($lane) }}">
        @include('native.partials.avatar', ['user' => $user, 'size' => $size - $ringWidth * 4])
    </native:stack>
@endif
