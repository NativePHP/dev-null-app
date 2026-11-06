{{-- Only 50 matching rows are mounted at once; search includes English and native language names. --}}
<native:bottom-sheet ref="translation-language-sheet" :visible="$pickingTranslationLanguage" detents="large" @dismiss="translationLanguageSheetClosed">
    <native:column class="w-full flex-1">
        <native:column class="w-full px-5 pt-6 pb-3 gap-3">
            <native:text font="semibold" class="text-lg text-theme-ink">Translation language</native:text>
            <native:outlined-text-input ref="translation-language-search" class="w-full" label="Find a language" native:model.debounce.300ms="translationLanguageQuery" a11y-label="Find a language" />
        </native:column>
        <native:scroll-view ref="translation-language-list" class="w-full flex-1">
            <native:column class="w-full pb-10">
                <native:pressable ref="translation-language-phone" @press="chooseTranslationLanguage(null)" a11y-label="Use phone language{{ $chosenTranslationLanguage === null ? ', selected' : '' }}">
                    <native:row class="w-full px-5 py-4 gap-3 items-center">
                        <native:column :width="16">
                            @if ($chosenTranslationLanguage === null)
                                <native:icon :ios="\App\Icons\Lucide::Check->ios()" :android="\App\Icons\Lucide::Check->android()" :size="16" class="text-theme-primary" />
                            @endif
                        </native:column>
                        <native:text class="flex-1 text-[15px] text-theme-ink">Use phone language</native:text>
                    </native:row>
                </native:pressable>
                @forelse ($translationLanguages as $language)
                    <native:column class="w-full h-[1] bg-theme-hairline" />
                    <native:pressable ref="translation-language-{{ $language['code'] }}" @press="chooseTranslationLanguage('{{ $language['code'] }}')" a11y-label="{{ $language['name'] }} {{ $language['native_name'] }}{{ $language['code'] === $chosenTranslationLanguage ? ', selected' : '' }}">
                        <native:row class="w-full px-5 py-3 gap-3 items-center">
                            <native:column :width="16">
                                @if ($language['code'] === $chosenTranslationLanguage)
                                    <native:icon :ios="\App\Icons\Lucide::Check->ios()" :android="\App\Icons\Lucide::Check->android()" :size="16" class="text-theme-primary" />
                                @endif
                            </native:column>
                            <native:column class="flex-1 gap-[2]">
                                <native:text class="text-[15px] text-theme-ink">{{ $language['name'] }}</native:text>
                                <native:text class="text-xs text-theme-ink-muted">{{ $language['native_name'] }}</native:text>
                            </native:column>
                        </native:row>
                    </native:pressable>
                @empty
                    <native:text ref="translation-language-none" class="w-full px-5 py-6 text-sm text-theme-ink-muted">No language matches "{{ trim($translationLanguageQuery) }}".</native:text>
                @endforelse
                @if ($translationLanguagesMore > 0)
                    <native:text ref="translation-language-more" class="w-full px-5 py-4 text-sm text-theme-ink-muted">{{ $translationLanguagesMore }} more. Keep typing to find yours.</native:text>
                @endif
            </native:column>
        </native:scroll-view>
    </native:column>
</native:bottom-sheet>
