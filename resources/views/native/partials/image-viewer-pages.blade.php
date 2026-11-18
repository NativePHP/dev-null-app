{{--
    The image viewer's pages, one image each, moved up and down together by the drag to close (see image-viewer).

    Each image pinches, and zooms on a double tap, on its own gesture area. Only the showing image, once zoomed, binds a
    pan: an area with a pan claims every touch, which would stop the pager paging and the drag to close. The zoom gets
    the same pans as zoom-pan, which takes no drags, so a pinch zooms around the fingers and a double tap aims at the
    tapped point from the first. Either one reports where it left the zoom through @pinchEnd, which binds the pans or
    lets them go. An image at no web address gets an empty page, never asked for (the pager can't skip a page, and the
    viewer never moves to it).
--}}
<native:column class="w-full h-full" :translate-y="$viewerDrag">
    <native:pager ref="viewer-pager" horizontal :page="$viewerIndex" on-page-change="viewerPaged" class="w-full h-full" a11y-label="Images">
        @foreach ($viewerImages as $place => $image)
            @php($pans = $place === $viewerIndex && $viewerZoomed)
            <native:column native:key="viewer-page-{{ $place }}" class="w-full h-full">
                @if (\App\Devnull\SiteLink::isWebUrl($image->url))
                    <native:gesture-area
                        ref="viewer-gestures-{{ $place }}"
                        :pinch="$viewerZooms[$place]"
                        pinch-min="1"
                        :pinch-max="\App\Devnull\ImageViewer::MaxZoom"
                        :double-tap-zoom="\App\Devnull\ImageViewer::DoubleTapZoom"
                        @pinchEnd="viewerZoomEnded"
                        :pan-x="$pans ? $viewerPansX[$place] : null"
                        :pan-y="$pans ? $viewerPansY[$place] : null"
                        :zoom-pan-x="$viewerPansX[$place]"
                        :zoom-pan-y="$viewerPansY[$place]"
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
