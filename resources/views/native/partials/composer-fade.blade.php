{{--
    The soft edge over the foot of a screen, just above its composer bar: a gradient from see-through at the top to
    the surface at the bottom, where the bar starts, so whatever scrolls down to the bar fades out instead of meeting a
    hard line. It lies over the screen's scroll view, at the bottom of the stack around it, on both platforms: on iOS
    the thread runs on under the bar, which hides it, and on Android it stops at the bar. Its colours come from
    Display::composerFade(). $ref names it.
--}}
<native:column ref="{{ $ref }}" class="absolute bottom-0 left-0 w-full h-[{{ \App\Devnull\Display::ComposerFadeHeight }}] {{ \App\Devnull\Display::composerFade() }}" />
