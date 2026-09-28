<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

use Igzard\CodeStorage\Internal\Arr;

/** Diff introduced by a commit. */
final class CommitDiffResult
{
    /**
     * @param  list<FileDiff>  $files
     * @param  list<FilteredFile>  $filteredFiles
     * @param  string  $baseSha  Resolved base commit. Can differ from $mergeBaseSha.
     * @param  string  $mergeBaseSha  Common ancestor used for the comparison. Empty when the API omits it.
     */
    public function __construct(
        public readonly string $sha,
        public readonly DiffStats $stats,
        public readonly array $files,
        public readonly array $filteredFiles,
        public readonly string $baseSha = '',
        public readonly string $mergeBaseSha = '',
    ) {}

    /** @internal */
    public static function fromArray(array $data): self
    {
        return new self(
            Arr::str($data, 'sha'),
            DiffStats::fromArray(Arr::arr($data, 'stats')),
            Arr::mapList($data, 'files', FileDiff::fromArray(...)),
            Arr::mapList($data, 'filtered_files', FilteredFile::fromArray(...)),
            Arr::str($data, 'base_sha'),
            Arr::str($data, 'merge_base_sha'),
        );
    }
}
