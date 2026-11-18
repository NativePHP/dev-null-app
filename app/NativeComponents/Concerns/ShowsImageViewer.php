<?php

namespace App\NativeComponents\Concerns;

use App\Devnull\Data\CommitImage;
use App\Devnull\ImageCache;
use App\Devnull\ImageViewer;
use App\Devnull\SiteLink;
use App\Devnull\Toasts;
use Devnull\Photos\Events\Saved;
use Devnull\Photos\Events\SaveFailed;
use Devnull\Photos\Facades\Photos;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\SharedValue;
use Throwable;

/**
 * Images full-screen, over everything else on the screen, sheet included (see partials/image-viewer): a commit's,
 * or a message's, from the one tapped. Any screen can show it: use this trait, include the partial once, and call
 * showImagesInViewer() with the images, the one to start from, and the refs of the images on the screen they were
 * opened from, which the viewer grows out of as it opens and shrinks back into as it closes.
 *
 * Swiping sideways pages through the images, which the dashes under them follow. A pinch zooms one between its whole
 * self and four times, a double tap zooms to twice and back, and a zoomed one pans. Dragging one up or down at its
 * whole self takes the viewer with it, and letting go far enough, or flicking it, closes the viewer. The round buttons
 * over the top close it and save the image to the phone's photos.
 */
trait ShowsImageViewer
{
    /**
     * Whether the image viewer is up.
     */
    public bool $viewerOpen = false;

    /**
     * The images being viewed, as they were when the viewer opened, so it keeps what it shows while it goes away even
     * if their commit or message leaves the screen.
     *
     * @var list<CommitImage>
     */
    public array $viewerImages = [];

    /**
     * Which of the viewer's images is showing.
     */
    public int $viewerIndex = 0;

    /**
     * The ref of the image on the screen each of the viewer's images was opened from, by place: the picture grows
     * out of the showing one's as the viewer opens, and shrinks back into it as it closes. An image with none, or
     * whose one is off screen, fades in and out instead.
     *
     * @var array<int, string>
     */
    public array $viewerSources = [];

    /**
     * The zoom and pan of each image, by its place, driven on the phone by its page's gesture area. Fresh ones when
     * the viewer opens and when a page is left, so each image starts at rest; emptied on close.
     *
     * @var array<int, SharedValue>
     */
    public array $viewerZooms = [];

    /**
     * @var array<int, SharedValue>
     */
    public array $viewerPansX = [];

    /**
     * @var array<int, SharedValue>
     */
    public array $viewerPansY = [];

    /**
     * Whether the showing image is zoomed in, as its last pinch left it. Zoomed, a drag pans it; at its whole self a
     * drag sideways pages and a drag up or down closes.
     */
    public bool $viewerZoomed = false;

    /**
     * How far the image has been dragged up or down to close the viewer, on the phone.
     */
    public ?SharedValue $viewerDrag = null;

    /**
     * Whether a save to the phone's photos is under way, so a second tap doesn't save it twice.
     */
    public bool $savingViewedImage = false;

    /**
     * Opens the viewer on $images, from the one at $index. $sources are the refs of the `native:image`s on the screen
     * they were tapped in, in the same order. An image at no web address opens nothing, and the viewer never pages
     * to one.
     *
     * @param  list<CommitImage>  $images
     * @param  list<string>  $sources
     */
    public function showImagesInViewer(array $images, int $index = 0, array $sources = []): void
    {
        $images = array_values($images);
        $image = $images[$index] ?? null;

        if ($image === null || ! SiteLink::isWebUrl($image->url)) {
            return;
        }

        $this->viewerImages = $images;
        $this->viewerIndex = $index;
        $this->viewerSources = array_slice(array_values($sources), 0, count($images));
        $this->viewerZooms = $this->viewerPansX = $this->viewerPansY = [];

        foreach (array_keys($images) as $place) {
            $this->restViewerImage($place);
        }

        $this->viewerZoomed = false;
        $this->viewerDrag = SharedValue::make();
        $this->savingViewedImage = false;
        $this->viewerOpen = true;
    }

