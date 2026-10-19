{{-- One commit on the graph: a ring in its lane (or Rail Gray for a deleted one) with a gap punched in the surface colour. --}}
@php($filled ??= true)
@php($colour = 'bg-theme-'.\App\Devnull\Lane::token($lane))
<native:stack :width="18" :height="18" center class="rounded-full {{ $colour }}">
    <native:stack :width="14" :height="14" center class="rounded-full bg-theme-surface">
        @if ($filled)
            <native:column :width="8" :height="8" class="rounded-full {{ $colour }}" />
        @endif
    </native:stack>
</native:stack>
