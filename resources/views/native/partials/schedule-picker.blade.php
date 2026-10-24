{{--
    The web's "Submit at", under a new commit's field once its clock button is on: the system's date and time picker
    (a compact one on iOS, which opens the calendar and the time over the sheet), in the phone's timezone, from now to
    the 30 days devnull allows. Under it, the timezone with its offset at the time picked, and the web's lines on what
    scheduling means, then Scheduled commits →, which opens the list of them.
--}}
@php($scheduleTimezone = app(\App\Devnull\Timezone::class)->here())
@php($scheduleNow = \Carbon\CarbonImmutable::now($scheduleTimezone))
@php($pickedAt = $scheduledAt === '' ? null : \Carbon\CarbonImmutable::parse($scheduledAt, $scheduleTimezone))
<native:column ref="schedule" class="w-full gap-2 rounded-lg border border-theme-hairline p-3">
    <native:text font="medium" class="text-sm text-theme-ink">Submit at</native:text>
    <native:date-picker
        ref="scheduled-at"
        class="w-full"
        mode="datetime"
        picker-style="compact"
        placeholder="Pick a date and time"
        :timezone="$scheduleTimezone"
        :min="$scheduleNow"
        :max="$scheduleNow->addDays(30)"
        native:model="scheduledAt"
        a11y-label="Submit at"
    />
    <native:text ref="schedule-help" class="text-xs leading-relaxed text-theme-ink-muted">{{ $scheduleTimezone }} ({{ \App\Devnull\Timezone::offset($scheduleTimezone, $pickedAt) }}). Within 30 days. Private until submitted; then submitted for review, not published automatically.</native:text>
    <native:pressable ref="open-scheduled" @press="openScheduledCommits" a11y-label="Scheduled commits">
        <native:text font="medium" class="text-xs text-theme-primary">Scheduled commits →</native:text>
    </native:pressable>
</native:column>
