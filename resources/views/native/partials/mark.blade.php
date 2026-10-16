{{--
    devnull's mark, the web's AppLogoIcon: a ring with a slash through it. Drawn like the rail nodes, an ink circle with
    the surface colour punched out of it, and the slash laid on top at 45°. The proportions are the icon's 24pt grid.
--}}
@php($size ??= 96)
@php($surface ??= 'bg-theme-background')
@php($stroke = round($size * 2.5 / 24, 2))
<native:stack :width="$size" :height="$size" center>
    <native:stack :width="round($size * 20 / 24, 2)" :height="round($size * 20 / 24, 2)" center class="rounded-full bg-theme-ink">
        <native:column :width="round($size * 15 / 24, 2)" :height="round($size * 15 / 24, 2)" class="rounded-full {{ $surface }}" />
        <native:column :width="round($size * 16 / 24, 2)" :height="$stroke" :rotate="-45" class="bg-theme-ink" />
    </native:stack>
</native:stack>
