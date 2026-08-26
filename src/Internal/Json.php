<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Internal;

use JsonException;

/** @internal */
final class Json
{
    /** Encodes without escaping slashes or unicode, matching the other SDKs. */
    public static function encode(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /** Decodes a JSON object, returning null when the payload is not one. */
    public static function decode(string $raw): ?array
    {
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
