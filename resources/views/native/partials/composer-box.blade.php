{{--
    A composer bar's field, tools and send button, laid out the way the Claude and Messages apps lay theirs out: the
    field across the top, growing to its five lines, and under it a row with the tools on the left and a round send
    button in your colour on the right. The same on both platforms. Nothing is drawn around them: they sit straight on
    the bar, which is glass on iOS and the surface on Android, under the fade at its top (see partials/composer-fade).

    $ref names the box ("{$ref}-box", also its key, so the field keeps its place as rows above it come and go) and its
    rows ("{$ref}-field-row", "{$ref}-actions"). $field is the partial that draws the field, keyed itself, so it stays
    the same view while the tools and everything else in the row under it change. $tools are composer-tool's, in the
    web's order. $lead and $trail, when there are any, are partials with their data ([view, data]) for the start of the
    row, ahead of the tools, and for the end, left of the send button, such as the count of a long draft. $send is
    send-button's: its ref, label, method, and whether it can send yet.

    The field's text lines up with the tools' icons under it. $fieldPadding is the field row's padding above and below
    the field (pt-1 by default), for a bar that wants more room between the field and the row under it.
--}}
@php($lead ??= [])
@php($fieldPadding ??= 'pt-1')
@php($trail ??= [])
<native:column native:key="{{ $ref }}-box" ref="{{ $ref }}-box" class="w-full">
    <native:row native:key="{{ $ref }}-field-row" ref="{{ $ref }}-field-row" class="w-full pl-4 pr-3 {{ $fieldPadding }}">
        @include($field)
    </native:row>
    <native:row native:key="{{ $ref }}-actions" ref="{{ $ref }}-actions" class="w-full h-[40] pl-1 pr-[6] pb-1 gap-2 items-center">
        @foreach ($lead as $part)
            @include($part[0], $part[1] ?? [])
        @endforeach
        @foreach ($tools as $tool)
            @include('native.partials.composer-tool', $tool)
        @endforeach
        <native:spacer />
        @foreach ($trail as $part)
            @include($part[0], $part[1] ?? [])
        @endforeach
        @include('native.partials.send-button', $send)
    </native:row>
</native:column>
