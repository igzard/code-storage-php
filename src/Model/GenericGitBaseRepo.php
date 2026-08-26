<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

use Igzard\CodeStorage\Enum\RepoProvider;

/** References a repository on a generic git host (GitLab, Bitbucket, Gitea, ...). */
final class GenericGitBaseRepo implements BaseRepo
{
    /**
     * @param  string|null  $upstreamHost  Bare hostname for self-hosted instances
     *                                     (e.g. "gitlab.example.com"). Falls back to the provider default.
     */
    public function __construct(
        public readonly RepoProvider $provider,
        public readonly string $owner,
        public readonly string $name,
        public readonly ?string $defaultBranch = null,
        public readonly ?string $upstreamHost = null,
    ) {}
}
