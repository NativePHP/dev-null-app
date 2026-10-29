{{--
    A composer bar's field and its tools, as the reply bar on a commit's page and a Remotes thread's message bar draw
    them, for the row that holds them with what follows (a counter, the send button). On iOS the field is a Liquid
    Glass plate and the tools sit inside it at its trailing edge, where iOS puts a field's own controls, staying at the
    bottom as the draft grows. On Android the tools lead the row, ahead of the field, and the row starts closer to the
    edge for them (ios:pl-4 android:pl-2).

    $ref names the plate ("{$ref}-plate", also its key, so the field keeps its place as rows above it come and go),
    $field is the partial that draws the field, and $tools are composer-tool's, in the web's order.
--}}
@php($toolsInField = \Native\Mobile\Platform::isIos())
@unless ($toolsInField)
    @foreach ($tools as $tool)
        @include('native.partials.composer-tool', $tool)
    @endforeach
@endunless
<native:row native:key="{{ $ref }}-plate" ref="{{ $ref }}-plate" class="flex-1 items-end ios:pr-1 ios:rounded-[18] ios:glass">
    @include($field)
    @if ($toolsInField)
        @foreach ($tools as $tool)
            @include('native.partials.composer-tool', $tool)
        @endforeach
    @endif
</native:row>
