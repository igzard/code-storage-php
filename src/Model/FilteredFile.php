<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

use Igzard\CodeStorage\Enum\DiffFileState;
use Igzard\CodeStorage\Internal\Arr;

/** A diff file omitted from the response body. */
final class FilteredFile
{
    public function __construct(
        public readonly string $path,
        public readonly DiffFileState $state,
        public readonly string $rawState,
        public readonly string $oldPath,
        public readonly int $bytes,
        public readonly bool $isEof,
    ) {}

    /** @internal */
    public static function fromArray(array $data): self
    {
        $rawState = Arr::str($data, 'state');

        return new self(
            Arr::str($data, 'path'),
            DiffFileState::fromRaw($rawState),
            $rawState,
            trim(Arr::str($data, 'old_path')),
            Arr::int($data, 'bytes'),
            Arr::bool($data, 'is_eof'),
        );
    }
}
