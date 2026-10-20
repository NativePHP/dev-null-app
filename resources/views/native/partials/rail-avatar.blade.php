{{--
    An author on the rail, as the web's RailAvatar: their 40pt avatar in a 2pt ring of their lane, with a 2pt gap in
    the surface colour. A staged one (a commit not made yet) draws the ring in dashes. $size is the ring's, 48 by
    default; the context around a commit page's commit draws it at 36. Replies use a 24pt ring with a 1pt border and gap.
--}}
@php($staged ??= false)
@php($size ??= 48)
@php($ringWidth = $size <= 24 ? 1 : 2)
@if ($staged)
    <native:column ref="staged-avatar" :width="48" :height="48" class="rounded-full bg-theme-surface">
        @include('native.partials.dashed-ring', ['size' => 48, 'lane' => $lane])
        <native:column class="absolute top-[4] left-[4]">
            @include('native.partials.avatar', ['user' => $user, 'size' => 40])
        </native:column>
    </native:column>
@else
    <native:stack :width="$size" :height="$size" center class="rounded-full bg-theme-{{ \App\Devnull\Lane::token($lane) }}">
        <native:stack :width="$size - $ringWidth * 2" :height="$size - $ringWidth * 2" center class="rounded-full bg-theme-surface">
            @include('native.partials.avatar', ['user' => $user, 'size' => $size - $ringWidth * 4])
        </native:stack>
    </native:stack>
@endif
