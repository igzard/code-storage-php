<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

/** Signature validation outcome, with the parsed payload when available. */
final class WebhookValidation
{
    public function __construct(
        public readonly bool $valid,
        public readonly string $error = '',
        public readonly ?int $timestamp = null,
        public readonly string $eventType = '',
        public readonly WebhookPushEvent|WebhookUnknownEvent|null $payload = null,
    ) {}

    /** @internal */
    public static function invalid(string $error, ?int $timestamp = null): self
    {
        return new self(false, $error, $timestamp);
    }

    /** @internal */
    public function withFailure(string $error): self
    {
        return new self(false, $error, $this->timestamp, $this->eventType);
    }

    /** @internal */
    public function withPayload(string $eventType, WebhookPushEvent|WebhookUnknownEvent $payload): self
    {
        return new self(true, '', $this->timestamp, $eventType, $payload);
    }
}
