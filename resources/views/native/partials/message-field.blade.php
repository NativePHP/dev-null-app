{{--
    A Remotes thread's message field (see partials/composer-plate). It keeps the keyboard up through presses elsewhere
    in the thread (keep-focus), as the web keeps its field focused through Reply and Push, and its column is keyed, so
    the reply strip or photos coming and going above it never rebuild it. It mounts afresh only to take focus
    ($composerKey): replying while the field is idle, or attaching photos. EDGE sends no focus event, so a press into
    the field tells the thread it's in use.
--}}
<native:column native:key="message-field" ref="message-field" class="flex-1 ios:pl-4" @pressUp="fieldPressed">
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
        keep-focus
        a11y-label="Message"
    />
</native:column>
