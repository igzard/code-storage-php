<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Model;

use InvalidArgumentException;

/** Identifies a commit author, committer or note author. */
final class CommitSignature
{
    public readonly string $name;

    public readonly string $email;

    public function __construct(string $name, string $email)
    {
        $this->name = trim($name);
        $this->email = trim($email);
    }

    /**
     * @internal
     *
     * @return array{name: string, email: string}
     */
    public function toPayload(string $context): array
    {
        if ($this->name === '' || $this->email === '') {
            throw new InvalidArgumentException($context.' name and email are required when provided');
        }

        return ['name' => $this->name, 'email' => $this->email];
    }
}
