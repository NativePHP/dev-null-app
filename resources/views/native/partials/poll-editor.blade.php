{{--
    The poll in a new commit or a quote, as the web's PollEditor: a hairline box with an outlined field per option,
    two to start, ✕ on the third and fourth, "Add option" until there are four, then "Poll length" and Remove poll
    under a hairline. Options are up to 50 characters, as devnull takes them.
--}}
<native:column ref="poll-editor" class="w-full p-3 gap-2 rounded-lg border border-theme-hairline">
    @foreach ($sheetPoll as $index => $option)
        <native:row class="w-full gap-2 items-center">
            <native:outlined-text-input
                ref="poll-option-{{ $index }}"
                class="flex-1"
                label="Option {{ $index + 1 }}"
                :value="$option"
                @change="pollOptionChanged({{ $index }})"
                sync-mode="debounce"
                debounce-ms="150"
                :autofocus="$index === 0 && $option === ''"
                :max-length="\App\Devnull\Data\PollDraft::MaxOptionLength"
            />
            @if ($index >= \App\Devnull\Data\PollDraft::MinOptions)
                <native:pressable ref="poll-option-{{ $index }}-remove" @press="removePollOption({{ $index }})" a11y-label="Remove option {{ $index + 1 }}">
                    <native:column class="min-w-[44] h-[44] items-center justify-center">
                        <native:icon :ios="\App\Icons\Lucide::X->ios()" :android="\App\Icons\Lucide::X->android()" :size="16" class="text-theme-ink-muted" />
                    </native:column>
                </native:pressable>
            @endif
        </native:row>
    @endforeach

    @if (count($sheetPoll) < \App\Devnull\Data\PollDraft::MaxOptions)
        <native:pressable ref="poll-add-option" @press="addPollOption" class="-ml-1" a11y-label="Add option">
            <native:row class="h-[36] px-1 gap-1 items-center">
                <native:icon :ios="\App\Icons\Lucide::Plus->ios()" :android="\App\Icons\Lucide::Plus->android()" :size="16" class="text-theme-primary" />
                <native:text font="medium" class="text-sm text-theme-primary">Add option</native:text>
            </native:row>
        </native:pressable>
    @endif

    <native:column class="w-full h-[1] bg-theme-hairline" />
    <native:row class="w-full gap-3 items-center">
        <native:column class="flex-1">
            <native:select ref="poll-length" label="Poll length" :options="array_keys(\App\Devnull\Data\PollDraft::Lengths)" native:model="sheetPollLength" />
        </native:column>
        <native:pressable ref="poll-remove" @press="removePoll" a11y-label="Remove poll">
            <native:column class="h-[44] px-2 items-center justify-center">
                <native:text class="text-sm text-theme-ink-muted">Remove poll</native:text>
            </native:column>
        </native:pressable>
    </native:row>
</native:column>
