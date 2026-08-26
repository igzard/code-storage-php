<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

use Igzard\CodeStorage\Internal\Arr;

/** The target ref update of a merge. */
final class MergeTargetRef
{
    public function __construct(
        public readonly string $branch,
        public readonly bool $ephemeral,
        public readonly string $oldSha,
        public readonly string $newSha,
    ) {}

    /** @internal */
    public static function fromArray(array $data): self
    {
        return new self(
            Arr::str($data, 'branch'),
            Arr::bool($data, 'ephemeral'),
            Arr::str($data, 'old_sha'),
            Arr::str($data, 'new_sha'),
        );
    }
}
