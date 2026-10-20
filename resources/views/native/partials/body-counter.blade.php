{{--
    The web's BodyCounter, next to Commit or Reply: nothing until 200 characters are left, then the count in mono, amber
    from 20 and Rm Red at none. Before that, a body long enough to be folded in lists shows the fold icon instead.
--}}
@php($remaining = \App\Devnull\Data\Commit::MaxLength - (($literal ?? false) ? mb_strlen(\App\Devnull\Data\CommitBody::normalize($body)) : \App\Devnull\Data\CommitBody::characterCount($body)))
@php($isCounting = $remaining <= \App\Devnull\Data\Commit::CountFrom)
@if ($isCounting)
    <native:text ref="{{ $ref }}" font="mono" class="text-[13px] {{ $remaining <= 0 ? 'text-theme-destructive' : ($remaining <= 20 ? 'text-theme-pending' : 'text-theme-ink-muted') }}" a11y-label="{{ $remaining }} characters left">{{ $remaining }}</native:text>
@elseif (\App\Devnull\Data\Commit::isFoldedInLists($body))
    <native:icon ref="{{ $ref }}-fold" :ios="\App\Icons\Lucide::FoldVertical->ios()" :android="\App\Icons\Lucide::FoldVertical->android()" :size="16" class="text-theme-ink-muted" a11y-label="Feeds show the first {{ \App\Devnull\Data\Commit::PreviewLength }} characters or {{ \App\Devnull\Data\Commit::PreviewLines }} lines, then Show more." />
@endif
