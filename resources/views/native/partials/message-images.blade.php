{{--
    A message's images. A long press on them opens the message's menu, as on its bubble ($photosMenu), and a tap on one
    puts away any actions showing, as a tap anywhere in the thread does.
--}}
@if ($message->images !== [])
    <native:pressable ref="photos-{{ $key }}" class="gap-1" :context-menu="$photosMenu">
        @foreach ($message->images as $index => $image)
            <native:image ref="image-{{ $key }}-{{ $index }}" @press="hideActions" :src="$image->url" :width="240" :height="(int) round(240 / $image->ratio())" :fit="2" class="rounded-xl bg-theme-wash" a11y-label="{{ $image->label($index, count($message->images)) }}" />
        @endforeach
    </native:pressable>
@endif
