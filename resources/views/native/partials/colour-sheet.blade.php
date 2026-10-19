{{--
    The sheet Custom opens on the Account screen, standing in for the system colour picker the web's Custom opens: a
    swatch of the colour being mixed and its hex, then a slider each for hue, saturation and lightness, and Use color,
    which previews it on the screen behind for Save color to save, like any other colour.

    The sliders send every step, not once the finger rests, so the swatch follows the drag. It's drawn in the colour
    itself rather than a lane's token, so dragging adds nothing to the theme. Only the colour you use becomes a token,
    when the screen behind draws it.
--}}
<native:bottom-sheet ref="colour-sheet" :visible="$mixingColour" detents="medium" @dismiss="colourSheetClosed">
    <native:column class="w-full px-5 pt-6 pb-8 gap-5">
        <native:text font="semibold" class="text-lg text-theme-ink">Custom color</native:text>

        <native:row ref="mixed-colour" class="w-full gap-3 items-center">
            <native:column ref="mixed-swatch" :width="44" :height="44" class="rounded-full bg-[{{ $mixedColour }}] flex-shrink-0" />
            <native:text font="mono" class="flex-1 text-base text-theme-ink">{{ $mixedColour }}</native:text>
        </native:row>

        <native:column class="w-full gap-1">
            <native:row class="w-full items-center">
                <native:text class="flex-1 text-sm text-theme-ink-muted">Hue</native:text>
                <native:text ref="hue-value" font="mono" class="w-[40] text-sm text-theme-ink text-right">{{ (int) $hue }}</native:text>
            </native:row>
            <native:slider ref="hue" :min="0" :max="360" :step="1" native:model.live="hue" class="w-full" a11y-label="Hue" />
        </native:column>

        <native:column class="w-full gap-1">
            <native:row class="w-full items-center">
                <native:text class="flex-1 text-sm text-theme-ink-muted">Saturation</native:text>
                <native:text ref="saturation-value" font="mono" class="w-[40] text-sm text-theme-ink text-right">{{ (int) $saturation }}</native:text>
            </native:row>
            <native:slider ref="saturation" :min="0" :max="100" :step="1" native:model.live="saturation" class="w-full" a11y-label="Saturation" />
        </native:column>

        <native:column class="w-full gap-1">
            <native:row class="w-full items-center">
                <native:text class="flex-1 text-sm text-theme-ink-muted">Lightness</native:text>
                <native:text ref="lightness-value" font="mono" class="w-[40] text-sm text-theme-ink text-right">{{ (int) $lightness }}</native:text>
            </native:row>
            <native:slider ref="lightness" :min="0" :max="100" :step="1" native:model.live="lightness" class="w-full" a11y-label="Lightness" />
        </native:column>

        <native:button ref="use-colour" :variant="$myColour" size="lg" @press="useMixedColour" class="w-full">Use color</native:button>
    </native:column>
</native:bottom-sheet>
