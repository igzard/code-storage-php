<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

use Igzard\CodeStorage\Internal\Arr;

/** A grep line match or context line. */
final class GrepLine
{
    public function __construct(
        public readonly int $lineNumber,
        public readonly string $text,
        public readonly string $type,
    ) {}

    /** @internal */
    public static function fromArray(array $data): self
    {
        return new self(
            Arr::int($data, 'line_number'),
            Arr::str($data, 'text'),
            Arr::str($data, 'type'),
        );
    }
}
