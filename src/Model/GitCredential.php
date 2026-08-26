<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

use Igzard\CodeStorage\Internal\Arr;

/** A generic git credential. */
final class GitCredential
{
    public function __construct(
        public readonly string $id,
        public readonly string $createdAt = '',
    ) {}

    /** @internal */
    public static function fromArray(array $data): self
    {
        return new self(Arr::str($data, 'id'), Arr::str($data, 'created_at'));
    }
}
