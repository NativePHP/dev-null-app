<?php

namespace App\Devnull\Data;

/**
 * Where the body mentions someone, or a GitHub organization devnull knows.
 * The offset and length count Unicode code points into the whole body and
 * include the "@".
 */
final readonly class Mention
{
    public function __construct(public string $username, public int $offset, public int $length, public bool $isOrganization = false) {}

    /**
     * GitHub accounts tagged in a bio or company, excluding emails, paths and package names.
     *
     * @return list<self>
     */
    public static function inText(string $text): array
    {
        preg_match_all('/(?<![\p{L}\p{N}_.\/@-])@([A-Za-z0-9](?:[A-Za-z0-9]|-(?=[A-Za-z0-9])){0,38})(?![A-Za-z0-9_@\/-]|\.[A-Za-z0-9])/u', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        return array_map(fn (array $match): self => new self(
            $match[1][0],
            mb_strlen(substr($text, 0, $match[0][1])),
            mb_strlen($match[0][0]),
        ), $matches);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $username = $data['handle'] ?? null;
        $offset = $data['offset'] ?? null;
        $length = $data['length'] ?? null;

        if (! is_string($username) || $username === '' || ! is_int($offset) || ! is_int($length) || $offset < 0 || $length < 2) {
            return null;
        }

        return new self($username, $offset, $length, ($data['kind'] ?? null) === 'organization');
    }
}
