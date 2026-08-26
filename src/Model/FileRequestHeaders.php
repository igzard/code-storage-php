<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

/** Range / conditional headers forwarded to /repos/file. */
final class FileRequestHeaders
{
    public function __construct(
        public readonly ?string $range = null,
        public readonly ?string $ifMatch = null,
        public readonly ?string $ifNoneMatch = null,
        public readonly ?string $ifModifiedSince = null,
        public readonly ?string $ifUnmodifiedSince = null,
        public readonly ?string $ifRange = null,
    ) {}

    /**
     * @internal
     *
     * @return array<string, string>
     */
    public function toHeaders(): array
    {
        $headers = [
            'Range' => $this->range,
            'If-Match' => $this->ifMatch,
            'If-None-Match' => $this->ifNoneMatch,
            'If-Modified-Since' => $this->ifModifiedSince,
            'If-Unmodified-Since' => $this->ifUnmodifiedSince,
            'If-Range' => $this->ifRange,
        ];

        return array_filter($headers, static fn (?string $value): bool => $value !== null && $value !== '');
    }
}
