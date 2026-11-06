{{-- Shared translation notice/actions, separate from full or compact body rendering. --}}
@if ($translationEligible)
    <native:column class="w-full pt-2 gap-1">
        @if ($translated)
            <native:text ref="translation-source-{{ $commit->id }}" class="text-xs text-theme-ink-muted">Translated from {{ $commit->translation->sourceName ?? $commit->translation->sourceLanguage ?? 'another language' }}</native:text>
            <native:pressable ref="show-original-{{ $commit->id }}" @press="showOriginal('{{ $commit->id }}')" a11y-label="Show original" class="min-h-[44] justify-center">
                <native:text class="text-sm text-theme-primary">Show original</native:text>
            </native:pressable>
        @else
            <native:button ref="translate-{{ $commit->id }}" variant="ghost" size="sm" :loading="isset($translating[$commit->id])" :disabled="isset($translating[$commit->id])" @press="translateCommit('{{ $commit->id }}')" a11y-label="Translate commit">{{ isset($translating[$commit->id]) ? 'Translating…' : 'Translate' }}</native:button>
        @endif
    </native:column>
@endif
