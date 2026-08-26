<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Internal;

use DateTimeImmutable;
use Throwable;

/** @internal */
final class Time
{
    /** Parses an RFC 3339 timestamp, returning null when absent or malformed. */
    public static function parse(string $value): ?DateTimeImmutable
    {
        if (trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }

    /** Parses an HTTP-date (RFC 7231) header value. */
    public static function parseHttpDate(string $value): ?DateTimeImmutable
    {
        if (trim($value) === '') {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat(DATE_RFC7231, $value);

        return $parsed === false ? self::parse($value) : $parsed;
    }
}
