{{--
    The reply bar's field on a commit's page, across the top of partials/composer-box. EDGE sends no focus event, so a
    press on the field is how the page learns you're replying. The tools are in the row under it, so pressing them
    doesn't count. Where the caret is goes to the page as it moves, for the "@" suggestions above the bar (see
    SuggestsMentions). It keeps the keyboard up through presses elsewhere on the page (keep-focus), as the Remotes
    field does, so picking someone to mention leaves you typing after them. It grows with the reply to five lines and
    then scrolls, sized by the field itself, not by the page.
--}}
<native:column native:key="reply-field" ref="reply-field" class="flex-1" @pressUp="replyTapped">
    <native:bare-text-input
        ref="reply-input"
        class="w-full py-2 text-theme-ink"
        placeholder="Reply to {{ $commit->author->handle() }}"
        native:model.debounce.150ms="reply"
        :selection="$mentionCarets['reply'] ?? null"
        @selectionChange="caretMoved('reply')"
        :autofocus="$focusReply"
        multiline
        :max-lines="\App\Devnull\Display::ReplyBarMaxLines"
        :max-length="\App\Devnull\Data\Commit::MaxRawLength"
        keep-focus
    />
</native:column>
