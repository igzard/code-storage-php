<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

use Igzard\CodeStorage\Internal\Arr;

/** The repo state a grep ran against. */
final class GrepRepo
{
    public function __construct(
        public readonly string $ref,
        public readonly string $commit,
    ) {}

    /** @internal */
    public static function fromArray(array $data): self
    {
        return new self(Arr::str($data, 'ref'), Arr::str($data, 'commit'));
    }
}
