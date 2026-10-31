{{--
    A commit's images full-screen, over everything else on the screen, sheet included (see ShowsCommits): the image
    the row tapped, whole rather than cropped as it is in the row, on black. A pinch zooms between the image's whole
    self and four times, around the fingers, and a drag pans it; both run on the phone with no frame trip, so the
    picture follows a finger. Bounded by the picture's own shape, it can't be lost off screen: zoomed, its edges stop at
    the screen's, and it stays centred on a side it fits; dragged past that it resists and springs back on release, so
    at its whole self it always comes back to the middle. The modal's own close, and Android's back, close it.

    Several images in a commit move with the chevrons under the picture, which say their place among them, and each
    move puts the picture back at rest, as closing and opening another image does. An image still loading, or one that
    fails, shows whatever its platform's loader shows meanwhile — iOS its spinner, Android nothing — since the image
    element tells the app nothing about either, there's nothing of the app's to draw here.

    The commit sits under the image in a pane, as the web docks it beside its lightbox: its author, then the whole
    commit, the pane as tall as it. A drag takes the pane down to its author line, to see more of the picture, and back
    up again, as the web's dock button does; the commit stays pinned to the top, cut off at the bottom. The drag runs on
    the phone, and only where it comes to rest comes back (see ShowsCommits::viewerPaneMoved()). The pane is drawn in
    the viewer itself, so the picture behind keeps its pinch and pan, and the picture keeps the room the pane takes when
    it's down, so the chevrons stay above it.

    Always in the tree, shown or not, like the sheet, so the phone can animate it away and say when it's gone.
    Included beside the sheet, so every screen that shows commits has it once.
--}}
<native:modal ref="image-viewer" :visible="$viewerOpen" a11y-label="Image viewer" @dismiss="closeImageViewer">
    @if ($viewerImages !== [])
        @php($image = $viewerImages[$viewerIndex])
        <native:stack class="w-full h-full bg-black">
        <native:column class="w-full h-full safe-area-bottom {{ $viewerCommit ? 'pb-['.\App\Devnull\Display::ViewerPaneRoom.']' : '' }}">
            <native:gesture-area ref="viewer-gestures" :pinch="$viewerZoom" pinch-min="1" pinch-max="4" :pan-x="$viewerPanX" :pan-y="$viewerPanY" bounded :content-ratio="$image->naturalRatio()" class="w-full flex-1">
                <native:column class="w-full h-full items-center justify-center">
                    <native:image
                        ref="viewed-image"
                        :src="\App\Devnull\ImageCache::src($image->url)"
                        :fit="1"
                        :scale="$viewerZoom"
                        :translate-x="$viewerPanX"
                        :translate-y="$viewerPanY"
                        class="w-full h-full"
                        alt="{{ $image->label($viewerIndex, count($viewerImages)) }}"
                    />
                </native:column>
            </native:gesture-area>
            @if (count($viewerImages) > 1)
                <native:row ref="viewer-count" class="w-full items-center justify-center gap-8 py-2">
                    @if ($viewerIndex > 0)
                        <native:pressable ref="viewed-previous" @press="viewPreviousImage" a11y-label="Previous image">
                            <native:stack :width="44" :height="44" center class="rounded-full bg-white/20">
                                <native:icon :ios="\App\Icons\Lucide::ChevronLeft->ios()" :android="\App\Icons\Lucide::ChevronLeft->android()" :size="22" class="text-white" />
                            </native:stack>
                        </native:pressable>
                    @endif
                    <native:text font="mono" class="text-sm text-white">{{ $viewerIndex + 1 }} of {{ count($viewerImages) }}</native:text>
                    @if ($viewerIndex < count($viewerImages) - 1)
                        <native:pressable ref="viewed-next" @press="viewNextImage" a11y-label="Next image">
                            <native:stack :width="44" :height="44" center class="rounded-full bg-white/20">
                                <native:icon :ios="\App\Icons\Lucide::ChevronRight->ios()" :android="\App\Icons\Lucide::ChevronRight->android()" :size="22" class="text-white" />
                            </native:stack>
                        </native:pressable>
                    @endif
                </native:row>
            @endif
        </native:column>

        @if ($viewerCommit)
            @php($author = $viewerCommit->author)
            <native:sheet-pane ref="viewer-pane" :detents="\App\Devnull\Display::ViewerPaneDown.',fit'" :detent="$viewerPaneDetent" @change="viewerPaneMoved" class="w-full h-full" a11y-label="The commit">
                <native:column class="w-full px-5 pt-2 pb-5 gap-3">
                    <native:row class="w-full justify-center">
                        <native:column :width="36" :height="5" class="rounded-full bg-theme-hairline" />
                    </native:row>
                    <native:row ref="viewer-author" class="w-full gap-3 items-center">
                        @include('native.partials.rail-avatar', ['user' => $author, 'lane' => $viewerCommit->lane(), 'size' => 48])
                        <native:column class="flex-1 gap-[2]">
                            <native:text font="semibold" class="text-base text-theme-ink" :max-lines="1">{{ $author->name }}</native:text>
                            <native:text font="mono" class="text-sm text-theme-ink-muted" :max-lines="1">{{ $author->handle() }} · {{ \App\Devnull\Display::relativeTime($viewerCommit->createdAt) }}</native:text>
                        </native:column>
                    </native:row>
                    <native:column ref="viewer-commit" class="w-full gap-1">
                        @include('native.partials.commit-body', ['commit' => $viewerCommit, 'focused' => true])
                    </native:column>
                </native:column>
            </native:sheet-pane>
        @endif
        </native:stack>
    @endif
</native:modal>
