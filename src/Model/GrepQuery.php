<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

use Igzard\CodeStorage\Internal\Arr;

/** The query a grep result was produced from. */
final class GrepQuery
{
    public function __construct(
        public readonly string $pattern,
        public readonly bool $caseSensitive,
    ) {}

    /** @internal */
    public static function fromArray(array $data): self
    {
        return new self(Arr::str($data, 'pattern'), Arr::bool($data, 'case_sensitive'));
    }
}
