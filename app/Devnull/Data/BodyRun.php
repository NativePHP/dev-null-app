<?php

namespace App\Devnull\Data;

/**
 * A run of a paragraph: plain prose, a mention, inline code, or a standalone
 * `/dev/null`, which draws as the wordmark.
 */
final readonly class BodyRun
{
    public function __construct(public string $text, public ?string $username = null, public bool $isCode = false, public bool $isWordmark = false) {}

    public function isMention(): bool
    {
        return $this->username !== null;
    }
}
