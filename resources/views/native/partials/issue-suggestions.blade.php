{{--
    Under a new commit's field, the web's two ways into an issue (see DraftsIssues). While the draft is only a slash and
    the start of a command, the web's command menu lists $suggestions, and a tap starts that issue, empty. A plain draft
    that mentions a bug or a feature gets the web's $hint instead: Use /bug makes the draft that issue as it is, and the
    X stops the hints on this phone.
--}}
@if ($suggestions !== [])
    <native:column ref="issue-commands" class="w-full p-1 rounded-md border border-theme-hairline bg-theme-surface" a11y-label="Commands">
        @foreach ($suggestions as $suggestion)
            <native:pressable ref="issue-command-{{ $suggestion->value }}" @press="pickIssueCommand('{{ $suggestion->value }}')" class="w-full" a11y-label="{{ $suggestion->command() }}, {{ $suggestion->description() }}">
                <native:row class="w-full min-h-[44] px-2 gap-3 items-center">
                    <native:icon :ios="$suggestion->icon()->ios()" :android="$suggestion->icon()->android()" :size="16" class="text-theme-ink-muted" />
                    <native:text font="mono" class="w-[80] text-sm text-theme-ink">{{ $suggestion->command() }}</native:text>
                    <native:text class="flex-1 text-sm text-theme-ink-muted" :max-lines="1">{{ $suggestion->description() }}</native:text>
                </native:row>
            </native:pressable>
        @endforeach
    </native:column>
@elseif ($hint)
    <native:row ref="issue-hint" class="w-full gap-2 items-center">
        <native:icon :ios="$hint->icon()->ios()" :android="$hint->icon()->android()" :size="14" class="text-theme-ink-muted" />
        <native:text class="flex-shrink text-[13px] text-theme-ink-muted" :max-lines="1">{{ $hint->hint() }}</native:text>
        <native:pressable ref="issue-hint-use" @press="useIssueCommand('{{ $hint->value }}')" a11y-label="Use {{ $hint->command() }}">
            <native:column class="h-[32] justify-center">
                <native:text font="mono" class="text-[13px] text-theme-primary">Use {{ $hint->command() }}</native:text>
            </native:column>
        </native:pressable>
        <native:pressable ref="issue-hint-dismiss" @press="dismissIssueHint" a11y-label="Don't suggest issue commands again">
            <native:column class="min-w-[32] h-[32] items-center justify-center">
                <native:icon :ios="\App\Icons\Lucide::X->ios()" :android="\App\Icons\Lucide::X->android()" :size="14" class="text-theme-ink-muted" />
            </native:column>
        </native:pressable>
    </native:row>
@endif
