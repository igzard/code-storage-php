<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Tests;

use Igzard\CodeStorage\Model\WebhookPushEvent;
use Igzard\CodeStorage\Model\WebhookUnknownEvent;
use Igzard\CodeStorage\Webhook;
use PHPUnit\Framework\TestCase;

final class WebhookTest extends TestCase
{
    private const SECRET = 'whsec_test';

    private static function payload(array $overrides = []): string
    {
        return json_encode(array_replace([
            'repository' => ['id' => 'repo-1', 'url' => 'https://acme.code.storage/repo-1.git'],
            'ref' => 'refs/heads/main',
            'before' => 'old-sha',
            'after' => 'new-sha',
            'customer_id' => 'cus_1',
            'pushed_at' => '2024-06-15T12:00:00Z',
        ], $overrides), JSON_THROW_ON_ERROR);
    }

    private static function signature(string $payload, ?int $timestamp = null): string
    {
        $timestamp ??= time();
        $mac = hash_hmac('sha256', $timestamp.'.'.$payload, self::SECRET);

        return "t={$timestamp},sha256={$mac}";
    }

    /** @return array<string, string> */
    private static function headers(string $payload, string $event = 'push', ?int $timestamp = null): array
    {
        return [
            'X-Pierre-Signature' => self::signature($payload, $timestamp),
            'X-Pierre-Event' => $event,
        ];
    }

    public function test_it_parses_a_signature_header(): void
    {
        $parsed = Webhook::parseSignatureHeader(' t=1718452800, sha256=deadbeef ');

        self::assertNotNull($parsed);
        self::assertSame('1718452800', $parsed->timestamp);
        self::assertSame('deadbeef', $parsed->signature);
    }

    public function test_it_rejects_malformed_signature_headers(): void
    {
        self::assertNull(Webhook::parseSignatureHeader(''));
        self::assertNull(Webhook::parseSignatureHeader('t=1718452800'));
        self::assertNull(Webhook::parseSignatureHeader('sha256=deadbeef'));
        self::assertNull(Webhook::parseSignatureHeader('garbage'));
    }

    public function test_it_validates_a_push_event(): void
    {
        $payload = self::payload();
        $validation = Webhook::validate($payload, self::headers($payload), self::SECRET);

        self::assertTrue($validation->valid);
        self::assertSame('push', $validation->eventType);
        self::assertInstanceOf(WebhookPushEvent::class, $validation->payload);
        self::assertSame('repo-1', $validation->payload->repository->id);
        self::assertSame('refs/heads/main', $validation->payload->ref);
        self::assertSame('cus_1', $validation->payload->customerId);
        self::assertSame('2024-06-15T12:00:00+00:00', $validation->payload->pushedAt?->format('c'));
    }

    public function test_header_lookup_is_case_insensitive(): void
    {
        $payload = self::payload();
        $validation = Webhook::validate($payload, [
            'x-pierre-signature' => self::signature($payload),
            'x-pierre-event' => 'push',
        ], self::SECRET);

        self::assertTrue($validation->valid);
    }

    public function test_it_accepts_header_lists(): void
    {
        $payload = self::payload();
        $validation = Webhook::validate($payload, [
            'X-Pierre-Signature' => [self::signature($payload)],
            'X-Pierre-Event' => ['push'],
        ], self::SECRET);

        self::assertTrue($validation->valid);
    }

    public function test_unknown_events_pass_through_raw(): void
    {
        $payload = '{"anything":true}';
        $validation = Webhook::validate($payload, self::headers($payload, 'repo.deleted'), self::SECRET);

        self::assertTrue($validation->valid);
        self::assertInstanceOf(WebhookUnknownEvent::class, $validation->payload);
        self::assertSame('repo.deleted', $validation->payload->type);
        self::assertSame($payload, $validation->payload->raw);
    }

    public function test_it_rejects_a_tampered_payload(): void
    {
        $payload = self::payload();
        $headers = self::headers($payload);
        $validation = Webhook::validate(self::payload(['ref' => 'refs/heads/evil']), $headers, self::SECRET);

        self::assertFalse($validation->valid);
        self::assertSame('invalid signature', $validation->error);
    }

    public function test_it_rejects_a_non_hex_signature(): void
    {
        $payload = self::payload();
        $validation = Webhook::validate($payload, [
            'X-Pierre-Signature' => 't='.time().',sha256=not-hex',
            'X-Pierre-Event' => 'push',
        ], self::SECRET);

        self::assertFalse($validation->valid);
        self::assertSame('invalid signature', $validation->error);
    }

    public function test_it_rejects_stale_timestamps(): void
    {
        $payload = self::payload();
        $validation = Webhook::validate($payload, self::headers($payload, timestamp: time() - 600), self::SECRET);

        self::assertFalse($validation->valid);
        self::assertStringContainsString('webhook timestamp too old', $validation->error);
    }

    public function test_it_rejects_future_timestamps(): void
    {
        $payload = self::payload();
        $validation = Webhook::validate($payload, self::headers($payload, timestamp: time() + 600), self::SECRET);

        self::assertFalse($validation->valid);
        self::assertSame('webhook timestamp is in the future', $validation->error);
    }

    public function test_a_negative_max_age_skips_the_freshness_check(): void
    {
        $payload = self::payload();
        $validation = Webhook::validate($payload, self::headers($payload, timestamp: 1), self::SECRET, -1);

        self::assertTrue($validation->valid);
    }

    public function test_it_rejects_an_empty_secret(): void
    {
        $payload = self::payload();
        $validation = Webhook::validate($payload, self::headers($payload), '  ');

        self::assertFalse($validation->valid);
        self::assertSame('empty secret is not allowed', $validation->error);
    }

    public function test_it_requires_both_headers(): void
    {
        $payload = self::payload();

        $missingSignature = Webhook::validate($payload, ['X-Pierre-Event' => 'push'], self::SECRET);
        self::assertSame('missing or invalid X-Pierre-Signature header', $missingSignature->error);

        $missingEvent = Webhook::validate($payload, ['X-Pierre-Signature' => self::signature($payload)], self::SECRET);
        self::assertSame('missing or invalid X-Pierre-Event header', $missingEvent->error);
    }

    public function test_it_rejects_invalid_json(): void
    {
        $payload = 'not-json';
        $validation = Webhook::validate($payload, self::headers($payload), self::SECRET);

        self::assertFalse($validation->valid);
        self::assertSame('invalid JSON payload', $validation->error);
    }

    public function test_it_rejects_an_incomplete_push_payload(): void
    {
        $payload = self::payload(['customer_id' => '']);
        $validation = Webhook::validate($payload, self::headers($payload), self::SECRET);

        self::assertFalse($validation->valid);
        self::assertSame('invalid push payload', $validation->error);
    }

    public function test_signature_only_validation(): void
    {
        $payload = self::payload();
        $timestamp = time();

        $validation = Webhook::validateSignature($payload, self::signature($payload, $timestamp), self::SECRET);

        self::assertTrue($validation->valid);
        self::assertSame($timestamp, $validation->timestamp);
        self::assertNull($validation->payload);
    }

    public function test_it_rejects_a_non_numeric_timestamp(): void
    {
        $validation = Webhook::validateSignature('{}', 't=abc,sha256=deadbeef', self::SECRET);

        self::assertFalse($validation->valid);
        self::assertSame('invalid timestamp in signature', $validation->error);
    }
}
