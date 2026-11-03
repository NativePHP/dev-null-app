<?php

namespace App\Devnull\NataNullers;

use App\Devnull\SshKey;
use App\Devnull\SshSignature;
use Illuminate\Support\Str;

/**
 * One finished game of Nata Nu//ers, signed by the device key that played it.
 *
 * The signature covers who played, which run, the score and when it ended, so
 * a score can't be lifted into someone else's commit or edited after the fact.
 * It proves which key signed it, not that the game was played fairly: the
 * phone is the referee.
 */
final readonly class Run
{
    public const string Namespace = 'devnull-nata-nullers';

    public function __construct(
        public string $id,
        public string $userId,
        public int $score,
        public int $endedAt,
        public string $signature,
    ) {}

    public static function sign(SshKey $key, string $userId, int $score, ?int $endedAt = null): self
    {
        $id = (string) Str::uuid();
        $endedAt ??= time();

        return new self($id, $userId, $score, $endedAt, $key->sign(self::message($id, $userId, $score, $endedAt), self::Namespace));
    }

    /**
     * What the device key signs, one field per line.
     */
    public static function message(string $id, string $userId, int $score, int $endedAt): string
    {
        return implode("\n", ['nata-nullers:v1', $userId, $id, (string) $score, (string) $endedAt]);
    }

    /**
     * The fingerprint of the key that signed this run, or null when the
     * signature doesn't hold for these fields.
     */
    public function signer(): ?string
    {
        return SshSignature::verify($this->signature, self::message($this->id, $this->userId, $this->score, $this->endedAt), self::Namespace);
    }

    /**
     * @return array{id: string, user_id: string, score: int, ended_at: int, signature: string}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'user_id' => $this->userId, 'score' => $this->score, 'ended_at' => $this->endedAt, 'signature' => $this->signature];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        if (! is_string($data['id'] ?? null) || ! is_string($data['user_id'] ?? null) || ! is_int($data['score'] ?? null) || ! is_int($data['ended_at'] ?? null) || ! is_string($data['signature'] ?? null)) {
            return null;
        }

        return new self($data['id'], $data['user_id'], $data['score'], $data['ended_at'], $data['signature']);
    }
}
