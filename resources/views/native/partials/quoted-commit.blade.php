{{--
    The commit a commit quotes, embedded under its body as the web's QuotedCommit: a hairline box, 8pt rounded, with a
    20pt avatar, the name, mono handle and time on one line, then the body clamped to six lines, and its poll, static.
    The whole box opens the quoted commit. One you may no longer see, or one that was deleted, is a dashed box in git's
    voice instead, with no author and nothing to open. Quotes embed one level only.

    $static draws the card under the quote you're writing, for $quoted, the commit you're quoting, or over the reply
    you're writing, $label 'Replying to'. It opens nothing.
--}}
@php($static ??= false)
@php($label ??= 'Quoting')
@php($quoted = $static ? $quoted : $commit->quotedCommit)
@if ($quoted === null || $quoted->isDeleted())
    <native:column ref="quoted-{{ $commit->id }}" class="w-full mt-2">
        <native:text class="w-full px-3 py-2 text-sm text-theme-ink-muted"><native:text font="mono" class="text-xs">{{ $quoted === null ? '[unavailable]' : '[deleted]' }}</native:text> {{ $quoted === null ? "This commit isn't available." : 'This commit was deleted.' }}</native:text>
        @include('native.partials.dashed-box')
    </native:column>
@else
    @php($quotedAuthor = $quoted->author)
    @if ($static)
        <native:column ref="quote-card" class="w-full mt-2" a11y-label="{{ $label }} {{ $quotedAuthor->name }}">
    @else
        <native:pressable ref="quoted-{{ $commit->id }}" @press="openCommit('{{ $quoted->id }}')" class="w-full mt-2" a11y-label="Quoting {{ $quotedAuthor->name }}">
    @endif
        <native:column class="w-full px-3 py-2 gap-1 rounded-lg border border-theme-hairline">
            <native:row class="w-full gap-[6] items-center">
                @include('native.partials.avatar', ['user' => $quotedAuthor, 'size' => 20])
                <native:text font="semibold" class="text-sm text-theme-ink flex-shrink" :max-lines="1">{{ $quotedAuthor->name }}</native:text>
                <native:text font="mono" class="text-xs text-theme-ink-muted flex-shrink" :max-lines="1">{{ $quotedAuthor->handle() }}</native:text>
                <native:text class="text-xs text-theme-ink-muted">·</native:text>
                <native:text font="mono" class="text-xs text-theme-ink-muted flex-shrink-0">{{ \App\Devnull\Display::relativeTime($quoted->createdAt) }}</native:text>
                @unless ($quoted->isApproved())
                    @include('native.partials.status-chip', ['status' => $quoted->status, 'small' => true])
                @endunless
            </native:row>
            <native:text class="w-full text-[15px] leading-relaxed text-theme-ink" :max-lines="6">{{ $quoted->plainText() }}</native:text>
            @if ($quoted->poll)
                @include('native.partials.commit-poll', ['commit' => $quoted, 'static' => true])
            @endif
        </native:column>
    @if ($static)
        </native:column>
    @else
        </native:pressable>
    @endif
@endif
