<?php

namespace App\Devnull\Data;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * A commit you scheduled, as devnull lists it for the web's Scheduled commits
 * page, which only you see. Until it's submitted it has its body, the options
 * of its poll, and how many images it carries. Once submitted, those are its
 * commit's, and its status is that commit's: pending review, approved,
 * rejected, or deleted.
 */
final readonly class ScheduledCommit
{
    /**
     * What the web calls each status once it's been submitted.
     */
    public const array Labels = [
        'pending' => 'Submitted for review',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'deleted' => 'Deleted',
    ];

    /**
     * @param  list<BodyBlock>  $content
     * @param  list<BodyBlock>|null  $previewContent
     * @param  list<string>|null  $pollOptions
     */
    public function __construct(
        public string $id,
        public ?string $body,
        public array $content,
        public ?array $previewContent,
        public ?array $pollOptions,
        public int $imageCount,
        public CarbonImmutable $scheduledAt,
        public ?CarbonImmutable $submittedAt,
        public string $status,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $body = is_string($data['body'] ?? null) ? $data['body'] : null;
        $segments = is_array($data['body_segments'] ?? null) && $data['body_segments'] !== [] ? array_values(array_filter($data['body_segments'], is_array(...))) : null;
        $previewSegments = is_array($data['preview_segments'] ?? null) ? array_values(array_filter($data['preview_segments'], is_array(...))) : null;
        $options = is_array($data['poll_options'] ?? null) ? array_values(array_filter($data['poll_options'], is_string(...))) : null;
        $status = $data['status'] ?? null;

        return new self(
            id: (string) ($data['id'] ?? ''),
            body: $body,
            content: $body === null ? [] : CommitBody::blocks($body, $segments),
            previewContent: $body === null || $previewSegments === null ? null : CommitBody::blocks($body, $previewSegments),
            pollOptions: $options,
            imageCount: max(0, (int) ($data['image_count'] ?? 0)),
            scheduledAt: Commit::date($data['scheduled_at'] ?? null) ?? CarbonImmutable::now(),
            submittedAt: Commit::date($data['submitted_at'] ?? null),
            status: is_string($status) && ($status === 'scheduled' || array_key_exists($status, self::Labels)) ? $status : 'scheduled',
        );
    }

    /**
     * Whether it's still waiting to be submitted, so it can be edited or canceled.
     */
    public function isWaiting(): bool
    {
        return $this->status === 'scheduled';
    }

    /**
     * Whether it's due and waiting for the scheduler, which submits due commits every minute.
     */
    public function isDue(?CarbonInterface $now = null): bool
    {
        return $this->isWaiting() && $this->scheduledAt->lessThanOrEqualTo($now ?? CarbonImmutable::now());
    }

    /**
     * What the web's list says of it.
     */
    public function label(?CarbonInterface $now = null): string
    {
        return match (true) {
            $this->isDue($now) => 'Waiting for submission',
            $this->isWaiting() => 'Scheduled',
            default => self::Labels[$this->status],
        };
    }

    /**
     * This one as devnull says it is now it's been committed early, keeping
     * what was sent, as the web's list keeps showing it.
     */
    public function submittedAs(self $now): self
    {
        return new self(...[...get_object_vars($this), 'submittedAt' => $now->submittedAt, 'status' => $now->status]);
    }

    /**
     * The links in its body, in order, which its rows open by their place among them, as a commit's do.
     *
     * @return list<BodyLink>
     */
    public function links(): array
    {
        $links = [];

        foreach ($this->content as $block) {
            foreach ($block->runs as $run) {
                if ($run->link !== null) {
                    $links[] = $run->link;
                }
            }
        }

        return $links;
    }

    /**
     * Whether its body is long, so the list shows only its start until "Show more", as a commit's is.
     */
    public function isFolded(): bool
    {
        return $this->previewContent !== null;
    }
}
