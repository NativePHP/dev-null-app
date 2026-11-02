{{--
    What the sheet says once devnull has a report, with Done, which presses $done.
--}}
<native:column ref="report-received" class="w-full px-5 pt-6 pb-6 gap-5">
    <native:row class="w-full gap-3 items-center">
        <native:icon :ios="\App\Icons\Lucide::Check->ios()" :android="\App\Icons\Lucide::Check->android()" :size="20" class="text-theme-primary" />
        <native:text class="flex-1 text-base text-theme-ink">Report received. Moderators will review it privately.</native:text>
    </native:row>
    <native:button ref="report-done" variant="secondary" size="lg" @press="{{ $done }}" class="w-full">Done</native:button>
</native:column>
