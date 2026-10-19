{{--
    Edit, on a scheduled commit still waiting: its body in the composer's field, and its time in the system's date and
    time picker, in the phone's timezone, within the 30 days devnull allows. Its poll and images stay as they are. Save
    waits for a body long enough to make (or any, with images) and a time the timezone doesn't skip. If devnull says
    no, why shows here and the edit stays. It's always on the screen, shown or not, so iOS can slide it away and say
    when it's gone.
--}}
<native:bottom-sheet ref="edit-sheet" :visible="$editOpen" detents="large" @dismiss="editSheetClosed">
    @if ($editingCommit)
        @php($editTimezone = $timezone)
        @php($editNow = \Carbon\CarbonImmutable::now($editTimezone))
        <native:scroll-view ref="edit-form" class="w-full flex-1">
            <native:column class="w-full px-5 pt-6 pb-10 gap-4">
                <native:text font="semibold" class="text-lg text-theme-ink">Edit scheduled commit</native:text>

                <native:bare-text-input
                    ref="edit-body"
                    class="w-full text-theme-ink"
                    placeholder="What did you ship?"
                    native:model.debounce.150ms="editBody"
                    multiline
                    :max-lines="8"
                    :max-length="\App\Devnull\Data\Commit::MaxRawLength"
                    size="lg"
                    a11y-label="Your commit"
                />

                @if ($editingCommit->pollOptions || $editingCommit->imageCount > 0)
                    <native:text ref="edit-attachments" class="text-xs leading-relaxed text-theme-ink-muted">{{ $editingCommit->pollOptions ? 'Its poll stays as it is.' : ($editingCommit->imageCount === 1 ? 'Its image stays as it is.' : 'Its images stay as they are.') }}</native:text>
                @endif

                <native:column class="w-full gap-2 rounded-lg border border-theme-hairline p-3">
                    <native:text font="medium" class="text-sm text-theme-ink">Submit at</native:text>
                    <native:date-picker
                        ref="edit-at"
                        class="w-full"
                        mode="datetime"
                        picker-style="compact"
                        placeholder="Pick a date and time"
                        :timezone="$editTimezone"
                        :min="$editNow"
                        :max="$editNow->addDays(30)"
                        native:model="editAt"
                        a11y-label="Submit at"
                    />
                    <native:text class="text-xs leading-relaxed text-theme-ink-muted">{{ $editTimezone }} ({{ \App\Devnull\Timezone::offset($editTimezone, $editAt === '' ? null : \Carbon\CarbonImmutable::parse($editAt, $editTimezone)) }}). Within 30 days.</native:text>
                </native:column>

                @if ($editError)
                    <native:text ref="edit-error" class="w-full text-sm text-theme-destructive">{{ $editError }}</native:text>
                @endif

                <native:row class="w-full gap-3 items-center justify-end">
                    @include('native.partials.body-counter', ['body' => $editBody, 'ref' => 'edit-budget'])
                    <native:button ref="save-edit" :variant="$myColour" :disabled="! $canSave" @press="saveEdit">Save</native:button>
                </native:row>
            </native:column>
        </native:scroll-view>
    @endif
</native:bottom-sheet>
