<?php

namespace App\Devnull\Data;

/** The optional, cache-only translation attached to a signed commit. */
final readonly class CommitTranslation
{
    /**
     * @param  list<BodyBlock>|null  $content
     * @param  list<BodyBlock>|null  $previewContent
     */
    public function __construct(
        public string $language,
        public ?string $sourceLanguage,
        public ?string $sourceName,
        public bool $available,
        public ?array $content = null,
        public ?array $previewContent = null,
    ) {}

    /** Whether preferences and a per-post override select this language's cached content. */
    public function isDisplayed(string $language, bool $automatic, ?string $display = null): bool
    {
        return $this->language === $language && $this->content !== null
            && ($display ?? ($automatic ? 'translated' : 'original')) === 'translated';
    }

    /**
     * Reads optional metadata without ever assigning translated segments to the original commit body.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        if (! is_string($data['language'] ?? null) || $data['language'] === '') {
            return null;
        }

        $translated = is_array($data['translated'] ?? null) ? $data['translated'] : [];
        $segments = self::segments($translated['body_segments'] ?? null);
        $preview = self::segments($translated['preview_segments'] ?? null);
        $body = $segments === null ? '' : self::body($segments);

        return new self(
            language: $data['language'],
            sourceLanguage: is_string($data['source_language'] ?? null) ? $data['source_language'] : null,
            sourceName: is_string($data['source_name'] ?? null) ? $data['source_name'] : null,
            available: ($data['available'] ?? false) === true,
            content: $segments === null ? null : CommitBody::blocks($body, $segments),
            previewContent: $preview === null ? null : CommitBody::blocks(self::body($preview), $preview),
        );
    }

    /**
     * Rebuild fences from the highlighter's escaped code, never rendering its HTML.
     * Mention offsets from the original are deliberately not reused.
     *
     * @param  list<array<string, mixed>>  $segments
     */
    private static function body(array $segments): string
    {
        $body = '';

        foreach ($segments as $segment) {
            $body .= match ($segment['type'] ?? null) {
                'text' => is_string($segment['text'] ?? null) ? $segment['text'] : '',
                'code' => '`'.(is_string($segment['code'] ?? null) ? $segment['code'] : '').'`',
                'block' => self::fencedCode($segment),
                default => '',
            };
        }

        return $body;
    }

    /**
     * Decodes escaped highlighter text and chooses a fence longer than any literal code backticks.
     *
     * @param  array<string, mixed>  $segment
     */
    private static function fencedCode(array $segment): string
    {
        $code = html_entity_decode(strip_tags(is_string($segment['html'] ?? null) ? $segment['html'] : ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        preg_match_all('/`+/', $code, $matches);
        $length = max([2, ...array_map(strlen(...), $matches[0])]) + 1;
        $fence = str_repeat('`', $length);

        return "\n{$fence}\n{$code}\n{$fence}\n";
    }

    /**
     * Missing segment arrays mean no cached translation, not an empty translated body.
     *
     * @return list<array<string, mixed>>|null
     */
    private static function segments(mixed $segments): ?array
    {
        return is_array($segments) ? array_values(array_filter($segments, is_array(...))) : null;
    }
}
