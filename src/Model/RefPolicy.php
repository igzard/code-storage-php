<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

use Igzard\CodeStorage\Enum\Op;

/** A single ordered ref-matching policy rule (first match wins). */
final class RefPolicy
{
    /** @param list<Op> $ops */
    public function __construct(
        public readonly string $pattern,
        public readonly array $ops = [],
    ) {}

    /**
     * @internal
     *
     * @return array{0: string, 1: list<string>}
     */
    public function toClaim(): array
    {
        return [$this->pattern, array_map(static fn (Op $op): string => $op->value, $this->ops)];
    }
}
