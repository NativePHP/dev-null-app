{{-- The people an org picker has picked, as chips you tap to take back out, or a line saying nobody is yet. --}}
<native:row ref="picked" class="w-full h-[28] items-center">
    @if ($picked === [])
        <native:text font="mono" class="text-xs text-theme-ink-muted">nobody picked yet</native:text>
    @else
        <native:scroll-view horizontal :shows-indicators="false" class="w-full">
            <native:row class="gap-[6] items-center">
                @foreach ($picked as $person)
                    <native:pressable native:key="picked-{{ $person->id }}" ref="picked-{{ $person->username }}" @press="toggle('{{ $person->username }}')" a11y-label="Remove {{ $person->handle() }}">
                        <native:row class="h-[28] pl-1 pr-2 gap-[6] items-center rounded-full bg-theme-wash">
                            @include('native.partials.avatar', ['user' => $person, 'size' => 20])
                            <native:text font="mono" class="text-xs text-theme-ink" :max-lines="1">{{ $person->handle() }}</native:text>
                            <native:icon :ios="\App\Icons\Lucide::X->ios()" :android="\App\Icons\Lucide::X->android()" :size="12" class="text-theme-ink-muted" />
                        </native:row>
                    </native:pressable>
                @endforeach
            </native:row>
        </native:scroll-view>
    @endif
</native:row>
