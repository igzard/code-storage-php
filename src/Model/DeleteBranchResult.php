<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

use Igzard\CodeStorage\Internal\Arr;

/** Branch deletion result. */
final class DeleteBranchResult
{
    public function __construct(
        public readonly string $name,
        public readonly string $message,
        public readonly bool $ephemeral,
    ) {}

    /** @internal */
    public static function fromArray(array $data): self
    {
        return new self(
            Arr::str($data, 'name'),
            Arr::str($data, 'message'),
            Arr::bool($data, 'ephemeral'),
        );
    }
}
