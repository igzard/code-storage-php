<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

/** The repository a webhook event refers to. */
final class WebhookRepository
{
    public function __construct(
        public readonly string $id,
        public readonly string $url,
    ) {}
}
