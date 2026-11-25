{{--
    How long a Remotes draft is, beside the send button in partials/composer-box: nothing until 1,600 characters, then
    the count out of 2,000 in mono, amber from 1,900 and Rm Red at the limit.
--}}
@php($length = mb_strlen($draft))
@if ($length >= 1600)
    <native:text ref="message-length" font="mono" class="text-[11px] {{ $length >= \App\Devnull\Data\Message::MaxLength ? 'text-theme-destructive' : ($length >= 1900 ? 'text-theme-pending' : 'text-theme-ink-muted') }}">{{ number_format($length) }}/{{ number_format(\App\Devnull\Data\Message::MaxLength) }}</native:text>
@endif
