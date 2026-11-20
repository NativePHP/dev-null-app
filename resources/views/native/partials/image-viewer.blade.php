{{--
    Images full-screen, over everything else on the screen, sheet included (see Concerns\ShowsImageViewer), on black
    that reaches every edge. Any screen can hang it: use the trait and include this once.

    It doesn't slide up. The black fades in over the screen while the tapped picture grows out of the image it was
    tapped in (morph-from, the source's ref) into its place here (morph-to), and closing runs that backwards: the black
    fades out while the picture shrinks back into the image on the screen. Paged to another image, it shrinks into
    that one's. With no source, or one that has gone off screen, it only fades.

    The fade and the morph need NativePHP/mobile-ui#221. Without it the modal ignores transition and morph-*, so the
    viewer slides up as before.

    Paging and the drag to close need NativePHP/mobile-ui#220 and NativePHP/mobile-air#716 to work at all. Each image's
    gesture area keeps its pans bound all the time, and without those PRs a bound pan takes every drag, so the pager
    never pages and a drag never closes the viewer. A double tap does nothing without them either.

    A swipe sideways pages through the images, one per page, and the dashes at the bottom say which is showing, the
    bright one sliding along as the page changes. A pinch zooms an image between its whole self and four times, and a
    double tap at its whole self zooms to twice, aimed at the tapped point; a double tap on a zoomed image takes it
    back to its whole self. A zoomed image pans. All of it runs on the phone with no frame trip. Bounded by the
    picture's own shape, it can't be lost off screen. The phone decides who gets a drag: a zoomed image takes it while
    it has room to move, and at its whole self the image leaves it to the pager and the drag to close.

    At its whole self, a drag up or down takes the image with the finger and fades the black and the buttons with it.
    Let go short of halfway to ImageViewer::DismissDistance and it springs back; past that, or with a flick, it carries on
    and the viewer closes. The round buttons over the top close it and save the image to the phone's photos, drawn as a
    sheet's bar buttons are (see sheet-bar-button).

    On iOS the modal's own close row is turned off, since the round close floats over the picture instead. Android
    keeps it, because the modal only closes on Android's back while it's dismissible, and dismissible draws the row.

    Always in the tree, shown or not, like the sheet, so the phone can animate it away and say when it's gone. Keyed
    so its pages coming and going don't shift the ids of whatever the screen draws after it.
--}}
@php($viewerOwnRow = \Native\Mobile\Platform::isAndroid())
<native:modal native:key="image-viewer" ref="image-viewer" :visible="$viewerOpen" :dismissible="$viewerOwnRow" transition="fade" :morph-from="$viewerSources[$viewerIndex] ?? ''" :morph-to="'viewed-image-'.$viewerIndex" a11y-label="Image viewer" @dismiss="closeImageViewer">
    @if ($viewerImages !== [])
        @php($far = \App\Devnull\ImageViewer::DismissDistance)
        @php($count = count($viewerImages))
        {{-- Faded by the drag either way: the outer layer as it goes up, the inner as it goes down. --}}
        @php($fadeUp = $viewerDrag->interpolate([-$far, 0], [0, 1]))
        @php($fadeDown = $viewerDrag->interpolate([0, $far], [1, 0]))
        <native:stack ref="viewer" class="w-full h-full">
            <native:column class="w-full h-full" :opacity="$fadeUp">
                <native:column ref="viewer-backdrop" class="w-full h-full bg-black" :opacity="$fadeDown" />
            </native:column>
            <native:gesture-area ref="viewer-dismiss" :pan-y="$viewerDrag" :snap-y="[-$far, 0, $far]" @dragEnd="viewerDragged" class="w-full h-full">
                @include('native.partials.image-viewer-pages')
            </native:gesture-area>
            <native:column class="w-full h-full" :opacity="$fadeUp">
                <native:column class="w-full h-full justify-between" :opacity="$fadeDown">
                    <native:row ref="viewer-bar" class="w-full px-4 pt-2 items-center {{ $viewerOwnRow ? 'justify-end' : 'justify-between' }}">
                        @unless ($viewerOwnRow)
                            @include('native.partials.sheet-bar-button', ['ref' => 'viewer-close', 'icon' => \App\Icons\Lucide::X, 'label' => 'Close', 'press' => 'closeImageViewer'])
                        @endunless
                        @include('native.partials.sheet-bar-button', ['ref' => 'viewer-save', 'icon' => \App\Icons\Lucide::Download, 'label' => 'Save to Photos', 'loading' => $savingViewedImage, 'press' => $savingViewedImage ? null : 'saveViewedImage'])
                    </native:row>
                    @if ($count > 1)
                        @php($step = 22)
                        <native:stack ref="viewer-dots" :width="$count * $step - 6" :height="3" class="self-center mb-6" a11y-label="Image {{ $viewerIndex + 1 }} of {{ $count }}">
                            <native:row class="w-full h-full gap-[6]">
                                @foreach ($viewerImages as $place => $image)
                                    <native:column :width="16" :height="3" class="rounded-full bg-white/35" />
                                @endforeach
                            </native:row>
                            <native:column ref="viewer-dot" :width="16" :height="3" class="rounded-full bg-white" :translate-x="($viewerIndex - ($count - 1) / 2) * $step" animate-duration="250" animate-easing="ease-out" />
                        </native:stack>
                    @endif
                </native:column>
            </native:column>
        </native:stack>
    @endif
</native:modal>
