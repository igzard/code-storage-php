<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Exception;

use RuntimeException;

/** HTTP error returned by a non-commit endpoint. */
final class ApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly string $statusText,
        public readonly string $method,
        public readonly string $url,
        public readonly mixed $body = null,
    ) {
        parent::__construct($message);
    }
}