    /**
     * The pager settled on another image. The one left goes back to rest, as Photos puts it, and the one arrived at
     * starts unzoomed. An image at no web address can't be paged to: the viewer stays where it was.
     */
    public function viewerPaged(int $index): void
    {
        $image = $this->viewerImages[$index] ?? null;

        if (! $this->viewerOpen || $index === $this->viewerIndex || $image === null || ! SiteLink::isWebUrl($image->url)) {
            return;
        }

        $this->restViewerImage($this->viewerIndex);
        $this->viewerIndex = $index;
        $this->viewerZoomed = false;
    }

    /**
     * A pinch or a double tap on the showing image ended, at $scale.
     */
    public function viewerZoomEnded(float $scale): void
    {
        $this->viewerZoomed = $this->viewerOpen && $scale > ImageViewer::ZoomedPast;
    }

    /**
     * The image was let go after a drag up or down, settled at $y: at rest, or off to one side, which closes the
     * viewer.
     */
    public function viewerDragged(float $x, float $y): void
    {
        if (abs($y) >= ImageViewer::DismissDistance / 2) {
            $this->closeImageViewer();
        }
    }

    /**
     * The viewer's close, a drag away, or Android's back: it goes and forgets the images and what their gestures
     * left behind, so the next one opens as this one did.
     */
    public function closeImageViewer(): void
    {
        $this->viewerOpen = false;
        $this->viewerImages = [];
        $this->viewerIndex = 0;
        $this->viewerSources = [];
        $this->viewerZooms = $this->viewerPansX = $this->viewerPansY = [];
        $this->viewerZoomed = false;
        $this->viewerDrag = null;
        $this->savingViewedImage = false;
    }

    /**
     * Saves the showing image to the phone's photos, from the copy the app already has where there is one. The phone
     * may ask first, and says how it went (see viewedImageSaved()).
     */
    public function saveViewedImage(): void
    {
        $image = $this->viewerImages[$this->viewerIndex] ?? null;

        if (! $this->viewerOpen || $this->savingViewedImage || $image === null || ! SiteLink::isWebUrl($image->url)) {
            return;
        }

        $path = $this->viewedImageFile($image->url);

        if ($path === null || ! Photos::save($path)) {
            Toasts::failed('Could not save the image. Try again.');

            return;
        }

        $this->savingViewedImage = true;
    }

    #[On(Saved::class)]
    public function viewedImageSaved(): void
    {
        $this->savingViewedImage = false;

        Toasts::say('Saved to Photos', short: true);
    }

    #[On(SaveFailed::class)]
    public function viewedImageNotSaved(string $reason = 'error'): void
    {
        $this->savingViewedImage = false;

        Toasts::failed($reason === 'denied'
            ? 'devnull isn\'t allowed to add to your photos. You can allow it in Settings.'
            : 'Could not save the image. Try again.');
    }

    /**
     * The image's file on the phone: the one the image cache keeps, or a fresh download into it.
     */
    private function viewedImageFile(string $url): ?string
    {
        $path = ImageCache::path($url);

        if (is_file($path)) {
            return $path;
        }

        try {
            $response = Http::timeout(20)->get($url);
        } catch (Throwable) {
            return null;
        }

        if (! $response instanceof Response || ! $response->successful() || ! str_starts_with((string) $response->header('Content-Type'), 'image/')) {
            return null;
        }

        File::ensureDirectoryExists(ImageCache::directory());
        File::put($path.'.part', $response->body());
        File::move($path.'.part', $path);

        return $path;
    }

    /**
     * Puts an image back at rest: fresh shared values, which the phone seeds at one and none, so what its last
     * gestures left behind can't carry over.
     */
    private function restViewerImage(int $place): void
    {
        $this->viewerZooms[$place] = SharedValue::make(1.0);
        $this->viewerPansX[$place] = SharedValue::make();
        $this->viewerPansY[$place] = SharedValue::make();
    }
}
