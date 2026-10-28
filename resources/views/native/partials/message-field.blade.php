{{--
    A Remotes thread's message field (see partials/composer-plate). It mounts afresh when you pick a message to reply
    to ($composerKey), so it takes focus and raises the keyboard, as the web focuses its field.
--}}
<native:column ref="message-field" class="flex-1 ios:pl-4">
    <native:bare-text-input
        native:key="message-input-{{ $composerKey }}"
        ref="message-input"
        class="w-full py-2 text-theme-ink"
        :autofocus="$composerKey > 0"
        :placeholder="$placeholder"
        native:model.debounce.150ms="draft"
        multiline
        :max-lines="5"
        :max-length="\App\Devnull\Data\Message::MaxLength"
        a11y-label="Message"
    />
</native:column>
