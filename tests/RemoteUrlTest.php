<?php

declare(strict_types=1);

namespace Igzard\CodeStorage\Tests;

use Igzard\CodeStorage\Enum\Op;
use Igzard\CodeStorage\Enum\Permission;
use Igzard\CodeStorage\Model\RefPolicy;
use Igzard\CodeStorage\Tests\Support\MockHttpClient;
use Igzard\CodeStorage\Tests\Support\TestCase;

final class RemoteUrlTest extends TestCase
{
    public function test_it_builds_an_authenticated_remote_url(): void
    {
        $repo = $this->client(new MockHttpClient)->repo('repo-1');
        $url = $repo->remoteUrl();

        self::assertStringStartsWith('https://t:', $url);
        self::assertStringEndsWith('@acme.code.storage/repo-1.git', $url);

        $claims = $this->claimsFromUrl($url);
        self::assertSame(['git:write', 'git:read'], $claims['scopes']);
        self::assertSame(365 * 24 * 3600, $claims['exp'] - $claims['iat']);
        self::assertArrayNotHasKey('refs', $claims);
        self::assertArrayNotHasKey('ops', $claims);
    }

    public function test_it_builds_ephemeral_and_import_remote_urls(): void
    {
        $repo = $this->client(new MockHttpClient)->repo('repo-1');

        self::assertStringEndsWith('@acme.code.storage/repo-1+ephemeral.git', $repo->ephemeralRemoteUrl());
        self::assertStringEndsWith('@acme.code.storage/repo-1+import.git', $repo->importRemoteUrl());
    }

    public function test_it_honours_explicit_permissions_and_ttl(): void
    {
        $repo = $this->client(new MockHttpClient)->repo('repo-1');
        $claims = $this->claimsFromUrl($repo->remoteUrl(permissions: [Permission::GitRead], ttl: 60));

        self::assertSame(['git:read'], $claims['scopes']);
        self::assertSame(60, $claims['exp'] - $claims['iat']);
    }

    public function test_it_falls_back_to_the_configured_default_ttl(): void
    {
        $repo = $this->client(new MockHttpClient, defaultTtl: 120)->repo('repo-1');
        $claims = $this->claimsFromUrl($repo->remoteUrl());

        self::assertSame(120, $claims['exp'] - $claims['iat']);
    }

    public function test_it_encodes_ref_policies_as_pattern_ops_tuples(): void
    {
        $repo = $this->client(new MockHttpClient)->repo('repo-1');
        $claims = $this->claimsFromUrl($repo->remoteUrl(refPolicies: [
            new RefPolicy('refs/heads/main', [Op::NoPush]),
            new RefPolicy('refs/heads/release/*'),
            new RefPolicy('*', [Op::NoForcePush, Op::VerifySig]),
        ]));

        self::assertSame([
            ['refs/heads/main', ['no-push']],
            ['refs/heads/release/*', []],
            ['*', ['no-force-push', 'verify-sig']],
        ], $claims['refs']);
    }

    public function test_it_encodes_deprecated_repo_wide_ops(): void
    {
        $repo = $this->client(new MockHttpClient)->repo('repo-1');
        $claims = $this->claimsFromUrl($repo->remoteUrl(ops: [Op::NoForcePush]));

        self::assertSame(['no-force-push'], $claims['ops']);
    }
}
