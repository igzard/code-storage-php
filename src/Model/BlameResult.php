<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

use Igzard\CodeStorage\Internal\Arr;

/** Per-line authorship for a file at a ref. */
final class BlameResult
{
    /**
     * @param  string  $commitSha  The SHA the requested ref resolved to.
     * @param  list<BlameLine>  $lines
     */
    public function __construct(
        public readonly string $ref,
        public readonly string $path,
        public readonly string $commitSha,
        public readonly array $lines,
    ) {}

    /** @internal */
    public static function fromArray(array $data): self
    {
        return new self(
            Arr::str($data, 'ref'),
            Arr::str($data, 'path'),
            Arr::str($data, 'commit_sha'),
            Arr::mapList($data, 'lines', BlameLine::fromArray(...)),
        );
    }
}
