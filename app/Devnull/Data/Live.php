<?php

namespace App\Devnull\Data;

use Carbon\CarbonImmutable;

/**
 * The stream a user is live on. devnull stops sending it once it ends, but a
 * kept user still has it, so whether it is on goes by when it ends.
 */
final readonly class Live
{
    public function __construct(
        public string $url,
        public CarbonImmutable $endsAt,
        public ?LivePlatform $platform = null,
        public ?string $thumbnailUrl = null,
    ) {}

    public static function fromArray(mixed $data): ?self
    {
        if (! is_array($data)) {
            return null;
        }

        $url = $data['url'] ?? null;
        $endsAt = Commit::date($data['ends_at'] ?? null);

        if (! is_string($url) || $url === '' || $endsAt === null) {
            return null;
        }

        $platform = $data['platform'] ?? null;
        $thumbnailUrl = $data['thumbnail_url'] ?? null;

        return new self(
            url: $url,
            endsAt: $endsAt,
            platform: is_string($platform) ? LivePlatform::tryFrom($platform) : null,
            thumbnailUrl: is_string($thumbnailUrl) && $thumbnailUrl !== '' ? $thumbnailUrl : null,
        );
    }

    /**
     * The stream in the API's shape, so it can be kept and read back.
     *
     * @return array{platform: ?string, url: string, thumbnail_url: ?string, ends_at: string}
     */
    public function toArray(): array
    {
        return [
            'platform' => $this->platform?->value,
            'url' => $this->url,
            'thumbnail_url' => $this->thumbnailUrl,
            'ends_at' => $this->endsAt->toIso8601ZuluString(),
        ];
    }

    /**
     * Where the stream is, as the web's stream card names it.
     */
    public function host(): string
    {
        $host = parse_url($this->url, PHP_URL_HOST);

        return is_string($host) ? (string) preg_replace('/^www\./i', '', $host) : $this->url;
    }

    public function isOn(): bool
    {
        return $this->endsAt->isFuture();
    }
}
