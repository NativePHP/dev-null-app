{{--
    The reply as it will look, in the reply bar's field's place, while Preview is on (see CommitDetail::toggleReplyPreview()):
    its body as devnull keeps it, with its photos. Held to about half a screen, and scrolls past that, so a long reply
    doesn't push the commit off the top.
--}}
<native:column native:key="reply-preview-pane" ref="reply-preview-pane" class="flex-1 py-2">
    <native:scroll-view ref="reply-preview-scroll" class="w-full max-h-[320]">
        @include('native.partials.composer-preview', [
            'ref' => 'reply-draft',
            'body' => $reply,
            'preview' => $replyPreview,
            'images' => $replyImages,
            'poll' => null,
            'pollLength' => \App\Devnull\Data\PollDraft::DefaultLength,
            'quoted' => null,
            'me' => $viewer,
        ])
    </native:scroll-view>
</native:column>
