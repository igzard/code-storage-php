<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

use Igzard\CodeStorage\Internal\Arr;

/** Tag creation result. */
final class CreateTagResult
{
    public function __construct(
        public readonly string $name,
        public readonly string $sha,
        public readonly string $message,
    ) {}

    /** @internal */
    public static function fromArray(array $data): self
    {
        return new self(
            Arr::str($data, 'name'),
            Arr::str($data, 'sha'),
            Arr::str($data, 'message'),
        );
    }
}
