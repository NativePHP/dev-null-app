{{--
    The web's IssueCommandBadge, over the field of a draft that's an issue: $command in a soft chip, whose X turns the
    draft back into a commit, then where the issue goes. The X's target is the chip's full height.
--}}
<native:row ref="issue-badge" class="w-full gap-2 items-center">
    <native:row class="gap-[6] items-center pl-2 rounded-md bg-theme-primary-soft">
        <native:icon :ios="$command->icon()->ios()" :android="$command->icon()->android()" :size="14" class="text-theme-primary" />
        <native:text font="mono" class="text-[13px] text-theme-primary">{{ $command->command() }}</native:text>
        <native:pressable ref="issue-cancel" @press="cancelIssue" a11y-label="Cancel {{ $command->command() }}, back to a commit">
            <native:column class="min-w-[36] h-[32] items-center justify-center">
                <native:icon :ios="\App\Icons\Lucide::X->ios()" :android="\App\Icons\Lucide::X->android()" :size="14" class="text-theme-primary" />
            </native:column>
        </native:pressable>
    </native:row>
    <native:text class="flex-1 text-[13px] text-theme-ink-muted" :max-lines="1">Public issue on {{ \App\Devnull\IssueCommand::Repository }}, crediting you</native:text>
</native:row>
