{{--
    The composer's tools, on a row of their own under what's being written, as the web's composer wraps them on a
    phone: photos from the library, a photo from the camera, a poll, and the preview. Up to three photos, or a poll,
    not both, as on the web, so each greys out while the other is there; a reply has no poll at all.
--}}
@php($photosFull = count($sheetImages) >= \App\Devnull\DraftImages::MaxImages)
<native:row ref="sheet-tools" class="w-full -ml-2 items-center">
    @include('native.partials.composer-tool', ['ref' => 'sheet-photos', 'icon' => \App\Icons\Lucide::ImagePlus, 'label' => 'Attach up to 3 photos', 'press' => 'pickPhotos', 'disabled' => $sheetPoll !== null || $photosFull || $sheetSending])
    @include('native.partials.composer-tool', ['ref' => 'sheet-camera', 'icon' => \App\Icons\Lucide::Camera, 'label' => 'Take a photo', 'press' => 'takePhoto', 'disabled' => $sheetPoll !== null || $photosFull || $sheetSending])
    @unless ($sheetView === 'reply')
        @include('native.partials.composer-tool', ['ref' => 'sheet-poll', 'icon' => \App\Icons\Lucide::ChartBar, 'label' => $sheetImages === [] ? 'Add poll' : 'A commit can have images or a poll, not both', 'press' => 'addPoll', 'disabled' => $sheetImages !== [] || $sheetPoll !== null || $sheetSending])
    @endunless
    @include('native.partials.composer-tool', ['ref' => 'sheet-preview', 'icon' => \App\Icons\Lucide::Eye, 'label' => $previewing ? 'Edit' : 'Preview', 'press' => 'togglePreview', 'active' => $previewing])
</native:row>
