<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

use Igzard\CodeStorage\Internal\Arr;

/** Paginated commit listing. */
final class ListCommitsResult
{
    /** @param list<CommitInfo> $commits */
    public function __construct(
        public readonly array $commits,
        public readonly string $nextCursor,
        public readonly bool $hasMore,
    ) {}

    /** @internal */
    public static function fromArray(array $data): self
    {
        return new self(
            Arr::mapList($data, 'commits', CommitInfo::fromArray(...)),
            Arr::str($data, 'next_cursor'),
            Arr::bool($data, 'has_more'),
        );
    }
}
