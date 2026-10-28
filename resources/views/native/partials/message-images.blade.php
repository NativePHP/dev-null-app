{{--
    A message's images. A long press on them opens the message's menu, as on its bubble ($photosMenu), and a tap on one
    puts away any actions showing, as a tap anywhere in the thread does. The gap goes on a column inside the pressable:
    the menu's items are children of the pressable too, and iOS spaces them out like any other child.
--}}
@if ($message->images !== [])
    <native:pressable ref="photos-{{ $key }}" :context-menu="$photosMenu">
        <native:column class="gap-1">
            @foreach ($message->images as $index => $image)
                <native:image ref="image-{{ $key }}-{{ $index }}" @press="hideActions" :src="$image->url" :width="240" :height="(int) round(240 / $image->ratio())" :fit="2" class="rounded-xl bg-theme-wash" a11y-label="{{ $image->label($index, count($message->images)) }}" />
            @endforeach
        </native:column>
    </native:pressable>
@endif
