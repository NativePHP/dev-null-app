<?php

namespace App\Devnull\Data;

/**
 * One achievement on a profile's shelf, unlocked or on its way.
 */
final readonly class Badge
{
    public function __construct(
        public string $id,
        public string $name,
        public string $description = '',
        public string $icon = '',
        public bool $unlocked = false,
        public int $progress = 0,
        public int $target = 1,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            description: (string) ($data['description'] ?? ''),
            icon: (string) ($data['icon'] ?? ''),
            unlocked: ($data['unlocked'] ?? false) === true,
            progress: is_int($data['progress'] ?? null) ? max(0, $data['progress']) : 0,
            target: is_int($data['target'] ?? null) ? max(1, $data['target']) : 1,
        );
    }

    /**
     * The web only counts progress on a locked badge that takes more than one step: "(3/10)".
     */
    public function showsProgress(): bool
    {
        return ! $this->unlocked && $this->target > 1;
    }

    /**
     * "unlocked", or how far along it is: "3 of 10".
     */
    public function statusLabel(): string
    {
        return $this->unlocked ? 'unlocked' : "{$this->progress} of {$this->target}";
    }

    /**
     * How far along it is as a whole percentage, for the bar under a locked badge in the dialog.
     */
    public function progressPercent(): int
    {
        return min(100, (int) round($this->progress / $this->target * 100));
    }
}
