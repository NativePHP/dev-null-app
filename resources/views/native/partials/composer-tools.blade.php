{{--
    The composer's tools, on the left of the toolbar pinned to the bottom of the sheet: photos from the library, a photo
    from the camera, a poll, the clock that schedules a new commit, and the preview. Up to three photos, or a poll,
    not both, as on the web, so each greys out while the other is there; a reply has no poll at all.
--}}
@php($photosFull = count($sheetImages) >= \App\Devnull\DraftImages::MaxImages)
<native:row ref="sheet-tools" class="items-center">
    @include('native.partials.composer-tool', ['ref' => 'sheet-photos', 'icon' => \App\Icons\Lucide::ImagePlus, 'label' => 'Attach up to 3 photos', 'press' => 'pickPhotos', 'disabled' => $sheetPoll !== null || $photosFull])
    @include('native.partials.composer-tool', ['ref' => 'sheet-camera', 'icon' => \App\Icons\Lucide::Camera, 'label' => 'Take a photo', 'press' => 'takePhoto', 'disabled' => $sheetPoll !== null || $photosFull])
    @unless ($sheetView === 'reply')
        @include('native.partials.composer-tool', ['ref' => 'sheet-poll', 'icon' => \App\Icons\Lucide::ChartBar, 'label' => $sheetImages === [] ? 'Add poll' : 'A commit can have images or a poll, not both', 'press' => 'addPoll', 'disabled' => $sheetImages !== [] || $sheetPoll !== null])
    @endunless
    @if ($sheetView === 'compose')
        @include('native.partials.composer-tool', ['ref' => 'sheet-schedule', 'icon' => \App\Icons\Lucide::Clock3, 'label' => $scheduling ? "Don't schedule" : 'Schedule for later', 'press' => 'toggleSchedule', 'active' => $scheduling, 'disabled' => $sendingSchedule])
    @endif
    @include('native.partials.composer-tool', ['ref' => 'sheet-preview', 'icon' => \App\Icons\Lucide::Eye, 'label' => $previewing ? 'Edit' : 'Preview', 'press' => 'togglePreview', 'active' => $previewing])
</native:row>
