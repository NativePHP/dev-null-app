{{--
    The soft edge between a screen and its composer bar: a gradient from see-through at the top to the bar's surface at
    the bottom, where the bar's rows start, so whatever scrolls down to the bar fades out instead of meeting a hard
    line. Its colours come from Display::composerFade().

    Where it goes depends on the platform (Display::fadesInBar()). On iOS the screen runs on under the bar, so the fade
    is the bar's first row ($inBar), over what runs under it, and the bar's glass fades in under it over the same
    height (see Display::composerBarGlass()). On Android the screen stops at the bar, so the fade lies over the foot of
    the screen's scroll view, at the bottom of the stack around it. From iOS 26 there's no fade at all: the system's
    scroll edge effect blurs what runs under the bar (Display::fadeRowInBar()). $ref names it.
--}}
@php($inBar ??= false)
<native:column ref="{{ $ref }}" class="{{ $inBar ? '' : 'absolute bottom-0 left-0' }} w-full h-[{{ \App\Devnull\Display::ComposerFadeHeight }}] {{ \App\Devnull\Display::composerFade() }}" />
