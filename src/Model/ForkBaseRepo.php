<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

/** References an existing Pierre repository to fork. */
final class ForkBaseRepo implements BaseRepo
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $ref = null,
        public readonly ?string $sha = null,
    ) {}
}
