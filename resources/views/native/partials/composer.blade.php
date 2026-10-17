{{--
    The top of the log, like the web's composer: your avatar staged on the rail beside "What did you ship?", which you
    type into where it is. Once you're in it, or it holds a draft, Commit sits under it, with the web's counter once
    few of the 4,000 characters are left. The ring is dashed until the commit is long enough to make, then solid.

    The field is keyed, so a new $composerKey (or a new list key) mounts a fresh one, clean after a commit. A touch on
    it is how the screen learns you're in it, since a field reports neither focus nor, on iOS, a caret in an empty
    field. The nav bar's compose button opens a composer in the sheet instead (see OpensCommitSheet).
--}}
<native:row ref="composer" class="w-full bg-theme-surface">
    <native:column :width="64" />

    <native:column class="flex-1">
        <native:column class="w-full pt-[22] pr-4 {{ $composerOpen ? 'pb-3 gap-3' : 'pb-[30]' }}">
            <native:column ref="composer-field" class="w-full" @pressUp="composerFocused">
                <native:bare-text-input
                    native:key="composer-{{ $composerKey }}"
                    ref="body"
                    class="w-full text-theme-ink"
                    placeholder="What did you ship?"
                    native:model.debounce.150ms="body"
                    multiline
                    :max-lines="8"
                    :max-length="\App\Devnull\Data\Commit::MaxLength"
                    size="lg"
                    a11y-label="Commit message"
                />
            </native:column>

            @if ($composerOpen)
                <native:row class="w-full gap-3 items-center justify-end">
                    @include('native.partials.body-counter', ['body' => $body, 'ref' => 'budget'])
                    <native:button ref="commit" variant="primary" :disabled="! $canCommit" @press="commit">Commit</native:button>
                </native:row>
            @endif
        </native:column>

        <native:column class="w-full h-[1] bg-theme-hairline" />
    </native:column>

    @include('native.partials.rail', ['lane' => $me->lane(), 'user' => $me, 'node' => $canCommit ? 'avatar' : 'staged', 'tail' => 'staged', 'tailDashes' => $composerOpen ? 6 : 2])
</native:row>
