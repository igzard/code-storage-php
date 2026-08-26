<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Tests\Support;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Igzard\CodeStorage\Client;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Throwaway P-256 key, shared with the Go SDK test suite. */
    public const TEST_KEY = "-----BEGIN PRIVATE KEY-----\n"
        ."MIGHAgEAMBMGByqGSM49AgEGCCqGSM49AwEHBG0wawIBAQQgy3DPdzzsP6tOOvmo\n"
        ."rjbx6L7mpFmKKL2hNWNW3urkN8ehRANCAAQ7/DPhGH3kaWl0YEIO+W9WmhyCclDG\n"
        ."yTh6suablSura7ZDG8hpm3oNsq/ykC3Scfsw6ZTuuVuLlXKV/be/Xr0d\n"
        ."-----END PRIVATE KEY-----\n";

    protected function client(MockHttpClient $http, ?string $token = null, ?int $defaultTtl = null): Client
    {
        $factory = new Psr17Factory;

        return new Client(
            name: 'acme',
            key: $token === null ? self::TEST_KEY : null,
            token: $token,
            defaultTtl: $defaultTtl,
            httpClient: $http,
            requestFactory: $factory,
            streamFactory: $factory,
        );
    }

    /** @return array<string, mixed> Verified JWT claims. */
    protected function claims(string $jwt): array
    {
        $details = openssl_pkey_get_details(openssl_pkey_get_private(self::TEST_KEY));

        return (array) JWT::decode($jwt, new Key($details['key'], 'ES256'));
    }

    /** @return array<string, mixed> Verified claims of the JWT embedded in a remote URL. */
    protected function claimsFromUrl(string $url): array
    {
        $password = parse_url($url, PHP_URL_PASS);
        self::assertIsString($password);
        self::assertNotSame('', $password);

        return $this->claims($password);
    }

    protected function bearer(MockHttpClient $http): string
    {
        return substr($http->lastRequest()->getHeaderLine('Authorization'), strlen('Bearer '));
    }

    /** @return list<array<string, mixed>> Decoded NDJSON lines of the last request body. */
    protected function ndjson(MockHttpClient $http): array
    {
        $lines = [];
        foreach (explode("\n", trim($http->lastBody())) as $line) {
            if ($line !== '') {
                $lines[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            }
        }

        return $lines;
    }
}
