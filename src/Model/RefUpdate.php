<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

use Igzard\CodeStorage\Internal\Arr;

/** Ref update details reported by a write operation. */
final class RefUpdate
{
    public function __construct(
        public readonly string $branch,
        public readonly string $oldSha,
        public readonly string $newSha,
    ) {}

    /** @internal */
    public static function fromArray(array $data): self
    {
        return new self(
            Arr::str($data, 'branch'),
            Arr::str($data, 'old_sha'),
            Arr::str($data, 'new_sha'),
        );
    }

    /**
     * Builds a RefUpdate only when at least one field carries information.
     *
     * @internal
     */
    public static function partial(string $branch, string $oldSha, string $newSha): ?self
    {
        $branch = trim($branch);
        $oldSha = trim($oldSha);
        $newSha = trim($newSha);

        if ($branch === '' && $oldSha === '' && $newSha === '') {
            return null;
        }

        return new self($branch, $oldSha, $newSha);
    }
}
