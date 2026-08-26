<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Enum;

/** Git file mode for committed blobs. */
enum GitFileMode: string
{
    case Regular = '100644';
    case Executable = '100755';
    case Symlink = '120000';
    case Submodule = '160000';
}
