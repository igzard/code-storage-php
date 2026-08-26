<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

/** Result of a commit-pack or diff-commit write. */
final class CommitResult
{
    public function __construct(
        public readonly string $commitSha,
        public readonly string $treeSha,
        public readonly string $targetBranch,
        public readonly int $packBytes,
        public readonly int $blobCount,
        public readonly RefUpdate $refUpdate,
    ) {}
}
