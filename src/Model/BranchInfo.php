<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

use Igzard\CodeStorage\Internal\Arr;

/** A branch entry. */
final class BranchInfo
{
    public function __construct(
        public readonly string $cursor,
        public readonly string $name,
        public readonly string $headSha,
        public readonly string $createdAt,
    ) {}

    /** @internal */
    public static function fromArray(array $data): self
    {
        return new self(
            Arr::str($data, 'cursor'),
            Arr::str($data, 'name'),
            Arr::str($data, 'head_sha'),
            Arr::str($data, 'created_at'),
        );
    }
}
