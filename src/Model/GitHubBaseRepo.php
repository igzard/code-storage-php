<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

use Igzard\CodeStorage\Enum\GitHubAuthType;
use Igzard\CodeStorage\Enum\RepoProvider;

/** References a GitHub repository. */
final class GitHubBaseRepo implements BaseRepo
{
    public function __construct(
        public readonly string $owner,
        public readonly string $name,
        public readonly ?string $defaultBranch = null,
        public readonly ?GitHubAuthType $authType = null,
        public readonly RepoProvider $provider = RepoProvider::GitHub,
    ) {}
}
