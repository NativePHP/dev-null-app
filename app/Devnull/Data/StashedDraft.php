<?php

namespace App\Devnull\Data;

/**
 * A draft as the stash keeps it (see App\Devnull\Stash): what you wrote, its
 * photos with their descriptions, and its poll with how long it runs, as the
 * composer had them when you left it.
 */
final readonly class StashedDraft
{
    /**
     * @param  list<DraftImage>  $images
     * @param  list<string>|null  $poll  The poll's options, or null with no poll.
     */
    public function __construct(
        public string $body = '',
        public array $images = [],
        public ?array $poll = null,
        public string $pollLength = PollDraft::DefaultLength,
    ) {}

    /**
     * The draft as the stash keeps it, without photos that have gone from the phone. A string is a draft kept before
     * photos and polls were.
     */
    public static function fromArray(mixed $draft): self
    {
        if (is_string($draft)) {
            return new self($draft);
        }

        $draft = is_array($draft) ? $draft : [];
        $poll = is_array($draft['poll'] ?? null) ? $draft['poll'] : null;

        return new self(
            body: is_string($draft['body'] ?? null) ? $draft['body'] : '',
            images: array_values(array_filter(array_map(DraftImage::fromArray(...), is_array($draft['images'] ?? null) ? $draft['images'] : []))),
            poll: is_array($poll['options'] ?? null) ? array_values(array_filter($poll['options'], is_string(...))) : null,
            pollLength: is_string($poll['length'] ?? null) && array_key_exists($poll['length'], PollDraft::Lengths) ? $poll['length'] : PollDraft::DefaultLength,
        );
    }

    /**
     * @return array{body: string, images: list<array{path: string, mime_type: string, width: int, height: int, description: string}>, poll: array{options: list<string>, length: string}|null}
     */
    public function toArray(): array
    {
        return [
            'body' => $this->body,
            'images' => array_map(fn (DraftImage $image): array => $image->toArray(), $this->images),
            'poll' => $this->poll === null ? null : ['options' => $this->poll, 'length' => $this->pollLength],
        ];
    }

    /**
     * Whether there's nothing in it to keep: no body, no photos, and no poll with anything written in it. An empty
     * poll still comes back with the body it was added to.
     */
    public function isEmpty(): bool
    {
        return $this->body === '' && $this->images === [] && ($this->poll === null || array_all($this->poll, fn (string $option): bool => trim($option) === ''));
    }
}
