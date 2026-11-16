{{--
    The web's BodyCounter, next to Commit or Reply: nothing until 200 characters are left, then the count in mono, amber
    from 20 and Rm Red at none. Before that, a body long enough to be folded in lists says "folded in feeds" in small
    muted text. It used to be the FoldVertical icon, which people read as a button they couldn't work out, and text
    that isn't a button can't be mistaken for one.
--}}
@php($remaining = \App\Devnull\Data\Commit::MaxLength - (($literal ?? false) ? mb_strlen(\App\Devnull\Data\CommitBody::normalize($body)) : \App\Devnull\Data\CommitBody::characterCount($body)))
@php($isCounting = $remaining <= \App\Devnull\Data\Commit::CountFrom)
@php($foldExplained = 'Feeds show the first '.\App\Devnull\Data\Commit::PreviewLength.' characters or '.\App\Devnull\Data\Commit::PreviewLines.' lines, then Show more.')
@if ($isCounting)
    <native:text ref="{{ $ref }}" font="mono" class="text-[13px] {{ $remaining <= 0 ? 'text-theme-destructive' : ($remaining <= 20 ? 'text-theme-pending' : 'text-theme-ink-muted') }}" a11y-label="{{ $remaining }} characters left">{{ $remaining }}</native:text>
@elseif (\App\Devnull\Data\Commit::isFoldedInLists($body))
    <native:text ref="{{ $ref }}-fold" font="mono" class="text-[11px] text-theme-ink-muted" :max-lines="1" a11y-label="{{ $foldExplained }}">folded in feeds</native:text>
@endif
