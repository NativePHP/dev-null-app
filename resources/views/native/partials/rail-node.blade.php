{{--
    One commit on the graph: a ring in its lane (or Rail Gray for a deleted one) with a gap punched in the surface colour.
    The gap is a 3pt surface border round the lane centre, which draws inside it, so the centre needs no view of its own.
    $position places it, as the rail does with `absolute` and its insets, and $nodeRef names it.
--}}
@php($filled ??= true)
@php($position ??= '')
@php($nodeRef ??= null)
@php($colour = 'bg-theme-'.\App\Devnull\Lane::token($lane))
<native:stack :ref="$nodeRef" :width="18" :height="18" center class="{{ $position }} rounded-full {{ $colour }}">
    <native:column :width="14" :height="14" class="rounded-full {{ $filled ? $colour.' border-[3] border-theme-surface' : 'bg-theme-surface' }}" />
</native:stack>
