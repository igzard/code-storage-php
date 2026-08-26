<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

use Igzard\CodeStorage\Internal\Arr;

/** File listing with per-file metadata and the commits they reference. */
final class ListFilesWithMetadataResult
{
    /**
     * @param  list<FileWithMetadata>  $files
     * @param  array<string, CommitMetadata>  $commits  keyed by commit SHA
     */
    public function __construct(
        public readonly array $files,
        public readonly array $commits,
        public readonly string $ref,
        public readonly string $nextCursor,
        public readonly bool $hasMore,
    ) {}

    /** @internal */
    public static function fromArray(array $data): self
    {
        $commits = [];
        foreach (Arr::arr($data, 'commits') as $sha => $commit) {
            if (is_string($sha) && is_array($commit)) {
                $commits[$sha] = CommitMetadata::fromArray($commit);
            }
        }

        return new self(
            Arr::mapList($data, 'files', FileWithMetadata::fromArray(...)),
            $commits,
            Arr::str($data, 'ref'),
            Arr::str($data, 'next_cursor'),
            Arr::bool($data, 'has_more'),
        );
    }
}
