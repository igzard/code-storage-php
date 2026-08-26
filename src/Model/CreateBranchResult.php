<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

use Igzard\CodeStorage\Internal\Arr;

/** Branch creation result. */
final class CreateBranchResult
{
    public function __construct(
        public readonly string $message,
        public readonly string $targetBranch,
        public readonly bool $targetIsEphemeral,
        public readonly string $commitSha,
    ) {}

    /** @internal */
    public static function fromArray(array $data): self
    {
        return new self(
            Arr::str($data, 'message'),
            Arr::str($data, 'target_branch'),
            Arr::bool($data, 'target_is_ephemeral'),
            Arr::str($data, 'commit_sha'),
        );
    }
}
