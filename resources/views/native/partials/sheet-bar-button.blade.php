{{--
    A round button in a sheet's bar, as Apple draws a sheet's cross and check: the same 44pt circle on both platforms.
    It's glass on iOS and a tonal circle on Android, or filled with your colour as the bar's main button ($main).

    Without $press it's drawn muted, and not as a button, as a disabled button would be. Its action checks again
    anyway. $loading swaps the icon for a spinner in the same circle.
--}}
@php($main ??= false)
@php($loading ??= false)
@php($hint ??= null)
@php($filled = $main && ($press !== null || $loading))
@php($circle = 'rounded-full '.($filled ? 'bg-theme-'.$myColour : 'android:bg-theme-surface-variant ios:glass'.($press === null ? '' : ':interactive')))
@php($iconColour = $filled ? 'text-theme-on-'.$myColour : ($press === null ? 'text-theme-ink-muted' : 'text-theme-ink'))
@if ($press !== null)
    <native:pressable ref="{{ $ref }}" @press="{{ $press }}" class="flex-shrink-0" a11y-label="{{ $label }}" :a11y-hint="$hint">
        <native:stack ref="{{ $ref }}-circle" :width="44" :height="44" center class="{{ $circle }}">
            <native:icon :ios="$icon->ios()" :android="$icon->android()" :size="20" class="{{ $iconColour }}" />
        </native:stack>
    </native:pressable>
@else
    <native:stack ref="{{ $ref }}" :width="44" :height="44" center class="flex-shrink-0 {{ $circle }}" a11y-label="{{ $label }}">
        @if ($loading)
            <native:activity-indicator />
        @else
            <native:icon :ios="$icon->ios()" :android="$icon->android()" :size="20" class="{{ $iconColour }}" />
        @endif
    </native:stack>
@endif
