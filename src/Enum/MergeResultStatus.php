<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Enum;

/** Merge operation outcome. */
enum MergeResultStatus: string
{
    case MergeCommit = 'merge_commit';
    case FastForward = 'fast_forward';
    case NoOp = 'no_op';
    case Squash = 'squash';
    case Unknown = 'unknown';

    public static function fromRaw(string $raw): self
    {
        return self::tryFrom(trim($raw)) ?? self::Unknown;
    }
}
