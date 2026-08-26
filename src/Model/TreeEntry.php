<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

use Igzard\CodeStorage\Enum\TreeEntryType;
use Igzard\CodeStorage\Internal\Arr;

/** A structured entry returned by Repo::listFiles(). */
final class TreeEntry
{
    public function __construct(
        public readonly string $path,
        public readonly ?TreeEntryType $type,
        public readonly string $mode,
    ) {}

    /** @internal */
    public static function fromArray(array $data): self
    {
        return new self(
            Arr::str($data, 'path'),
            TreeEntryType::tryFrom(Arr::str($data, 'type')),
            Arr::str($data, 'mode'),
        );
    }
}
