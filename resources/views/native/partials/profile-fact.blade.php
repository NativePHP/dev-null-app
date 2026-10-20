{{-- One thing GitHub says about someone, under their bio: a muted icon and text, or a link in Commit Blue when $press is set. --}}
@php($press ??= null)
@if ($press)
    <native:pressable ref="profile-{{ $key }}" @press="{{ $press }}" a11y-label="{{ $label }}">
        <native:row class="w-full gap-2 items-center">
            <native:column :width="16" class="items-center">
                <native:icon :ios="$icon->ios()" :android="$icon->android()" :size="14" class="text-theme-ink-muted" />
            </native:column>
            <native:text class="flex-1 text-sm text-theme-primary" :max-lines="1">{{ $label }}</native:text>
        </native:row>
    </native:pressable>
@else
    <native:row ref="profile-{{ $key }}" class="w-full gap-2 items-center">
        <native:column :width="16" class="items-center">
            <native:icon :ios="$icon->ios()" :android="$icon->android()" :size="14" class="text-theme-ink-muted" />
        </native:column>
        @if ($gitHubMentions ?? false)
            @include('native.partials.github-text', ['text' => $label, 'ref' => 'profile-'.$key.'-text', 'classes' => 'flex-1 text-sm text-theme-ink-muted', 'maxLines' => 1])
        @else
            <native:text class="flex-1 text-sm text-theme-ink-muted" :max-lines="1">{{ $label }}</native:text>
        @endif
    </native:row>
@endif
