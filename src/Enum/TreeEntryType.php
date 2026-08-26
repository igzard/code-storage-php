<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Enum;

/** Kind of object at a tree entry. */
enum TreeEntryType: string
{
    case Blob = 'blob';
    case Tree = 'tree';
    case Symlink = 'symlink';
    case Submodule = 'submodule';
}
