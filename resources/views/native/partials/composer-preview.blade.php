{{--
    A draft as the commit it would become, in place of the field, as the web's ComposerPreviewBody: its body as devnull
    would keep it, with its code and mentions once devnull has said, then its photos, its poll's options and how long it
    runs once published, and the commit it quotes. With nothing to show yet, it says so.

    The composer sheet and the reply bar on a commit's page both show one. $ref names it ("{$ref}-preview", and
    "{$ref}-poll" for the poll), $body is the draft, $preview what devnull said of it (see AttachesToCommits), $images
    its photos, $poll its options or null, $pollLength how long the poll runs, and $quoted the commit it quotes or null.
--}}
@php($draftCommit = \App\Devnull\Data\Draft::asCommit($me ?? app(\App\Devnull\Account::class)->user() ?? \App\Devnull\Data\User::fromArray([]), $body, $preview, $images, $poll, $quoted))
<native:column ref="{{ $ref }}-preview" class="w-full" a11y-label="Preview">
    @if (trim($body) === '' && $images === [] && $poll === null)
        <native:text class="w-full text-[15px] leading-relaxed text-theme-ink-muted">Nothing to preview yet.</native:text>
    @else
        @include('native.partials.commit-body', ['commit' => $draftCommit, 'focused' => true, 'static' => true])
        @include('native.partials.commit-media', ['commit' => $draftCommit, 'withEmbeds' => false, 'scoreAction' => false])
        @if ($draftCommit->poll)
            <native:column ref="{{ $ref }}-poll" class="w-full mt-3 gap-2" a11y-label="Poll options">
                @foreach ($draftCommit->poll->options as $option)
                    <native:column class="w-full px-3 py-2 rounded-md border border-theme-hairline">
                        <native:text class="w-full text-[15px] text-theme-ink">{{ $option->label }}</native:text>
                    </native:column>
                @endforeach
                <native:text font="mono" class="text-[13px] text-theme-ink-muted">{{ \App\Devnull\Data\PollDraft::runs($pollLength) }}</native:text>
            </native:column>
        @endif
        @if ($draftCommit->quotedCommit)
            @include('native.partials.quoted-commit', ['commit' => $draftCommit, 'quoted' => $draftCommit->quotedCommit, 'static' => true])
        @endif
    @endif
</native:column>
