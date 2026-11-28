{{--
    The inside of a text element for plain text, with each standalone /dev/null as the wordmark, where the web's
    WordmarkText draws one: a guild's description and a Remote's preview (see CommitBody::wordmarkRuns and
    partials/wordmark). Text without one is the text alone, as before.

    The runs sit flush with no line breaks between them, since a line break in text would print.
--}}@php($runs = \App\Devnull\Data\CommitBody::wordmarkRuns($text))
@if (array_any($runs, fn ($run): bool => $run->isWordmark))
@foreach ($runs as $run)
@if ($run->isWordmark)
@include('native.partials.wordmark', ['afterSpace' => $loop->index > 0 && ctype_space(substr($runs[$loop->index - 1]->text, -1))])
@else
<native:text :text="$run->text" />
@endif
@endforeach
@else
{{ $text }}
@endif
