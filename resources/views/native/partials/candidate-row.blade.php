{{--
    Someone a people picker found, as the web's candidate row: their avatar, name and handle, and, when you can't reach
    them, why, dimmed. In the org picker a circle on the right fills once they're picked.
--}}
@php($isDisabled = $reason !== null && ! ($isPicked ?? false))
<native:pressable native:key="candidate-{{ $user->id }}" ref="candidate-{{ $user->username }}" @press="{{ $press }}('{{ $user->username }}')" class="w-full" a11y-label="{{ $user->name }}, {{ $user->handle() }}{{ $isDisabled ? ', '.$reason : '' }}{{ ($isPicked ?? false) ? ', picked' : '' }}">
    <native:row class="w-full px-4 py-2 gap-3 items-center bg-theme-surface">
        <native:column class="{{ $isDisabled ? 'opacity-[0.5]' : '' }}">
            @include('native.partials.avatar', ['user' => $user, 'size' => 36])
        </native:column>
        <native:column class="flex-1 gap-[2] {{ $isDisabled ? 'opacity-[0.6]' : '' }}">
            <native:text font="semibold" class="text-sm text-theme-ink" :max-lines="1">{{ $user->name }}</native:text>
            <native:text font="mono" class="text-xs text-theme-ink-muted" :max-lines="1">{{ $user->handle() }}{{ $isDisabled ? ' · '.$reason : '' }}</native:text>
        </native:column>
        @isset($isPicked)
            @if (! $isDisabled)
                <native:stack :width="20" :height="20" center class="rounded-full border {{ $isPicked ? 'border-theme-'.$myColour.' bg-theme-'.$myColour : 'border-theme-hairline' }}">
                    @if ($isPicked)
                        <native:icon :ios="\App\Icons\Lucide::Check->ios()" :android="\App\Icons\Lucide::Check->android()" :size="14" class="text-theme-on-{{ $myColour }}" />
                    @endif
                </native:stack>
            @endif
        @endisset
    </native:row>
</native:pressable>
