{{--
    One of the composer's tools, as the web's ghost icon buttons: a 44pt target with the icon in muted ink, on Blue
    Wash in Commit Blue while it's on ($active). While it can't be used ($disabled), it's faded, with nothing to press.
    With $menu (TopBarAction items), a tap opens that menu instead of pressing.
--}}
@php($active ??= false)
@php($disabled ??= false)
@php($menu ??= null)
@if ($menu !== null)
    {{-- iOS's menu reads its icon to VoiceOver rather than the pressable's label, so the icon carries it too. --}}
    <native:pressable ref="{{ $ref }}" :menu="$menu" a11y-label="{{ $label }}">
        <native:column class="min-w-[44] h-[36] items-center justify-center rounded-md">
            <native:icon :ios="$icon->ios()" :android="$icon->android()" :size="20" class="text-theme-ink-muted" a11y-label="{{ $label }}" />
        </native:column>
    </native:pressable>
@elseif ($disabled)
    <native:column ref="{{ $ref }}" class="min-w-[44] h-[36] items-center justify-center rounded-md" a11y-label="{{ $label }}">
        <native:icon :ios="$icon->ios()" :android="$icon->android()" :size="20" class="text-theme-ink-muted opacity-40" />
    </native:column>
@else
    <native:pressable ref="{{ $ref }}" @press="{{ $press }}" a11y-label="{{ $label }}">
        <native:column class="min-w-[44] h-[36] items-center justify-center rounded-md {{ $active ? 'bg-theme-primary-soft' : '' }}">
            <native:icon :ios="$icon->ios()" :android="$icon->android()" :size="20" class="{{ $active ? 'text-theme-primary' : 'text-theme-ink-muted' }}" />
        </native:column>
    </native:pressable>
@endif
