<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

use Igzard\CodeStorage\Internal\Arr;

/** Matches within a single file. */
final class GrepFileMatch
{
    /** @param list<GrepLine> $lines */
    public function __construct(
        public readonly string $path,
        public readonly array $lines,
    ) {}

    /** @internal */
    public static function fromArray(array $data): self
    {
        return new self(
            Arr::str($data, 'path'),
            Arr::mapList($data, 'lines', GrepLine::fromArray(...)),
        );
    }
}
