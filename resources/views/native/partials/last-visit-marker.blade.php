{{--
    Between what came in since your last visit and the first thing you'd already seen, on the Log and Notifications
    alike: a mono `HEAD@{last visit}` on a band in a wash of the muted colour, with a little room above and below, as
    the web's inbox draws it.

    The web asks for Geist Mono at semibold, but only loads its 400 and 500 files, so browsers draw the 500. Here that's
    the `mono-medium` face, with no weight class on top.

    Given a $lane, its rail runs down the gutter over the band and the room around it, without a break. The room is the
    band's margin rather than padding here, since iOS lays an absolute child out inside its parent's padding.
--}}
@php($lane ??= null)
<native:column native:key="last-visit" ref="last-visit" class="w-full bg-theme-surface" a11y-label="{{ $label ?? 'Commits above are new since your last visit' }}">
    <native:row class="w-full my-3 bg-theme-wash/40">
        <native:column :width="64" />
        <native:row class="flex-1 py-4 pr-4 items-center">
            <native:text font="mono-medium" class="text-xs text-theme-ink-muted">HEAD@{last visit}</native:text>
        </native:row>
    </native:row>

    @include('native.partials.rail', ['previous' => $lane])
</native:column>
