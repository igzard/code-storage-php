<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Enum;

/** Policy operation included in the JWT. */
enum Op: string
{
    case NoForcePush = 'no-force-push';
    case NoPush = 'no-push';
    /**
     * Requires every commit introduced by a push to a matching ref to carry a
     * valid signature from a registered signing key.
     */
    case VerifySig = 'verify-sig';
}
