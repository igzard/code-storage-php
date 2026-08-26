<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Internal;

use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Builds the NDJSON request bodies the commit-pack and diff-commit endpoints consume.
 *
 * @internal
 */
final class Ndjson
{
    public const MAX_CHUNK_BYTES = 4 * 1024 * 1024;

    /** @param resource $out */
    public static function writeLine($out, array $payload): void
    {
        if (fwrite($out, Json::encode($payload)."\n") === false) {
            throw new RuntimeException('failed to write ndjson payload');
        }
    }

    /**
     * Streams a source as base64 chunks. The final chunk always carries eof=true,
     * so an empty source still emits exactly one chunk.
     *
     * @param  resource  $out
     * @param  string|resource|StreamInterface  $source
     * @param  string|null  $contentId  Blob content id, or null to emit diff chunks.
     */
    public static function writeChunks($out, mixed $source, ?string $contentId): void
    {
        $read = self::reader($source);
        $pending = null;

        while (true) {
            $data = $read();
            if ($data === null) {
                break;
            }
            if ($data === '') {
                continue;
            }
            if ($pending !== null) {
                self::writeLine($out, self::chunk($contentId, $pending, false));
            }
            $pending = $data;
        }

        self::writeLine($out, self::chunk($contentId, $pending ?? '', true));
    }

    /** @return array<string, array<string, mixed>> */
    private static function chunk(?string $contentId, string $data, bool $eof): array
    {
        $encoded = $data === '' ? '' : base64_encode($data);

        if ($contentId === null) {
            return ['diff_chunk' => ['data' => $encoded, 'eof' => $eof]];
        }

        return ['blob_chunk' => ['content_id' => $contentId, 'data' => $encoded, 'eof' => $eof]];
    }

    /**
     * @param  string|resource|StreamInterface  $source
     * @return callable(): ?string  Returns the next chunk, or null when exhausted.
     */
    private static function reader(mixed $source): callable
    {
        if (is_string($source)) {
            $offset = 0;

            return static function () use ($source, &$offset): ?string {
                if ($offset >= strlen($source)) {
                    return null;
                }
                $chunk = substr($source, $offset, self::MAX_CHUNK_BYTES);
                $offset += strlen($chunk);

                return $chunk;
            };
        }

        if ($source instanceof StreamInterface) {
            return static function () use ($source): ?string {
                if ($source->eof()) {
                    return null;
                }

                return $source->read(self::MAX_CHUNK_BYTES);
            };
        }

        if (is_resource($source)) {
            return static function () use ($source): ?string {
                if (feof($source)) {
                    return null;
                }
                $data = fread($source, self::MAX_CHUNK_BYTES);

                return $data === false ? null : $data;
            };
        }

        throw new InvalidArgumentException('unsupported content source; expected a string, stream resource or StreamInterface');
    }
}
