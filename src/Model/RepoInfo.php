<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

use Igzard\CodeStorage\Internal\Arr;

/** A repository entry in list results. */
final class RepoInfo
{
    public function __construct(
        public readonly string $repoId,
        public readonly string $url,
        public readonly string $defaultBranch,
        public readonly string $createdAt,
        public readonly ?RepoBaseInfo $baseRepo = null,
    ) {}

    /** @internal */
    public static function fromArray(array $data): self
    {
        $baseRepo = $data['base_repo'] ?? null;

        return new self(
            Arr::str($data, 'repo_id'),
            Arr::str($data, 'url'),
            Arr::str($data, 'default_branch'),
            Arr::str($data, 'created_at'),
            is_array($baseRepo) ? RepoBaseInfo::fromArray($baseRepo) : null,
        );
    }
}
