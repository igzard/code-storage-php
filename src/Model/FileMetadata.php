<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

use DateTimeImmutable;
use Igzard\CodeStorage\Internal\Time;
use Psr\Http\Message\ResponseInterface;

/** Parsed result of a HEAD /repos/file request. */
final class FileMetadata
{
    public function __construct(
        public readonly int $statusCode,
        public readonly string $blobSha,
        public readonly string $lastCommitSha,
        public readonly int $size,
        public readonly string $etag,
        public readonly ?DateTimeImmutable $lastModified,
        public readonly string $rawLastModified,
        public readonly string $acceptRanges,
        public readonly string $contentRange,
        public readonly string $contentType,
    ) {}

    /** @internal */
    public static function fromResponse(ResponseInterface $response): self
    {
        $header = static fn (string $name): string => $response->getHeaderLine($name);
        $rawLastModified = $header('Last-Modified');
        $contentLength = $header('Content-Length');

        return new self(
            $response->getStatusCode(),
            $header('X-Blob-Sha'),
            $header('X-Last-Commit-Sha'),
            is_numeric($contentLength) ? (int) $contentLength : 0,
            $header('ETag'),
            Time::parseHttpDate($rawLastModified),
            $rawLastModified,
            $header('Accept-Ranges'),
            $header('Content-Range'),
            $header('Content-Type'),
        );
    }
}
