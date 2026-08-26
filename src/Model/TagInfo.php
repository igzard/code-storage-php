<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

use Igzard\CodeStorage\Internal\Arr;

/** A tag entry. */
final class TagInfo
{
    public function __construct(
        public readonly string $cursor,
        public readonly string $name,
        public readonly string $sha,
    ) {}

    /** @internal */
    public static function fromArray(array $data): self
    {
        return new self(
            Arr::str($data, 'cursor'),
            Arr::str($data, 'name'),
            Arr::str($data, 'sha'),
        );
    }
}
