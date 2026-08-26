<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Enum;

/** Normalized diff status. */
enum DiffFileState: string
{
    case Added = 'added';
    case Modified = 'modified';
    case Deleted = 'deleted';
    case Renamed = 'renamed';
    case Copied = 'copied';
    case TypeChanged = 'type_changed';
    case Unmerged = 'unmerged';
    case Unknown = 'unknown';

    /** Normalizes a raw git status letter ("A", "R100", "m", ...). */
    public static function fromRaw(string $raw): self
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return self::Unknown;
        }

        return match (strtoupper($trimmed[0])) {
            'A' => self::Added,
            'M' => self::Modified,
            'D' => self::Deleted,
            'R' => self::Renamed,
            'C' => self::Copied,
            'T' => self::TypeChanged,
            'U' => self::Unmerged,
            default => self::Unknown,
        };
    }
}
