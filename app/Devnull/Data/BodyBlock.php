<?php

namespace App\Devnull\Data;

/**
 * A block of a commit body: a paragraph of runs, a quote (lines that start
 * with `>`, the marker dropped), or a fenced code block.
 */
final readonly class BodyBlock
{
    /**
     * @param  list<BodyRun>  $runs
     */
    private function __construct(public array $runs = [], public ?string $code = null, public ?string $language = null, public bool $quoted = false) {}

    /**
     * @param  list<BodyRun>  $runs
     */
    public static function paragraph(array $runs): self
    {
        return new self(runs: $runs);
    }

    /**
     * @param  list<BodyRun>  $runs
     */
    public static function quote(array $runs): self
    {
        return new self(runs: $runs, quoted: true);
    }

    public static function code(string $code, ?string $language = null): self
    {
        return new self(code: $code, language: $language);
    }

    public function isCode(): bool
    {
        return $this->code !== null;
    }

    public function isQuote(): bool
    {
        return $this->quoted;
    }
}
