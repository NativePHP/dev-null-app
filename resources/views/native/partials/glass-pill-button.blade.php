{{--
    A header's main buttons on a profile or an organization's page: Follow, Unfollow and Remote. A 40pt pill, a step up
    from the small theme button they were, on Liquid Glass on iOS as the app's other floating buttons are (see
    sheet-bar-button). $filled draws it in your colour, tinted through the glass on iOS, for the one thing to do next,
    like Follow; otherwise it's clear glass on iOS and a tonal pill on Android.

    It's a pressable rather than a theme button, which takes no glass or per-button fill.
--}}
@php($filled ??= false)
@php($icon ??= null)
@php($hint ??= null)
@php($a11yLabel ??= $label)
@php($pill = 'rounded-full ios:glass:interactive '.($filled ? 'bg-theme-'.$myColour : 'android:bg-theme-surface-variant'))
@php($ink = $filled ? 'text-theme-on-'.$myColour : 'text-theme-ink')
<native:pressable ref="{{ $ref }}" @press="{{ $press }}" press-scale="0.96" class="flex-shrink-0" a11y-label="{{ $a11yLabel }}" :a11y-hint="$hint">
    <native:row ref="{{ $ref }}-pill" :height="40" class="px-5 gap-2 items-center {{ $pill }}">
        @if ($icon)
            <native:icon :ios="$icon->ios()" :android="$icon->android()" :size="18" class="{{ $ink }}" />
        @endif
        <native:text font="semibold" class="text-base font-semibold {{ $ink }}" :max-lines="1">{{ $label }}</native:text>
    </native:row>
</native:pressable>
