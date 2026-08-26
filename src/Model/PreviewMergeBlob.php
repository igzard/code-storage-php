<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

use Igzard\CodeStorage\Internal\Arr;

/** A conflict stage blob in a merge preview. */
final class PreviewMergeBlob
{
    public function __construct(
        public readonly string $oid,
        public readonly string $content,
        public readonly bool $truncated,
        public readonly bool $binary,
    ) {}

    /** @internal */
    public static function fromArray(array $data): self
    {
        return new self(
            Arr::str($data, 'oid'),
            Arr::str($data, 'content'),
            Arr::bool($data, 'truncated'),
            Arr::bool($data, 'binary'),
        );
    }
}
