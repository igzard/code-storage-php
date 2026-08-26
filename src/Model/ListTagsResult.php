<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

use Igzard\CodeStorage\Internal\Arr;

/** Paginated tag listing. */
final class ListTagsResult
{
    /** @param list<TagInfo> $tags */
    public function __construct(
        public readonly array $tags,
        public readonly string $nextCursor,
        public readonly bool $hasMore,
    ) {}

    /** @internal */
    public static function fromArray(array $data): self
    {
        return new self(
            Arr::mapList($data, 'tags', TagInfo::fromArray(...)),
            Arr::str($data, 'next_cursor'),
            Arr::bool($data, 'has_more'),
        );
    }
}
