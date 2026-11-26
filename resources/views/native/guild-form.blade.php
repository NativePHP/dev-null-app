{{--
    The web's guild dialog as a screen (see App\NativeComponents\GuildForm): what a guild is, the hashtag the name makes,
    the display name, the description, and the icon, an emoji from the picker or an image from your photos. The button
    rides above the keyboard in the bottom bar, with devnull's reason beside it when it says no, as on New org.
--}}
@use('App\Icons\Lucide')
@use('App\NativeComponents\GuildForm')
<native:column class="w-full h-full bg-theme-surface">
    <native:emoji-picker ref="guild-emoji-picker" :visible="$emojiPickerOpen" @change="emojiPicked" @dismiss="emojiPickerClosed" />

    <native:scroll-view class="w-full flex-1">
        <native:column class="w-full px-4 pt-3 gap-4">
            <native:text ref="guild-form-intro" class="w-full text-sm text-theme-ink-muted">{{ $isEditing ? 'As the moderator, you can change the display name, icon and description.' : 'Create a public group. Its name determines the hashtag that brings commits into the guild.' }}</native:text>

            <native:text ref="guild-form-hashtag" font="mono" class="w-full text-[13px] text-theme-ink-muted" :max-lines="1">{{ $hashtag === '' ? 'Your hashtag appears here' : '#'.$hashtag }}</native:text>

            <native:column class="w-full gap-2">
                <native:text class="text-sm text-theme-ink">Guild display name</native:text>
                <native:row class="w-full h-[40] px-3 items-center rounded-lg border border-theme-hairline bg-theme-background">
                    <native:bare-text-input
                        ref="guild-name"
                        class="w-full text-theme-ink"
                        native:model.debounce.250ms="name"
                        :max-length="GuildForm::NameMaxLength"
                        a11y-label="Guild display name"
                    />
                </native:row>
                <native:text class="w-full text-xs text-theme-ink-muted">Spaces and decoration are okay. Matching hashtags link to this guild.</native:text>
            </native:column>

            <native:outlined-text-input
                ref="guild-description"
                class="w-full"
                label="Description"
                native:model.debounce.250ms="description"
                multiline
                :min-lines="3"
                :max-lines="6"
                :max-length="GuildForm::DescriptionMaxLength"
            />

            <native:column class="w-full gap-2">
                <native:text class="text-sm text-theme-ink">Icon</native:text>
                <native:row class="w-full gap-3 items-center">
                    <native:pressable ref="guild-icon" @press="openEmojiPicker" a11y-label="Choose an emoji">
                        <native:stack :width="48" :height="48" class="rounded-lg bg-theme-wash" center>
                            @include('native.partials.guild-mark', ['guild' => $mark, 'size' => 48, 'ref' => 'guild-form-mark'])
                        </native:stack>
                    </native:pressable>
                    <native:button ref="choose-emoji" variant="secondary" size="sm" :ios-icon="Lucide::SmilePlus->ios()" :android-icon="Lucide::SmilePlus->android()" @press="openEmojiPicker">Emoji</native:button>
                    <native:button ref="upload-icon" variant="secondary" size="sm" :ios-icon="Lucide::ImagePlus->ios()" :android-icon="Lucide::ImagePlus->android()" @press="pickIcon">Upload image</native:button>
                </native:row>
                <native:text class="w-full text-xs text-theme-ink-muted">Choose an emoji or upload an image. JPEG, PNG or WebP, up to 2 MB. An upload replaces the emoji.</native:text>
            </native:column>

            {{-- The bottom bar sits over the end of the form, so the form clears it and the home indicator. --}}
            <native:column class="w-full h-[{{ 64 + \App\Devnull\Display::HomeIndicatorHeight }}]" />
        </native:column>
    </native:scroll-view>

    <native:bottom-bar>
        <native:column ref="guild-form-bar" class="w-full bg-theme-surface">
            <native:column class="w-full h-[1] border-b border-theme-hairline" />
            <native:row class="w-full px-4 py-3 gap-3 items-center">
                <native:text ref="guild-form-error" font="mono" class="flex-1 text-xs text-theme-destructive" :max-lines="2">{{ $formError ?? '' }}</native:text>
                <native:column class="w-[140]">
                    <native:button ref="save-guild" :variant="$myColour" :disabled="$saving || trim($name) === '' || trim($description) === ''" @press="save" class="w-full">{{ $isEditing ? 'Save changes' : 'Create guild' }}</native:button>
                </native:column>
            </native:row>
        </native:column>
    </native:bottom-bar>
</native:column>
