<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Enum;

/** Ref update failure reason. */
enum RefUpdateReason: string
{
    case PreconditionFailed = 'precondition_failed';
    case Conflict = 'conflict';
    case NotFound = 'not_found';
    case Invalid = 'invalid';
    case Timeout = 'timeout';
    case Unauthorized = 'unauthorized';
    case Forbidden = 'forbidden';
    case Unavailable = 'unavailable';
    case Internal = 'internal';
    case Failed = 'failed';
    case Unknown = 'unknown';

    public static function fromStatus(string $status): self
    {
        $normalized = strtolower(trim($status));
        if ($normalized === '' || $normalized === 'ok') {
            return self::Unknown;
        }

        return self::tryFrom($normalized) ?? self::Unknown;
    }
}
