<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

use Igzard\CodeStorage\Internal\Arr;

/** File path listing. */
final class ListFilesResult
{
    /**
     * @param  list<string>  $paths
     * @param  list<TreeEntry>  $entries
     */
    public function __construct(
        public readonly array $paths,
        public readonly string $ref,
        public readonly array $entries,
        public readonly string $nextCursor,
        public readonly bool $hasMore,
    ) {}

    /** @internal */
    public static function fromArray(array $data): self
    {
        return new self(
            Arr::strList($data, 'paths'),
            Arr::str($data, 'ref'),
            Arr::mapList($data, 'entries', TreeEntry::fromArray(...)),
            Arr::str($data, 'next_cursor'),
            Arr::bool($data, 'has_more'),
        );
    }
}
