<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

use Igzard\CodeStorage\Enum\MergeResultStatus;
use Igzard\CodeStorage\Internal\Arr;

/** Result of a branch merge. */
final class MergeResult
{
    public function __construct(
        public readonly MergeResultStatus $result,
        public readonly string $commitSha,
        public readonly string $treeSha,
        public readonly MergeRef $source,
        public readonly MergeTargetRef $target,
        public readonly string $mergeBaseSha,
        public readonly int $promotedCommits,
    ) {}

    /** @internal */
    public static function fromArray(array $data): self
    {
        return new self(
            MergeResultStatus::fromRaw(Arr::str($data, 'result')),
            Arr::str($data, 'commit_sha'),
            Arr::str($data, 'tree_sha'),
            MergeRef::fromArray(Arr::arr($data, 'source')),
            MergeTargetRef::fromArray(Arr::arr($data, 'target')),
            Arr::str($data, 'merge_base_sha'),
            Arr::int($data, 'promoted_commits'),
        );
    }
}
