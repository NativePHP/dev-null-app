{{--
    The photos attached to a draft, as the web's ImagePickerPreviews: 80pt squares, each with ✕ to take it off and
    ALT to describe it for people who can't see it, inverted once it has a description. The open description sits
    under the photos with its count out of 1,000 and Done.
--}}
@if ($sheetImages !== [])
    <native:column ref="sheet-images" class="w-full gap-2">
        <native:row class="w-full gap-2" a11y-label="Attached photos">
            @foreach ($sheetImages as $index => $image)
                <native:column ref="sheet-image-{{ $index }}" :width="80" :height="80" class="rounded-md border border-theme-hairline bg-theme-wash">
                    <native:image :src="$image->path" :width="80" :height="80" :fit="2" alt="Attached photo {{ $index + 1 }}" class="rounded-md" />
                    <native:pressable ref="sheet-image-{{ $index }}-remove" @press="removePhoto({{ $index }})" class="absolute top-0 right-0" a11y-label="Remove photo {{ $index + 1 }}">
                        <native:column class="w-[32] h-[32] items-center justify-center">
                            <native:column class="w-[20] h-[20] items-center justify-center rounded-full bg-theme-surface/80">
                                <native:icon :ios="\App\Icons\Lucide::X->ios()" :android="\App\Icons\Lucide::X->android()" :size="14" class="text-theme-ink" />
                            </native:column>
                        </native:column>
                    </native:pressable>
                    <native:pressable ref="sheet-image-{{ $index }}-alt" @press="describePhoto({{ $index }})" class="absolute bottom-0 left-0" a11y-label="{{ $image->isDescribed() ? 'Edit description of photo '.($index + 1) : 'Add description to photo '.($index + 1) }}">
                        <native:column class="p-1">
                            <native:text font="bold" class="px-1 rounded text-[11px] {{ $image->isDescribed() ? 'bg-theme-ink text-theme-surface' : 'bg-theme-surface/80 text-theme-ink' }}">{{ $image->isDescribed() ? 'ALT' : '+ALT' }}</native:text>
                        </native:column>
                    </native:pressable>
                </native:column>
            @endforeach
        </native:row>

        @if ($describing !== null && isset($sheetImages[$describing]))
            @php($described = $sheetImages[$describing])
            <native:column ref="sheet-image-description" class="w-full p-2 gap-1 rounded-md border border-theme-hairline">
                <native:text class="text-xs text-theme-ink-muted">Description of photo {{ $describing + 1 }}</native:text>
                <native:bare-text-input
                    ref="sheet-image-description-input"
                    class="w-full text-sm text-theme-ink"
                    placeholder="Describe the photo for people who can't see it"
                    :value="$described->description"
                    @change="photoDescribed({{ $describing }})"
                    sync-mode="debounce"
                    debounce-ms="150"
                    autofocus
                    multiline
                    :max-lines="4"
                    :max-length="\App\Devnull\DraftImages::MaxDescriptionLength"
                />
                <native:row class="w-full gap-3 items-center justify-end">
                    <native:text font="mono" class="text-xs text-theme-ink-muted">{{ mb_strlen($described->description) }}/{{ \App\Devnull\DraftImages::MaxDescriptionLength }}</native:text>
                    <native:button ref="sheet-image-description-done" variant="secondary" size="sm" @press="describePhoto({{ $describing }})">Done</native:button>
                </native:row>
            </native:column>
        @endif
    </native:column>
@endif
