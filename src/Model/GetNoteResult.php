<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

use Igzard\CodeStorage\Internal\Arr;

/** A git note read. */
final class GetNoteResult
{
    public function __construct(
        public readonly string $sha,
        public readonly string $note,
        public readonly string $refSha,
    ) {}

    /** @internal */
    public static function fromArray(array $data): self
    {
        return new self(
            Arr::str($data, 'sha'),
            Arr::str($data, 'note'),
            Arr::str($data, 'ref_sha'),
        );
    }
}
