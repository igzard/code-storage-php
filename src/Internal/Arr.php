<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Internal;

/**
 * Defensive accessors for decoded JSON payloads: the API may omit any field.
 *
 * @internal
 */
final class Arr
{
    public static function str(array $data, string $key, string $default = ''): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    public static function int(array $data, string $key, int $default = 0): int
    {
        $value = $data[$key] ?? null;

        return is_int($value) || is_float($value) ? (int) $value : $default;
    }

    public static function bool(array $data, string $key, bool $default = false): bool
    {
        $value = $data[$key] ?? null;

        return is_bool($value) ? $value : $default;
    }

    /** @return array<mixed> */
    public static function arr(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        return is_array($value) ? $value : [];
    }

    /** @return list<string> */
    public static function strList(array $data, string $key): array
    {
        $out = [];
        foreach (self::arr($data, $key) as $value) {
            if (is_string($value)) {
                $out[] = $value;
            }
        }

        return $out;
    }

    /**
     * @param  callable(array<mixed>): T  $factory
     * @return list<T>
     *
     * @template T
     */
    public static function mapList(array $data, string $key, callable $factory): array
    {
        $out = [];
        foreach (self::arr($data, $key) as $entry) {
            if (is_array($entry)) {
                $out[] = $factory($entry);
            }
        }

        return $out;
    }
}
