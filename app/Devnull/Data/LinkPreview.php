<?php

namespace App\Devnull\Data;

/**
 * The Open Graph card devnull fetched for the first link in a commit.
 */
final readonly class LinkPreview
{
    public function __construct(
        public string $url,
        public ?string $title = null,
        public ?string $description = null,
        public ?string $imageUrl = null,
        public ?string $siteName = null,
        public ?Embed $embed = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $url = $data['url'] ?? null;

        if (! is_string($url) || $url === '') {
            return null;
        }

        return new self(
            url: $url,
            title: self::string($data['title'] ?? null),
            description: self::string($data['description'] ?? null),
            imageUrl: self::string($data['image_url'] ?? null),
            siteName: self::string($data['site_name'] ?? null),
            embed: is_array($data['embed'] ?? null) ? Embed::fromArray($data['embed']) : null,
        );
    }

    /**
     * The web only shows a preview once the page has given it a title.
     */
    public function isReady(): bool
    {
        return $this->title !== null;
    }

    /**
     * The site's own name, or the host without "www.", as the web prints it.
     */
    public function site(): string
    {
        if ($this->siteName !== null) {
            return $this->siteName;
        }

        $host = parse_url($this->url, PHP_URL_HOST);

        return is_string($host) ? (string) preg_replace('/^www\./', '', $host) : $this->url;
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
