<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

use Igzard\CodeStorage\Internal\Arr;

/** The source ref of a merge. */
final class MergeRef
{
    public function __construct(
        public readonly string $branch,
        public readonly bool $ephemeral,
        public readonly string $sha,
    ) {}

    /** @internal */
    public static function fromArray(array $data): self
    {
        return new self(
            Arr::str($data, 'branch'),
            Arr::bool($data, 'ephemeral'),
            Arr::str($data, 'sha'),
        );
    }
}
