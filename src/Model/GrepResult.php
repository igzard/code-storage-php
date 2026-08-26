<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

use Igzard\CodeStorage\Internal\Arr;

/** Paginated grep results. */
final class GrepResult
{
    /** @param list<GrepFileMatch> $matches */
    public function __construct(
        public readonly GrepQuery $query,
        public readonly GrepRepo $repo,
        public readonly array $matches,
        public readonly string $nextCursor,
        public readonly bool $hasMore,
    ) {}

    /** @internal */
    public static function fromArray(array $data): self
    {
        return new self(
            GrepQuery::fromArray(Arr::arr($data, 'query')),
            GrepRepo::fromArray(Arr::arr($data, 'repo')),
            Arr::mapList($data, 'matches', GrepFileMatch::fromArray(...)),
            Arr::str($data, 'next_cursor'),
            Arr::bool($data, 'has_more'),
        );
    }
}
