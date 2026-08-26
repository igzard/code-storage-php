<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

use Igzard\CodeStorage\Internal\Arr;

/** Inline conflict content for a path. */
final class PreviewMergeConflict
{
    public function __construct(
        public readonly string $path,
        public readonly PreviewMergeBlob $result,
        public readonly PreviewMergeBlob $base,
        public readonly PreviewMergeBlob $ours,
        public readonly PreviewMergeBlob $theirs,
    ) {}

    /** @internal */
    public static function fromArray(array $data): self
    {
        return new self(
            Arr::str($data, 'path'),
            PreviewMergeBlob::fromArray(Arr::arr($data, 'result')),
            PreviewMergeBlob::fromArray(Arr::arr($data, 'base')),
            PreviewMergeBlob::fromArray(Arr::arr($data, 'ours')),
            PreviewMergeBlob::fromArray(Arr::arr($data, 'theirs')),
        );
    }
}
