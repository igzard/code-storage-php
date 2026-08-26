<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Enum;

/** How Repo::merge() reconciles source into target. */
enum MergeStrategy: string
{
    case Merge = 'merge';
    case FfOnly = 'ff_only';
    case FfPrefer = 'ff_prefer';
}
