<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

use Igzard\CodeStorage\Enum\DiffFileState;
use Igzard\CodeStorage\Internal\Arr;

/** A diffed file. */
final class FileDiff
{
    public function __construct(
        public readonly string $path,
        public readonly DiffFileState $state,
        public readonly string $rawState,
        public readonly string $oldPath,
        public readonly string $raw,
        public readonly int $bytes,
        public readonly bool $isEof,
        public readonly int $additions,
        public readonly int $deletions,
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
            Arr::str($data, 'raw'),
            Arr::int($data, 'bytes'),
            Arr::bool($data, 'is_eof'),
            Arr::int($data, 'additions'),
            Arr::int($data, 'deletions'),
        );
    }
}
