<?php

namespace App\Devnull;

/**
 * Measures the image viewer works to (see Concerns\ShowsImageViewer and partials/image-viewer).
 */
final class ImageViewer
{
    /**
     * How far a drag up or down takes the image before the viewer goes, in points. Letting go short of half of it,
     * without a flick, springs the image back.
     */
    public const float DismissDistance = 240;

    /**
     * The zoom past which an image counts as zoomed in, so a pinch that ends a hair over its whole self still pages.
     */
    public const float ZoomedPast = 1.01;

    /**
     * The most an image zooms, as many times its whole self.
     */
    public const float MaxZoom = 4;
}
