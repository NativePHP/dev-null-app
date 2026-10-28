<?php

namespace App\Devnull\Data;

/**
 * A run of a paragraph: plain prose, a mention, inline code, a link, or a
 * standalone `/dev/null`, which draws as the wordmark, or a hex color, which
 * draws with a swatch of it. A link's text is the URL as written, and a
 * color's is the color as written, `#` included. A mention of a GitHub
 * organization has its handle in $username too, and $isOrganization set.
 */
final readonly class BodyRun
{
    public function __construct(public string $text, public ?string $username = null, public bool $isCode = false, public bool $isWordmark = false, public ?BodyLink $link = null, public ?string $color = null, public bool $isOrganization = false) {}

    public function isMention(): bool
    {
        return $this->username !== null;
    }

    /**
     * Whether this run is a hex color, whose digits are in $color.
     */
    public function isColor(): bool
    {
        return $this->color !== null;
    }
}
