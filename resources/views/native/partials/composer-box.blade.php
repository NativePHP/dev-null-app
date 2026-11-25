{{--
    A composer bar's field, tools and send button, laid out the way the Claude and Messages apps lay theirs out: the
    field across the top, growing to its five lines, and under it a row with the tools on the left and a round send
    button in your colour on the right. The same on both platforms. Before iOS 26 nothing is drawn around them: they sit
    straight on the bar, which is glass on iOS and the surface on Android, under the fade at its top (see
    partials/composer-fade). From iOS 26 they sit in a rounded Liquid Glass box over the system's scroll edge effect,
    unless Display::ComposerInGlassBox is off (see Display::composerBox()).

    $ref names the box ("{$ref}-box", also its key, so the field keeps its place as rows above it come and go) and its
    rows ("{$ref}-field-row", "{$ref}-actions"). $field is the partial that draws the field, keyed itself, so it stays
    the same view while the tools and everything else in the row under it change. $tools are composer-tool's, in the
    web's order. $lead and $trail, when there are any, are partials with their data ([view, data]) for the start of the
    row, ahead of the tools, and for the end, left of the send button, such as the count of a long draft. $send is
    send-button's: its ref, label, method, and whether it can send yet.

    The field's text lines up with the tools' icons under it. The field sits a little nearer the fade above it than
    the row under it: the row around the box has no top padding, and the field row leaves 8pt under the field. In the
    glass box the field sits as far under the box's edge as above the tools (Display::composerFieldTop()).
--}}
@php($lead ??= [])
@php($trail ??= [])
<native:column native:key="{{ $ref }}-box" ref="{{ $ref }}-box" class="w-full {{ \App\Devnull\Display::composerBox() }}">
    <native:row native:key="{{ $ref }}-field-row" ref="{{ $ref }}-field-row" class="w-full pl-4 pr-3 {{ \App\Devnull\Display::composerFieldTop() }} pb-2">
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
