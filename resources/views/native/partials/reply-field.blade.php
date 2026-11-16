{{--
    The reply bar's field on a commit's page, across the top of partials/composer-box. EDGE sends no focus event, so a
    press on the field is how the page learns you're replying. The expand button is in the row under it, so pressing it
    doesn't count. Where the caret is goes to the page as it moves, for the "@" suggestions above the bar (see
    SuggestsMentions). It keeps the keyboard up through presses elsewhere on the page (keep-focus), as the Remotes
    field does, so picking someone to mention leaves you typing after them. The expand button grows it to
    Display::ExpandedReplyMinLines tall: the same field, only taller, so the keyboard doesn't move.
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
        :min-lines="$replyExpanded ? \App\Devnull\Display::ExpandedReplyMinLines : 1"
        :max-lines="$replyExpanded ? \App\Devnull\Display::ExpandedReplyMaxLines : \App\Devnull\Display::ReplyBarMaxLines"
        :max-length="\App\Devnull\Data\Commit::MaxRawLength"
        keep-focus
    />
</native:column>
