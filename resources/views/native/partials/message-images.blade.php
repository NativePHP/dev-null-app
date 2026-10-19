{{-- A message's images, each a tap away from React and Reply. --}}
@foreach ($message->images as $index => $image)
    <native:image ref="image-{{ $key }}-{{ $index }}" @press="toggleActions({{ $message->id }})" :src="$image->url" :width="240" :height="(int) round(240 / $image->ratio())" :fit="2" class="rounded-xl bg-theme-wash" a11y-label="{{ $image->label($index, count($message->images)) }}" />
@endforeach
