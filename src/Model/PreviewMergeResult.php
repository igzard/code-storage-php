<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

use Igzard\CodeStorage\Enum\PreviewMergeResultStatus;
use Igzard\CodeStorage\Enum\PreviewMergeStatus;
use Igzard\CodeStorage\Internal\Arr;

/** Read-only merge preview result. */
final class PreviewMergeResult
{
    /**
     * @param  list<string>  $conflictPaths
     * @param  list<PreviewMergeConflict>  $conflicts
     * @param  list<PreviewMergeFilteredConflict>  $filteredConflicts
     */
    public function __construct(
        public readonly ?PreviewMergeStatus $status,
        public readonly ?PreviewMergeResultStatus $result,
        public readonly string $sourceBranch,
        public readonly string $targetBranch,
        public readonly string $sourceTipSha,
        public readonly string $targetTipSha,
        public readonly string $mergeBaseSha,
        public readonly array $conflictPaths,
        public readonly array $conflicts,
        public readonly array $filteredConflicts,
    ) {}

    /** @internal */
    public static function fromArray(array $data): self
    {
        return new self(
            PreviewMergeStatus::tryFrom(Arr::str($data, 'status')),
            PreviewMergeResultStatus::tryFrom(Arr::str($data, 'result')),
            Arr::str($data, 'source_branch'),
            Arr::str($data, 'target_branch'),
            Arr::str($data, 'source_tip_sha'),
            Arr::str($data, 'target_tip_sha'),
            Arr::str($data, 'merge_base_sha'),
            Arr::strList($data, 'conflict_paths'),
            Arr::mapList($data, 'conflicts', PreviewMergeConflict::fromArray(...)),
            Arr::mapList($data, 'filtered_conflicts', PreviewMergeFilteredConflict::fromArray(...)),
        );
    }
}
