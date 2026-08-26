<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

use DateTimeImmutable;
use Igzard\CodeStorage\Internal\Arr;
use Igzard\CodeStorage\Internal\Time;

/** A commit entry. */
final class CommitInfo
{
    /**
     * @param  list<string>  $parentShas  Parent commit SHAs in Git parent order; empty for root commits.
     * @param  string  $signature  Armored signature from the commit's gpgsig header. Only populated
     *                             by Repo::getCommit() for signed commits.
     * @param  string  $payload  Exact bytes the signature is computed over. Only populated by
     *                           Repo::getCommit() for signed commits.
     */
    public function __construct(
        public readonly string $sha,
        public readonly array $parentShas,
        public readonly string $message,
        public readonly string $authorName,
        public readonly string $authorEmail,
        public readonly string $committerName,
        public readonly string $committerEmail,
        public readonly ?DateTimeImmutable $date,
        public readonly string $rawDate,
        public readonly string $signature = '',
        public readonly string $payload = '',
    ) {}

    /** @internal */
    public static function fromArray(array $data): self
    {
        $rawDate = Arr::str($data, 'date');
        // Both fields are present only for signed commits; treat a half-populated
        // response as unsigned.
        $signature = Arr::str($data, 'signature');
        $payload = Arr::str($data, 'payload');
        if ($signature === '' || $payload === '') {
            $signature = $payload = '';
        }

        return new self(
            Arr::str($data, 'sha'),
            Arr::strList($data, 'parent_shas'),
            Arr::str($data, 'message'),
            Arr::str($data, 'author_name'),
            Arr::str($data, 'author_email'),
            Arr::str($data, 'committer_name'),
            Arr::str($data, 'committer_email'),
            Time::parse($rawDate),
            $rawDate,
            $signature,
            $payload,
        );
    }
}
