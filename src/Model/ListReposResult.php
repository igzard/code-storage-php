<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

use Igzard\CodeStorage\Internal\Arr;

/** Paginated repository listing. */
final class ListReposResult
{
    /** @param list<RepoInfo> $repos */
    public function __construct(
        public readonly array $repos,
        public readonly string $nextCursor,
        public readonly bool $hasMore,
    ) {}

    /** @internal */
    public static function fromArray(array $data): self
    {
        return new self(
            Arr::mapList($data, 'repos', RepoInfo::fromArray(...)),
            Arr::str($data, 'next_cursor'),
            Arr::bool($data, 'has_more'),
        );
    }
}
