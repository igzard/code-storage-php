<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

use Igzard\CodeStorage\Internal\Arr;

/** Aggregate diff stats. */
final class DiffStats
{
    public function __construct(
        public readonly int $files,
        public readonly int $additions,
        public readonly int $deletions,
        public readonly int $changes,
    ) {}

    /** @internal */
    public static function fromArray(array $data): self
    {
        return new self(
            Arr::int($data, 'files'),
            Arr::int($data, 'additions'),
            Arr::int($data, 'deletions'),
            Arr::int($data, 'changes'),
        );
    }
}
