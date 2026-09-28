<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Internal;

use DateTimeImmutable;
use DateTimeZone;
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

    /** Parses an HTTP-date (RFC 7231 IMF-fixdate). The timestamp is always GMT. */
    public static function parseHttpDate(string $value): ?DateTimeImmutable
    {
        if (trim($value) === '') {
            return null;
        }

        // The format literal is GMT, not a timezone token, so the zone has to be
        // passed explicitly. Otherwise the clock time is read in the default zone.
        $parsed = DateTimeImmutable::createFromFormat(
            'D, d M Y H:i:s \G\M\T',
            $value,
            new DateTimeZone('GMT'),
        );

        return $parsed === false ? self::parse($value) : $parsed;
    }
}
