<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Exception;

use Igzard\CodeStorage\Enum\RefUpdateReason;
use Igzard\CodeStorage\Model\RefUpdate;
use RuntimeException;

/** A ref update (commit, note write, restore) the server refused. */
final class RefUpdateException extends RuntimeException
{
    public readonly RefUpdateReason $reason;

    public function __construct(
        string $message,
        public readonly string $status,
        public readonly ?RefUpdate $refUpdate = null,
    ) {
        parent::__construct($message);
        $this->reason = RefUpdateReason::fromStatus($status);
    }
}
