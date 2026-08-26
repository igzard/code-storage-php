<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Internal;

use Igzard\CodeStorage\Enum\RefUpdateReason;
use Igzard\CodeStorage\Exception\RefUpdateException;
use Igzard\CodeStorage\Model\CommitResult;
use Igzard\CodeStorage\Model\RefUpdate;
use Psr\Http\Message\ResponseInterface;

/**
 * Shared commit-pack / diff-commit acknowledgement handling.
 *
 * @internal
 */
final class CommitPack
{
    /** @throws RefUpdateException when the server refused the ref update */
    public static function result(array $ack): CommitResult
    {
        $result = Arr::arr($ack, 'result');
        $refUpdate = RefUpdate::fromArray($result);

        if (! Arr::bool($result, 'success')) {
            $status = Arr::str($result, 'status');
            $message = trim(Arr::str($result, 'message'));
            throw new RefUpdateException(
                $message !== '' ? $message : 'commit failed with status '.$status,
                $status,
                $refUpdate,
            );
        }

        $commit = Arr::arr($ack, 'commit');

        return new CommitResult(
            Arr::str($commit, 'commit_sha'),
            Arr::str($commit, 'tree_sha'),
            Arr::str($commit, 'target_branch'),
            Arr::int($commit, 'pack_bytes'),
            Arr::int($commit, 'blob_count'),
            $refUpdate,
        );
    }

    /** Turns a non-2xx commit-pack response into a RefUpdateException. */
    public static function error(ResponseInterface $response, string $fallbackMessage): RefUpdateException
    {
        $raw = (string) $response->getBody();
        $statusLabel = self::defaultStatusLabel($response->getStatusCode());
        $refUpdate = null;
        $message = '';

        $parsed = Json::decode($raw);
        if ($parsed !== null) {
            $result = Arr::arr($parsed, 'result');
            if (trim(Arr::str($result, 'status')) !== '') {
                $statusLabel = trim(Arr::str($result, 'status'));
            }
            if (Arr::str($result, 'message') !== '') {
                $message = trim(Arr::str($result, 'message'));
            }
            $refUpdate = RefUpdate::partial(
                Arr::str($result, 'branch'),
                Arr::str($result, 'old_sha'),
                Arr::str($result, 'new_sha'),
            );

            if ($message === '' && trim(Arr::str($parsed, 'error')) !== '') {
                $message = trim(Arr::str($parsed, 'error'));
            }
        }

        if ($message === '' && $raw !== '') {
            $message = trim($raw);
        }
        if ($message === '') {
            $message = $fallbackMessage;
        }

        return new RefUpdateException($message, $statusLabel, $refUpdate);
    }

    public static function defaultStatusLabel(int $statusCode): string
    {
        $reason = RefUpdateReason::fromStatus((string) $statusCode);

        return $reason === RefUpdateReason::Unknown ? RefUpdateReason::Failed->value : $reason->value;
    }
}
