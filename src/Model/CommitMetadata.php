<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

use DateTimeImmutable;
use Igzard\CodeStorage\Internal\Arr;
use Igzard\CodeStorage\Internal\Time;

/** Commit metadata referenced by a files-metadata listing. */
final class CommitMetadata
{
    public function __construct(
        public readonly string $author,
        public readonly ?DateTimeImmutable $date,
        public readonly string $rawDate,
        public readonly string $message,
    ) {}

    /** @internal */
    public static function fromArray(array $data): self
    {
        $rawDate = Arr::str($data, 'date');

        return new self(
            Arr::str($data, 'author'),
            Time::parse($rawDate),
            $rawDate,
            Arr::str($data, 'message'),
        );
    }
}
