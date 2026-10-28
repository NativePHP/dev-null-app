{{-- A message's images. A tap on one puts away any actions showing, as a tap anywhere in the thread does. --}}
@foreach ($message->images as $index => $image)
    <native:image ref="image-{{ $key }}-{{ $index }}" @press="hideActions" :src="$image->url" :width="240" :height="(int) round(240 / $image->ratio())" :fit="2" class="rounded-xl bg-theme-wash" a11y-label="{{ $image->label($index, count($message->images)) }}" />
@endforeach
