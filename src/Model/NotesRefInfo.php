<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

use Igzard\CodeStorage\Internal\Arr;

/** A single notes ref entry. */
final class NotesRefInfo
{
    public function __construct(
        public readonly string $cursor,
        public readonly string $ref,
        public readonly string $sha,
    ) {}

    /** @internal */
    public static function fromArray(array $data): self
    {
        return new self(
            Arr::str($data, 'cursor'),
            Arr::str($data, 'ref'),
            Arr::str($data, 'sha'),
        );
    }
}
