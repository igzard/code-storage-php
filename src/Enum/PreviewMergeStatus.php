<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Enum;

/** Whether a merge preview is clean or conflicted. */
enum PreviewMergeStatus: string
{
    case Clean = 'clean';
    case Conflicted = 'conflicted';
}
