<?php

declare(strict_types=1);

namespace Igzard\CodeStorage;

/** Package identity, sent as the Code-Storage-Agent header. */
final class Version
{
    public const NAME = 'code-storage-php-sdk';

    public const VERSION = '1.16.2';

    public static function userAgent(): string
    {
        return self::NAME.'/'.self::VERSION;
    }
}
