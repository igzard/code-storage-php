<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Enum;

/** JWT scopes supported by the API. */
enum Permission: string
{
    case GitRead = 'git:read';
    case GitWrite = 'git:write';
    case RepoWrite = 'repo:write';
    case OrgRead = 'org:read';
}
