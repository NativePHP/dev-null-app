{{--
    A composer bar's field, tools and send button in one rounded box, the way the Claude and Messages apps draw theirs:
    the field across the top, growing to its five lines, and under it a row with the tools on the left and a round
    send button in your colour on the right, with anything that counts the draft beside it. The same on both
    platforms. The bar around it is the backdrop: Liquid Glass tinted with the surface on iOS, so the thread
    scrolling under it blurs instead of showing through, and the solid surface with a hairline on Android.

    $ref names the box ("{$ref}-box", also its key, so the field keeps its place as rows above it come and go) and its
    rows ("{$ref}-field-row", "{$ref}-actions"). $field is the partial that draws the field, keyed itself, so it stays
    the same view while the tools and the counter under it change. $tools are composer-tool's, in the web's order.
    $counter, when there is one, is a partial and its data ([view, data]) for the count left of the send button.
    $send is send-button's: its ref, label, method, and whether it can send yet.
--}}
@php($counter ??= null)
<native:column native:key="{{ $ref }}-box" ref="{{ $ref }}-box" class="w-full rounded-[22] border border-theme-hairline bg-theme-surface">
    <native:row native:key="{{ $ref }}-field-row" ref="{{ $ref }}-field-row" class="w-full px-3 pt-1">
        @include($field)
    </native:row>
    <native:row native:key="{{ $ref }}-actions" ref="{{ $ref }}-actions" class="w-full h-[40] pl-1 pr-[6] pb-1 gap-2 items-center">
        @foreach ($tools as $tool)
            @include('native.partials.composer-tool', $tool)
        @endforeach
        <native:spacer />
        @if ($counter !== null)
            @include($counter[0], $counter[1] ?? [])
        @endif
        @include('native.partials.send-button', $send)
    </native:row>
</native:column>
