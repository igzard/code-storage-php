<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

use DateTimeImmutable;
use Igzard\CodeStorage\Internal\Arr;
use Igzard\CodeStorage\Internal\Time;

/** Blame attribution for a single line in a file. */
final class BlameLine
{
    public function __construct(
        public readonly int $lineNumber,
        public readonly string $commitSha,
        public readonly int $originalLineNumber,
        public readonly string $originalPath,
        public readonly string $previousCommitSha,
        public readonly string $authorName,
        public readonly string $authorEmail,
        public readonly ?DateTimeImmutable $authorTime,
        public readonly string $rawAuthorTime,
        public readonly string $committerName,
        public readonly string $committerEmail,
        public readonly ?DateTimeImmutable $committerTime,
        public readonly string $rawCommitterTime,
        public readonly string $summary,
    ) {}

    /** @internal */
    public static function fromArray(array $data): self
    {
        $rawAuthorTime = Arr::str($data, 'author_time');
        $rawCommitterTime = Arr::str($data, 'committer_time');

        return new self(
            Arr::int($data, 'line_number'),
            Arr::str($data, 'commit_sha'),
            Arr::int($data, 'original_line_number'),
            Arr::str($data, 'original_path'),
            Arr::str($data, 'previous_commit_sha'),
            Arr::str($data, 'author_name'),
            Arr::str($data, 'author_email'),
            Time::parse($rawAuthorTime),
            $rawAuthorTime,
            Arr::str($data, 'committer_name'),
            Arr::str($data, 'committer_email'),
            Time::parse($rawCommitterTime),
            $rawCommitterTime,
            Arr::str($data, 'summary'),
        );
    }
}
