<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

use Igzard\CodeStorage\Internal\Arr;

/** Repository deletion result. */
final class DeleteRepoResult
{
    public function __construct(
        public readonly string $repoId,
        public readonly string $message,
    ) {}

    /** @internal */
    public static function fromArray(array $data): self
    {
        return new self(Arr::str($data, 'repo_id'), Arr::str($data, 'message'));
    }
}
