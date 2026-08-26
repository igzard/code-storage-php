<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

use Igzard\CodeStorage\Internal\Arr;

/** Result of a git note create/append/delete. */
final class NoteWriteResult
{
    /**
     * @param  string  $targetRef  The notes ref the operation targeted (defaults to refs/notes/commits).
     */
    public function __construct(
        public readonly string $sha,
        public readonly string $targetRef,
        public readonly string $baseCommit,
        public readonly string $newRefSha,
        public readonly NoteResult $result,
    ) {}

    /** @internal */
    public static function fromArray(array $data): self
    {
        return new self(
            Arr::str($data, 'sha'),
            Arr::str($data, 'target_ref'),
            Arr::str($data, 'base_commit'),
            Arr::str($data, 'new_ref_sha'),
            NoteResult::fromArray(Arr::arr($data, 'result')),
        );
    }
}
