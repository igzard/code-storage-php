<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

use Igzard\CodeStorage\Internal\Arr;

/** Status of a git note write. */
final class NoteResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $status,
        public readonly string $message,
    ) {}

    /** @internal */
    public static function fromArray(array $data): self
    {
        return new self(
            Arr::bool($data, 'success'),
            Arr::str($data, 'status'),
            Arr::str($data, 'message'),
        );
    }
}
