{{--
    The image viewer's pages, one image each, moved up and down together by the drag to close (see image-viewer).

    Each image pinches, zooms on a double tap and pans on its own gesture area, with its pans bound all the time.
    Bounded, the area only takes a one-finger drag while its zoomed image has room to move, so at its whole self a drag
    goes to the pager or the drag to close, and zoomed in the same drag pans it. The pinch and the double tap aim with
    those same pans: a pinch zooms around the fingers and a double tap aims at the tapped point. The phone does all of
    it, so PHP isn't told when a zoom ends. An image at no web address gets an empty page, never asked for (the pager
    can't skip a page, and the viewer never moves to it).
--}}
<native:column class="w-full h-full" :translate-y="$viewerDrag">
    <native:pager ref="viewer-pager" horizontal :page="$viewerIndex" on-page-change="viewerPaged" class="w-full h-full" a11y-label="Images">
        @foreach ($viewerImages as $place => $image)
            <native:column native:key="viewer-page-{{ $place }}" class="w-full h-full">
                @if (\App\Devnull\SiteLink::isWebUrl($image->url))
                    <native:gesture-area
                        ref="viewer-gestures-{{ $place }}"
                        :pinch="$viewerZooms[$place]"
                        pinch-min="1"
                        :pinch-max="\App\Devnull\ImageViewer::MaxZoom"
                        @doubleTap.zoom="{{ \App\Devnull\ImageViewer::DoubleTapZoom }}"
                        :pan-x="$viewerPansX[$place]"
                        :pan-y="$viewerPansY[$place]"
                        bounded
                        :content-ratio="$image->naturalRatio()"
                        class="w-full h-full"
                    >
                        <native:column class="w-full h-full items-center justify-center">
                            <native:image
                                ref="viewed-image-{{ $place }}"
                                :src="\App\Devnull\ImageCache::src($image->url)"
                                :fit="1"
                                :scale="$viewerZooms[$place]"
                                :translate-x="$viewerPansX[$place]"
                                :translate-y="$viewerPansY[$place]"
                                class="w-full h-full"
                                alt="{{ $image->label($place, count($viewerImages)) }}"
                            />
                        </native:column>
                    </native:gesture-area>
                @endif
            </native:column>
        @endforeach
    </native:pager>
</native:column>
