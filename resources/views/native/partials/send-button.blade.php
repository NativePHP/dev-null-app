{{--
    A composer's send button: a 32pt circle in your colour with an up arrow, as the Claude and Messages apps send.
    It names what it does for screen readers ($label: Push, Reply). While there's nothing to send ($disabled) it's a
    muted circle, and not a button, as a disabled button would be. Its 36pt tap target fits the composer-tool row.
--}}
@php($disabled ??= false)
@php($circle = 'rounded-full '.($disabled ? 'bg-theme-surface-variant' : 'bg-theme-'.$myColour))
@php($arrow = $disabled ? 'text-theme-ink-muted' : 'text-theme-on-'.$myColour)
@if ($disabled)
    <native:stack ref="{{ $ref }}" :width="36" :height="36" center class="flex-shrink-0" a11y-label="{{ $label }}">
        <native:stack ref="{{ $ref }}-circle" :width="32" :height="32" center class="{{ $circle }}">
            <native:icon :ios="\App\Icons\Lucide::ArrowUp->ios()" :android="\App\Icons\Lucide::ArrowUp->android()" :size="18" class="{{ $arrow }}" />
        </native:stack>
    </native:stack>
@else
    <native:pressable ref="{{ $ref }}" @press="{{ $press }}" press-scale="0.92" class="flex-shrink-0" a11y-label="{{ $label }}">
        <native:stack :width="36" :height="36" center>
            <native:stack ref="{{ $ref }}-circle" :width="32" :height="32" center class="{{ $circle }}">
                <native:icon :ios="\App\Icons\Lucide::ArrowUp->ios()" :android="\App\Icons\Lucide::ArrowUp->android()" :size="18" class="{{ $arrow }}" />
            </native:stack>
        </native:stack>
    </native:pressable>
@endif
