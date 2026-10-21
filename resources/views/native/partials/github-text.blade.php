@php($position = 0)
<native:text ref="{{ $ref }}" class="{{ $classes }}" :max-lines="$maxLines ?? 0">
@foreach (\App\Devnull\Data\Mention::inText($text) as $mention)
@if ($mention->offset > $position)
<native:text :text="mb_substr($text, $position, $mention->offset - $position)" />
@endif
<native:text ref="{{ $ref }}-mention-{{ $mention->offset }}" :text="mb_substr($text, $mention->offset, $mention->length)" class="font-medium text-theme-primary" @press="openGitHubMention('{{ $mention->username }}')" a11y-label="{{ '@'.$mention->username }} on GitHub" />
@php($position = $mention->offset + $mention->length)
@endforeach
@if ($position < mb_strlen($text))
<native:text :text="mb_substr($text, $position)" />
@endif
</native:text>
