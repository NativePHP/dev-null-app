{{--
    One poll option's label row with results: its label, a check after it when it's your vote, and its percentage in
    mono on the right. commit-poll draws it twice, an invisible copy in the flow that gives the option its height and
    the copy you see on top of the bar, so $class places it and $ref names it. Only the copy you see names its check.
--}}
<native:row ref="{{ $ref }}" class="w-full px-3 py-2 gap-[6] items-center {{ $class }}">
    <native:text :font="$option->voted ? 'medium' : null" class="flex-shrink text-[15px] text-theme-ink">{{ $option->label }}</native:text>
    @if ($option->voted && $isVisible)
        <native:icon ref="poll-voted-{{ $option->id }}" :ios="\App\Icons\Lucide::Check->ios()" :android="\App\Icons\Lucide::Check->android()" :size="16" class="text-theme-primary" />
    @elseif ($option->voted)
        <native:icon :ios="\App\Icons\Lucide::Check->ios()" :android="\App\Icons\Lucide::Check->android()" :size="16" class="text-theme-primary" />
    @endif
    <native:spacer />
    <native:text font="mono" class="text-[13px] text-theme-ink">{{ $option->share() }}%</native:text>
</native:row>
