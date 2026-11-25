{{--
    A message's images. A long press on them opens the message's menu, as on its bubble ($photosMenu). A tap on one
    opens the message's images full-screen from that one (see Conversation::openMessageImage), once devnull has the
    message: a message still being pushed has its images only on the phone.
--}}
@if ($message->images !== [])
    <native:pressable ref="photos-{{ $key }}" class="gap-1" :context-menu="$photosMenu">
        @foreach ($message->images as $index => $image)
            @if ($message->isPending())
                <native:image ref="image-{{ $key }}-{{ $index }}" :src="$image->url" :width="240" :height="(int) round(240 / $image->ratio())" :fit="2" class="rounded-xl bg-theme-wash" a11y-label="{{ $image->label($index, count($message->images)) }}" />
            @else
                <native:image ref="image-{{ $key }}-{{ $index }}" @press="openMessageImage({{ $message->id }}, {{ $index }})" :src="$image->url" :width="240" :height="(int) round(240 / $image->ratio())" :fit="2" class="rounded-xl bg-theme-wash" a11y-label="{{ $image->label($index, count($message->images)) }}" />
            @endif
        @endforeach
    </native:pressable>
@endif
