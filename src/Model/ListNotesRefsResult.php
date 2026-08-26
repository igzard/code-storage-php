<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

use Igzard\CodeStorage\Internal\Arr;

/** Paginated notes ref listing. */
final class ListNotesRefsResult
{
    /**
     * @param  list<NotesRefInfo>  $refs
     * @param  string  $prefix  The normalized notes ref prefix used for the listing.
     */
    public function __construct(
        public readonly array $refs,
        public readonly string $nextCursor,
        public readonly bool $hasMore,
        public readonly string $prefix,
    ) {}

    /** @internal */
    public static function fromArray(array $data): self
    {
        return new self(
            Arr::mapList($data, 'refs', NotesRefInfo::fromArray(...)),
            Arr::str($data, 'next_cursor'),
            Arr::bool($data, 'has_more'),
            Arr::str($data, 'prefix'),
        );
    }
}
