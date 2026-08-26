<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

use Igzard\CodeStorage\Internal\Arr;

/** A conflict whose inline content was omitted. */
final class PreviewMergeFilteredConflict
{
    public function __construct(
        public readonly string $path,
        public readonly string $reason,
    ) {}

    /** @internal */
    public static function fromArray(array $data): self
    {
        return new self(Arr::str($data, 'path'), Arr::str($data, 'reason'));
    }
}
