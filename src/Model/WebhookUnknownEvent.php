<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

/** Fallback for event types this SDK does not model. */
final class WebhookUnknownEvent
{
    public function __construct(
        public readonly string $type,
        public readonly string $raw,
    ) {}
}
