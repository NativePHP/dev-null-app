{{-- The web's people search field: a search glyph inside a bordered field, the term typed after it. --}}
<native:row ref="people-search" class="w-full h-[40] px-3 gap-2 items-center rounded-lg border border-theme-hairline bg-theme-background">
    <native:icon :ios="\App\Icons\Lucide::Search->ios()" :android="\App\Icons\Lucide::Search->android()" :size="16" class="text-theme-ink-muted" />
    <native:column class="flex-1">
        <native:bare-text-input
            ref="people-query"
            class="w-full text-theme-ink"
            :placeholder="$placeholder"
            native:model.debounce.250ms="query"
            :autofocus="$autofocus ?? false"
            :max-length="100"
            a11y-label="Find people"
        />
    </native:column>
</native:row>
