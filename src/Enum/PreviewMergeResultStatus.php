<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Enum;

/** Result if the previewed merge were applied. */
enum PreviewMergeResultStatus: string
{
    case MergeCommit = 'merge_commit';
    case FastForward = 'fast_forward';
    case NoOp = 'no_op';
}
