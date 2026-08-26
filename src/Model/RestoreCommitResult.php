<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

/** Result of a restore-commit write. */
final class RestoreCommitResult
{
    public function __construct(
        public readonly string $commitSha,
        public readonly string $treeSha,
        public readonly string $targetBranch,
        public readonly int $packBytes,
        public readonly RefUpdate $refUpdate,
    ) {}
}
