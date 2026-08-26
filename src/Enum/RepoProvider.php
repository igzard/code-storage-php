<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Enum;

/** Supported base repo providers. */
enum RepoProvider: string
{
    case GitHub = 'github';
    case GitLab = 'gitlab';
    case Bitbucket = 'bitbucket';
    case Gitea = 'gitea';
    case Forgejo = 'forgejo';
    case Codeberg = 'codeberg';
    case SourceHut = 'sr.ht';
}
