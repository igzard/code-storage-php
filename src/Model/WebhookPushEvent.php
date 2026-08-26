<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

use DateTimeImmutable;

/** A validated push webhook. */
final class WebhookPushEvent
{
    public function __construct(
        public readonly WebhookRepository $repository,
        public readonly string $ref,
        public readonly string $before,
        public readonly string $after,
        public readonly string $customerId,
        public readonly ?DateTimeImmutable $pushedAt,
        public readonly string $rawPushedAt,
        public readonly string $type = 'push',
    ) {}
}
