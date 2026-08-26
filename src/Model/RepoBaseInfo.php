<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

use Igzard\CodeStorage\Internal\Arr;

/** Base repo reference on list results. */
final class RepoBaseInfo
{
    public function __construct(
        public readonly string $provider,
        public readonly string $owner,
        public readonly string $name,
    ) {}

    /** @internal */
    public static function fromArray(array $data): self
    {
        return new self(
            Arr::str($data, 'provider'),
            Arr::str($data, 'owner'),
            Arr::str($data, 'name'),
        );
    }
}
