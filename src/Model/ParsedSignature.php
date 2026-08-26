<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

/** A parsed X-Pierre-Signature header. */
final class ParsedSignature
{
    public function __construct(
        public readonly string $timestamp,
        public readonly string $signature,
    ) {}
}
