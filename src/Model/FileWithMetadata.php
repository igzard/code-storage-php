<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

use Igzard\CodeStorage\Enum\TreeEntryType;
use Igzard\CodeStorage\Internal\Arr;

/** A file entry with mode, size and last commit metadata. */
final class FileWithMetadata
{
    public function __construct(
        public readonly string $path,
        public readonly string $mode,
        public readonly int $size,
        public readonly string $lastCommitSha,
        public readonly ?TreeEntryType $type,
    ) {}

    /** @internal */
    public static function fromArray(array $data): self
    {
        return new self(
            Arr::str($data, 'path'),
            Arr::str($data, 'mode'),
            Arr::int($data, 'size'),
            Arr::str($data, 'last_commit_sha'),
            TreeEntryType::tryFrom(Arr::str($data, 'type')),
        );
    }
}
