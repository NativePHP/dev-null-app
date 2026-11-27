{{--
    A message's images, on the wash and edged in their sender's colour like a bubble (see partials/message-item), so a
    dark image doesn't melt into a dark thread. A long press on them opens the message's menu, as on its bubble
    ($photosMenu). A tap on one opens the message's images full-screen from that one (see
    Conversation::openMessageImage), once devnull has the message: a message still being pushed has its images only on
    the phone.
--}}
@if ($message->images !== [])
    <native:pressable ref="photos-{{ $key }}" class="gap-1" :context-menu="$photosMenu">
        @foreach ($message->images as $index => $image)
            @php($shape = $image->width > 0 && $image->height > 0 ? $image->width / $image->height : $image->ratio())
            @php($imageWidth = (int) round(min(240, 240 * $shape)))
            @if ($message->isPending())
                <native:image ref="image-{{ $key }}-{{ $index }}" :src="$image->url" :width="$imageWidth" :height="(int) round($imageWidth / $shape)" :fit="2" class="rounded-xl {{ $tighten(! $startsRun || $index > 0, ! $endsRun || $index < count($message->images) - 1 || $message->voice !== null || $message->body !== '') }} {{ $bubble }}/60" a11y-label="{{ $image->label($index, count($message->images)) }}" />
            @else
                <native:image ref="image-{{ $key }}-{{ $index }}" @press="openMessageImage({{ $message->id }}, {{ $index }})" :src="$image->url" :width="$imageWidth" :height="(int) round($imageWidth / $shape)" :fit="2" class="rounded-xl {{ $tighten(! $startsRun || $index > 0, ! $endsRun || $index < count($message->images) - 1 || $message->voice !== null || $message->body !== '') }} {{ $bubble }}" a11y-label="{{ $image->label($index, count($message->images)) }}" />
            @endif
        @endforeach
    </native:pressable>
@endif
