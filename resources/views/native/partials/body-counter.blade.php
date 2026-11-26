{{--
    The web's BodyCounter, next to Commit or Reply: nothing until 200 characters are left, then the count in mono, amber
    from 20 and Rm Red at none.
--}}
@php($remaining = \App\Devnull\Data\Commit::MaxLength - (($literal ?? false) ? mb_strlen(\App\Devnull\Data\CommitBody::normalize($body)) : \App\Devnull\Data\CommitBody::characterCount($body)))
@if ($remaining <= \App\Devnull\Data\Commit::CountFrom)
    <native:text ref="{{ $ref }}" font="mono" class="text-[13px] {{ $remaining <= 0 ? 'text-theme-destructive' : ($remaining <= 20 ? 'text-theme-pending' : 'text-theme-ink-muted') }}" a11y-label="{{ $remaining }} characters left">{{ $remaining }}</native:text>
@endif
