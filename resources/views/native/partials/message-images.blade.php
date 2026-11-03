{{--
    A message's images. A long press on them opens the message's menu, as on its bubble ($photosMenu).
--}}
@if ($message->images !== [])
    <native:pressable ref="photos-{{ $key }}" class="gap-1" :context-menu="$photosMenu">
        @foreach ($message->images as $index => $image)
            <native:image ref="image-{{ $key }}-{{ $index }}" :src="$image->url" :width="240" :height="(int) round(240 / $image->ratio())" :fit="2" class="rounded-xl bg-theme-wash" a11y-label="{{ $image->label($index, count($message->images)) }}" />
        @endforeach
    </native:pressable>
@endif
