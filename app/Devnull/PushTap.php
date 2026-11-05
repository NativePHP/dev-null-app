<?php

namespace App\Devnull;

/**
 * Where a tap on a push from devnull goes: the tab it belongs to, and the screen to open in it, if the push says
 * which. devnull puts the push's data in its link's query (devnull://push?type=replied&commit_id=…), and the
 * values are strings.
 */
final readonly class PushTap
{
    public function __construct(
        public string $tab,
        public ?string $path = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function from(array $data): self
    {
        $value = fn (string $key): ?string => is_string($data[$key] ?? null) && $data[$key] !== '' ? $data[$key] : null;

        return match ($value('type')) {
            'message', 'ping' => new self('/remotes', match (true) {
                $value('kind') === 'org' && $value('conversation_id') !== null => '/orgs/'.rawurlencode($value('conversation_id')),
                $value('kind') === 'direct' && $value('handle') !== null => '/conversations/'.rawurlencode($value('handle')),
                default => null,
            }),
            'replied', 'mentioned' => new self('/notifications', $value('commit_id') === null ? null : '/commits/'.rawurlencode($value('commit_id'))),
            'followed' => new self('/notifications', $value('handle') === null ? null : '/users/'.rawurlencode($value('handle'))),
            default => new self('/notifications'),
        };
    }
}
