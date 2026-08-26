<?php

declare(strict_types=1);

namespace Igzard\CodeStorage;

use Igzard\CodeStorage\Internal\Arr;
use Igzard\CodeStorage\Internal\Json;
use Igzard\CodeStorage\Internal\Time;
use Igzard\CodeStorage\Model\ParsedSignature;
use Igzard\CodeStorage\Model\WebhookPushEvent;
use Igzard\CodeStorage\Model\WebhookRepository;
use Igzard\CodeStorage\Model\WebhookUnknownEvent;
use Igzard\CodeStorage\Model\WebhookValidation;

/** Webhook signature validation and payload parsing. */
final class Webhook
{
    public const DEFAULT_MAX_AGE_SECONDS = 300;

    /** Parses the X-Pierre-Signature header ("t=<unix>,sha256=<hex>"). */
    public static function parseSignatureHeader(string $header): ?ParsedSignature
    {
        $header = trim($header);
        if ($header === '') {
            return null;
        }

        $timestamp = '';
        $signature = '';
        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2) {
                continue;
            }
            if ($pair[0] === 't') {
                $timestamp = $pair[1];
            } elseif ($pair[0] === 'sha256') {
                $signature = $pair[1];
            }
        }

        if ($timestamp === '' || $signature === '') {
            return null;
        }

        return new ParsedSignature($timestamp, $signature);
    }

    /**
     * Validates the HMAC signature and its timestamp.
     *
     * @param  int|null  $maxAgeSeconds  Null or 0 uses the 300 second default; a negative value skips the check.
     */
    public static function validateSignature(
        string $payload,
        string $signatureHeader,
        string $secret,
        ?int $maxAgeSeconds = null,
    ): WebhookValidation {
        if (trim($secret) === '') {
            return WebhookValidation::invalid('empty secret is not allowed');
        }

        $parsed = self::parseSignatureHeader($signatureHeader);
        if ($parsed === null) {
            return WebhookValidation::invalid('invalid signature header format');
        }
        if (! preg_match('/^-?\d+$/', $parsed->timestamp)) {
            return WebhookValidation::invalid('invalid timestamp in signature');
        }

        $timestamp = (int) $parsed->timestamp;
        $maxAge = ($maxAgeSeconds === null || $maxAgeSeconds === 0) ? self::DEFAULT_MAX_AGE_SECONDS : $maxAgeSeconds;
        if ($maxAge > 0) {
            $age = time() - $timestamp;
            if ($age > $maxAge) {
                return WebhookValidation::invalid("webhook timestamp too old ({$age} seconds)", $timestamp);
            }
            if ($age < -60) {
                return WebhookValidation::invalid('webhook timestamp is in the future', $timestamp);
            }
        }

        $expected = hash_hmac('sha256', $parsed->timestamp.'.'.$payload, $secret, true);
        $provided = self::hexDecode($parsed->signature);
        if ($provided === null || ! hash_equals($expected, $provided)) {
            return WebhookValidation::invalid('invalid signature', $timestamp);
        }

        return new WebhookValidation(true, '', $timestamp);
    }

    /**
     * Validates the signature and parses the payload.
     *
     * @param  array<string, string|list<string>>  $headers  Request headers; names are matched case-insensitively.
     */
    public static function validate(
        string $payload,
        array $headers,
        string $secret,
        ?int $maxAgeSeconds = null,
    ): WebhookValidation {
        $signatureHeader = self::header($headers, 'x-pierre-signature');
        if ($signatureHeader === '') {
            return WebhookValidation::invalid('missing or invalid X-Pierre-Signature header');
        }

        $eventType = self::header($headers, 'x-pierre-event');
        if ($eventType === '') {
            return WebhookValidation::invalid('missing or invalid X-Pierre-Event header');
        }

        $validation = self::validateSignature($payload, $signatureHeader, $secret, $maxAgeSeconds);
        if (! $validation->valid) {
            return $validation;
        }

        $decoded = Json::decode($payload);
        if ($decoded === null) {
            return $validation->withFailure('invalid JSON payload');
        }

        if ($eventType !== 'push') {
            return $validation->withPayload($eventType, new WebhookUnknownEvent($eventType, $payload));
        }

        $repository = Arr::arr($decoded, 'repository');
        $event = new WebhookPushEvent(
            new WebhookRepository(Arr::str($repository, 'id'), Arr::str($repository, 'url')),
            Arr::str($decoded, 'ref'),
            Arr::str($decoded, 'before'),
            Arr::str($decoded, 'after'),
            Arr::str($decoded, 'customer_id'),
            Time::parse(Arr::str($decoded, 'pushed_at')),
            Arr::str($decoded, 'pushed_at'),
        );

        if (
            $event->repository->id === '' || $event->repository->url === '' || $event->ref === ''
            || $event->before === '' || $event->after === '' || $event->customerId === '' || $event->rawPushedAt === ''
        ) {
            return $validation->withFailure('invalid push payload');
        }

        return $validation->withPayload($eventType, $event);
    }

    /** @param array<string, string|list<string>> $headers */
    private static function header(array $headers, string $name): string
    {
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) !== $name) {
                continue;
            }
            $value = is_array($value) ? ($value[0] ?? '') : $value;

            return trim((string) $value);
        }

        return '';
    }

    private static function hexDecode(string $value): ?string
    {
        if ($value === '' || strlen($value) % 2 !== 0 || preg_match('/^[0-9a-fA-F]+$/', $value) !== 1) {
            return null;
        }

        $decoded = hex2bin($value);

        return $decoded === false ? null : $decoded;
    }
}
